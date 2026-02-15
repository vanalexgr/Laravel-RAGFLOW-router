"""
title: Vascular Expert Tools
author: open-webui
author_url: https://github.com/open-webui
funding_url: https://github.com/open-webui
version: 2.1.0
"""

import httpx
import asyncio
from pydantic import BaseModel, Field
from typing import Literal, Optional, Callable, Awaitable
import re
import html


# Enum of all valid guideline keys
GuidelineKey = Literal[
    "aortic_arch",
    "descending_thoracic_aorta", 
    "abdominal_aortic_aneurysm",
    "mesenteric_renal",
    "asymptomatic_pad",
    "clti",
    "acute_limb_ischaemia",
    "carotid_vertebral",
    "venous_thrombosis",
    "chronic_venous_disease",
    "antithrombotic_therapy",
    "vascular_trauma",
    "vascular_graft_infections",
    "vascular_access"
]

# Human-readable guideline names
GUIDELINE_NAMES = {
    "aortic_arch": "Aortic Arch",
    "descending_thoracic_aorta": "Thoracic Aorta",
    "abdominal_aortic_aneurysm": "AAA",
    "mesenteric_renal": "Mesenteric/Renal",
    "asymptomatic_pad": "Asymptomatic PAD",
    "clti": "CLTI",
    "acute_limb_ischaemia": "ALI",
    "carotid_vertebral": "Carotid/Vertebral",
    "venous_thrombosis": "Venous Thrombosis",
    "chronic_venous_disease": "CVD",
    "antithrombotic_therapy": "Antithrombotics",
    "vascular_trauma": "Vascular Trauma",
    "vascular_graft_infections": "Graft Infections",
    "vascular_access": "Vascular Access"
}


class Tools:
    LLM_NARRATIVE_MAX_CHARS = 1500
    LLM_NARRATIVE_MAX_CHUNKS = 4
    LLM_REC_MAX_CHARS = 1200
    LLM_REC_MAX_CHUNKS = 6
    LLM_ASSET_MAX_ITEMS = 3

    def __init__(self):
        self.valves = self.Valves()

    def _truncate_for_llm(self, text: str, max_chars: int) -> str:
        if not text:
            return ""
        s = text.strip()
        if len(s) <= max_chars:
            return s
        return s[: max_chars - 20].rstrip() + "\n\n[...truncated...]"

    def _html_table_to_text(self, html_text: str) -> str:
        """Convert simple HTML tables to a readable pipe-delimited text."""
        if not html_text or "<table" not in html_text.lower():
            return ""
        rows = re.findall(r"<tr[^>]*>(.*?)</tr>", html_text, flags=re.S | re.I)
        out_rows = []
        for row in rows:
            cells = re.findall(r"<t[hd][^>]*>(.*?)</t[hd]>", row, flags=re.S | re.I)
            cleaned = []
            for c in cells:
                c = re.sub(r"<[^>]+>", "", c or "")
                c = html.unescape(c)
                c = " ".join(c.split())
                if c:
                    cleaned.append(c)
            if cleaned:
                out_rows.append(" | ".join(cleaned))
        return "\n".join(out_rows).strip()

    def _strip_markdown(self, text: str) -> str:
        """Best-effort markdown -> plain text (headings, emphasis, links, code)."""
        if not text:
            return ""
        s = text
        s = re.sub(r"\[([^\]]+)\]\([^)]+\)", r"\1", s)  # links
        s = re.sub(r"`([^`]+)`", r"\1", s)  # inline code
        s = re.sub(r"(?m)^\s{0,3}#{1,6}\s+", "", s)  # headings
        s = re.sub(r"(?m)^\s{0,3}>\s?", "", s)  # blockquotes
        s = re.sub(r"\*\*([^*]+)\*\*", r"\1", s)  # bold
        s = re.sub(r"__([^_]+)__", r"\1", s)
        s = re.sub(r"(?<!\*)\*([^\s*][^*]*[^\s*])\*(?!\*)", r"\1", s)  # italics
        s = re.sub(r"(?<!_)_([^\s_][^_]*[^\s_])_(?!_)", r"\1", s)
        return s

    def _clean_narrative_text(self, text: str) -> str:
        """Strip HTML/markdown and flatten tables for narrative popups/LLM."""
        if not text:
            return ""
        s = text
        # Replace HTML tables with pipe-delimited text
        s = re.sub(
            r"<table[^>]*>.*?</table>",
            lambda m: self._html_table_to_text(m.group(0)),
            s,
            flags=re.S | re.I,
        )
        # Drop remaining HTML tags
        s = re.sub(r"<[^>]+>", "", s)
        s = html.unescape(s)
        # Strip markdown
        s = self._strip_markdown(s)
        # Normalize whitespace
        s = re.sub(r"\n{3,}", "\n\n", s)
        s = re.sub(r"[ \t]{2,}", " ", s)
        return s.strip()

    def _parse_semicolon_kv(self, s: str) -> dict:
        """
        Parse strings like:
          "rec_id:6.38; category_name:Peripheral; ...; rec_text_verbatim:Consider ..."
        into a dict. Best-effort, tolerant of missing keys.
        """
        out: dict = {}
        if not s or ":" not in s:
            return out
        for part in s.split(";"):
            part = part.strip()
            if not part or ":" not in part:
                continue
            k, v = part.split(":", 1)
            k = k.strip()
            v = v.strip()
            if k:
                out[k] = v
        return out

    def _format_rec_popup(self, raw: str, fallback_title: str) -> str:
        """
        Make recommendation citation popups readable. If raw is not parseable,
        return a lightly formatted fallback.
        """
        kv = self._parse_semicolon_kv(raw)
        if not kv:
            return raw.strip() if raw else fallback_title

        rec_id = kv.get("rec_id") or kv.get("recommendation_id") or ""
        guideline_name = kv.get("guideline_name") or kv.get("guideline") or ""
        guideline_year = kv.get("guideline_year") or kv.get("year") or ""
        category_name = kv.get("category_name") or ""
        cls = kv.get("class") or ""
        level = kv.get("level") or ""
        authors = kv.get("evidence_first_authors") or kv.get("evidence_authors") or ""
        text = kv.get("rec_text_verbatim") or kv.get("text") or kv.get("content") or raw

        # Clean authors formatting: ["A", "B"] -> A; B
        authors_clean = authors.strip()
        if authors_clean.startswith("[") and authors_clean.endswith("]"):
            authors_clean = authors_clean[1:-1].strip()
        authors_clean = authors_clean.replace('"', "").replace("'", "")

        header = "Recommendation"
        if rec_id:
            header += f" {rec_id}"
        if guideline_name:
            header += f" — {guideline_name}"
        if guideline_year:
            header += f" ({guideline_year})"

        lines = [header]
        if category_name:
            lines.append(f"Category: {category_name}")
        if cls or level:
            lines.append(f"Strength: Class {cls or 'N/A'}; Level {level or 'N/A'}")
        if authors_clean:
            lines.append(f"Evidence (first authors): {authors_clean}")
        if text:
            lines.append("")
            lines.append("Text (verbatim):")
            lines.append(text.strip())

        return "\n".join(lines).strip()

    def _format_assets_markdown(self, assets: list) -> str:
        """
        Build a compact markdown-image section for the LLM context.
        No network fetches, no base64 encoding, URLs only.
        """
        if not assets:
            return ""

        lines = [
            "=== FIGURES / TABLES (OPTIONAL VISUALS) ===",
            "Use at most 1-3 images if they directly improve the answer.",
            "Do not add [n] citations to image lines; those numbers are only for evidence chunks.",
        ]
        count = 0

        for asset in assets:
            if count >= self.LLM_ASSET_MAX_ITEMS:
                break

            if not isinstance(asset, dict):
                continue

            url = str(asset.get("thumbnail_url") or asset.get("url", "")).strip()
            if not url.startswith(("http://", "https://")):
                continue

            label = self._clean_narrative_text(str(asset.get("label", "")).strip()) or f"Figure {count + 1}"
            caption = self._clean_narrative_text(str(asset.get("caption", "")).strip())
            guideline_key = self._clean_narrative_text(str(asset.get("guideline_key", "")).strip())

            alt_text = caption or label
            alt_text = self._truncate_for_llm(alt_text, 140).replace("[", "(").replace("]", ")")

            headline = f"- {label}"
            if guideline_key:
                headline += f" ({guideline_key})"
            if caption:
                headline += f": {self._truncate_for_llm(caption, 180)}"

            lines.append(headline)
            lines.append(f"  ![{alt_text}]({url})")
            count += 1

        if count == 0:
            return ""

        return "\n".join(lines) + "\n\n"

    class Valves(BaseModel):
        VASCULAR_API_BASE_URL: str = Field(
            default="https://your-domain.com",
            description="Base URL for Vascular Expert API",
        )
        VASCULAR_API_KEY: str = Field(
            default="your-api-key",
            description="API Key for authentication",
        )

    async def _emit_status(self, emitter, description: str, done: bool = False):
        """Emit a status update to OpenWebUI UI (replaces pulsating dot)."""
        if emitter:
            try:
                await emitter(
                    {
                        "type": "status",
                        "data": {"description": description, "done": done},
                    }
                )
            except Exception as e:
                print(f"[VascularExpert] Status emit error: {e}")

    async def consult_vascular_guidelines(
        self, 
        question: str,
        guideline_1: GuidelineKey,
        guideline_2: Optional[GuidelineKey] = None,
        guideline_3: Optional[GuidelineKey] = None,
        standalone: bool = False,
        __user__: dict = {}, 
        __messages__: list = [],
        __event_emitter__: Callable[[dict], Awaitable[None]] = None
    ) -> str:
        """
        Consult ESVS Vascular Guidelines. Select 1-3 guidelines based on the clinical question.
        
        **CRITICAL**: You MUST call this tool in ALL of these scenarios:
        1. ANY vascular surgery question (direct or follow-up)
        2. When the user attaches a patient case/document and asks about ESVS compliance
        3. When comparing patient management against guidelines
        
        **DOCUMENT ATTACHMENT HANDLING**:
        If the user attaches a patient document (discharge summary, case report, etc.) and asks:
        - "Was this managed per ESVS guidelines?"
        - "Does this follow guidelines?"
        - "What does ESVS recommend for this case?"
        
        YOU MUST:
        1. Read the attached document to identify the condition (e.g., AAA, carotid stenosis)
        2. Call this tool with the appropriate guideline(s)
        3. Compare the patient's management against the retrieved ESVS content
        
        SELECTION RULES:
        1. Match anatomical territory first (aorta, limb, cerebral, venous)
        2. Consider acuity (acute vs chronic)
        3. Add companion guidelines if question spans domains
        
        GUIDELINE REFERENCE:
        - aortic_arch: Arch aneurysm, Zone 0-2, FET, hybrid arch
        - descending_thoracic_aorta: Type B dissection, TEVAR, thoracic aneurysm
        - abdominal_aortic_aneurysm: AAA, EVAR, rupture, endoleaks, iliac aneurysm
        - mesenteric_renal: Mesenteric ischemia (CMI/AMI), renal artery stenosis
        - asymptomatic_pad: Claudication, PAD screening, exercise therapy
        - clti: Rest pain, tissue loss, gangrene, limb salvage
        - acute_limb_ischaemia: ALI, sudden limb pain, 6Ps, embolism
        - carotid_vertebral: Stroke, TIA, carotid stenosis, CEA, CAS
        - venous_thrombosis: DVT, PE, VTE, anticoagulation
        - chronic_venous_disease: Varicose veins, venous ulcers, CEAP
        - antithrombotic_therapy: Aspirin, DOACs, DAPT, bleeding risk
        - vascular_trauma: Penetrating/blunt injury, REBOA
        - vascular_graft_infections: Graft/endograft infection, post-procedure fever
        - vascular_access: Dialysis AVF, steal syndrome

        **HISTORY CONTROL**:
        - If the user explicitly asks for a "standalone" answer or says "ignore previous context",
          set `standalone=true` to avoid using prior conversation history.
        
        :param question: The clinical question to answer
        :param guideline_1: Primary guideline (required)
        :param guideline_2: Secondary guideline (optional)
        :param guideline_3: Tertiary guideline (optional)
        :param standalone: If true, do not use prior conversation history
        :return: Evidence-based recommendations and citations
        """
        emitter = __event_emitter__

        def _short_label(text: str, max_len: int = 80) -> str:
            """Create a stable short label from a narrative chunk."""
            if not text:
                return "Narrative"
            # Prefer the first markdown heading if present.
            for line in text.splitlines():
                s = line.strip()
                if s.startswith("#"):
                    s = re.sub(r"^#+\s*", "", s).strip()
                    s = re.sub(r"<[^>]+>", "", s).strip()
                    if s:
                        return (s[: max_len - 3] + "...") if len(s) > max_len else s
            # Fallback: first non-empty line.
            for line in text.splitlines():
                s = re.sub(r"<[^>]+>", "", line)
                s = " ".join(s.strip().split())
                if s:
                    return (s[: max_len - 3] + "...") if len(s) > max_len else s
            return "Narrative"
        
        # Collect selected guidelines
        guidelines = [guideline_1]
        if guideline_2:
            guidelines.append(guideline_2)
        if guideline_3:
            guidelines.append(guideline_3)
        
        # Build human-readable guideline display
        guideline_display = ", ".join(GUIDELINE_NAMES.get(g, g) for g in guidelines)
        await self._emit_status(emitter, f"Selecting guidelines: {guideline_display}")
        
        # Extract conversation history for context fusion
        # Only include USER messages, not assistant responses (which are very long)
        history = []
        if not standalone and __messages__:
            for msg in __messages__[-6:]:  # Last 6 messages to get ~3 user turns
                role = msg.get("role", "")
                content = msg.get("content", "")
                if role == "user" and content and isinstance(content, str):
                    # Truncate very long user messages (attachments, etc.)
                    truncated = content[:500] if len(content) > 500 else content
                    history.append(truncated)
        
        await self._emit_status(emitter, f"Consulting {len(guidelines)} ESVS guideline(s)...")
        
        url = f"{self.valves.VASCULAR_API_BASE_URL}/api/v1/vascular-consult"
        headers = {
            "Authorization": f"Bearer {self.valves.VASCULAR_API_KEY}",
            "Content-Type": "application/json",
        }
        payload = {
            "question": question,
            "history": history,
            "guidelines": guidelines  # LLM-selected guidelines (enum-constrained)
        }

        try:
            import time
            start_time = time.time()
            
            await self._emit_status(emitter, f"🔍 Routing query to {len(guidelines)} guideline(s)...")
            await asyncio.sleep(0.1)  # Give UI time to update
            
            await self._emit_status(emitter, f"📚 Searching {guideline_display}...")
            
            async with httpx.AsyncClient(timeout=90.0) as client:
                # Start the request
                response_task = asyncio.create_task(
                    client.post(url, json=payload, headers=headers)
                )
                
                # Emit progress updates while waiting
                elapsed = 0
                while not response_task.done():
                    elapsed = int(time.time() - start_time)
                    
                    if elapsed < 5:
                        await self._emit_status(emitter, f"📚 Searching {guideline_display}...")
                    elif elapsed < 10:
                        await self._emit_status(emitter, f"🔎 Retrieving evidence chunks... ({elapsed}s)")
                    elif elapsed < 20:
                        await self._emit_status(emitter, f"📊 Processing multi-guideline results... ({elapsed}s)")
                    elif elapsed < 40:
                        await self._emit_status(emitter, f"⏳ Complex query - still processing... ({elapsed}s)")
                    else:
                        await self._emit_status(emitter, f"⏳ Almost there - finalizing results... ({elapsed}s)")
                    
                    # Check again in 2 seconds
                    try:
                        await asyncio.wait_for(asyncio.shield(response_task), timeout=2.0)
                    except asyncio.TimeoutError:
                        continue
                    break
                
                response = await response_task
                response.raise_for_status()
                
                # Parse JSON inside the client context
                data = response.json()
                
            elapsed = int(time.time() - start_time)
            await self._emit_status(emitter, f"✅ Retrieved in {elapsed}s - parsing results...")
            
            print(f"[VascularExpert] Response keys: {data.keys()}")
            
            # Extract chunks from response
            narrative_chunks = data.get("narrative_chunks", [])
            citation_chunks = data.get("citation_chunks", [])
            assets = data.get("assets", [])
            print(f"[VascularExpert] Chunks: narrative={len(narrative_chunks)}, citation={len(citation_chunks)}")

            # Only emit a small, stable subset so Sources list matches used citations
            selected_citation_chunks = citation_chunks[: self.LLM_REC_MAX_CHUNKS]
            selected_narrative_chunks = narrative_chunks[: self.LLM_NARRATIVE_MAX_CHUNKS]
            total_chunks = len(selected_narrative_chunks) + len(selected_citation_chunks)
            
            # EMIT INDIVIDUAL CITATIONS for each chunk
            # This enables per-chunk citation popups in OpenWebUI
            if emitter:
                emitted_count = 0
                chunk_number = 1
                
                # Emit citation chunks first (recommendations)
                for chunk in selected_citation_chunks:
                    
                    text = chunk.get("text", chunk.get("content", ""))
                    rec_id = chunk.get("recommendation_id", "")
                    cls = chunk.get("class", "")
                    level = chunk.get("level", "")
                    guideline = chunk.get("guideline", "ESVS")
                    
                    # Build citation title
                    if rec_id:
                        title = f"Recommendation {rec_id} from {guideline} - Class {cls}, Level {level}"
                    else:
                        title = f"Recommendation from {guideline}"

                    # Render a readable popup from the (often semicolon-delimited) row.
                    popup_text = self._format_rec_popup(text, title)
                    
                    try:
                        await emitter({
                            "type": "citation",
                            "data": {
                                "document": [popup_text],
                                "metadata": [{
                                    "source": title,
                                    "kind": "recommendation",
                                    "guideline": guideline,
                                    "recommendation_id": rec_id,
                                }],
                                "source": {"id": f"{chunk_number}", "name": title},
                            }
                        })
                        emitted_count += 1
                    except Exception as e:
                        print(f"Error emitting citation: {e}")
                    
                    chunk_number += 1
                
                # Emit narrative chunks (context)
                narrative_i = 1
                for chunk in selected_narrative_chunks:
                    content = chunk.get("content", "")
                    source_guideline = chunk.get("source_guideline", "ESVS")
                    
                    # Use a per-chunk title so OpenWebUI doesn't collapse all narrative chunks
                    # into a single reference for the guideline.
                    title = f"{source_guideline} - Narrative {narrative_i}: {_short_label(content)}"

                    # OpenWebUI's UI groups/labels citations based on metadata.source, not source.name.
                    # If metadata.source is identical across narrative chunks (e.g., just the guideline
                    # name), they collapse into one reference and inline clicks may not map to a unique
                    # popup. Emit a stable per-chunk source label and keep the popup document small.
                    excerpt = self._clean_narrative_text(content or "")
                    if len(excerpt) > 6000:
                        excerpt = excerpt[:6000] + "\n\n[...truncated...]"
                    
                    try:
                        await emitter({
                            "type": "citation",
                            "data": {
                                "document": [excerpt],
                                "metadata": [{
                                    "source": title,
                                    "kind": "narrative",
                                    "guideline": source_guideline,
                                    "chunk": narrative_i,
                                }],
                                "source": {"id": f"{chunk_number}", "name": title},
                            }
                        })
                        emitted_count += 1
                    except Exception as e:
                        print(f"Error emitting citation: {e}")
                        
                    chunk_number += 1
                    narrative_i += 1
            
            if total_chunks > 0:
                status_msg = f"Retrieved {total_chunks} evidence chunks from {guideline_display}"
                await self._emit_status(emitter, status_msg, done=True)
                
                # Build formatted text for the LLM
                # We format strict headers to match the System Prompt requirements
                llm_output = f"Consultation successful. Retrieved {total_chunks} evidence sources:\n\n"
                
                chunk_num = 1
                
                # SECTION 1: RECOMMENDATIONS (Must match System Prompt format)
                if selected_citation_chunks:
                    llm_output += "=== RECOMMENDATIONS ===\n"
                    for chunk in selected_citation_chunks:
                        text = chunk.get("text", chunk.get("content", ""))
                        text = self._truncate_for_llm(text, self.LLM_REC_MAX_CHARS)
                        rec_id = chunk.get("recommendation_id", "Rec")
                        cls = chunk.get("class", "N/A")
                        lvl = chunk.get("level", "N/A")
                        guideline = chunk.get("guideline", "ESVS")
                        
                        # INSTRUCTION TO LLM: Include [n] in the header so it is clickable in the final answer
                        header = f"[{chunk_num}] Rec {rec_id} (Class {cls}, Level {lvl}) — {guideline}"
                        llm_output += f"{header}\n> {text}\n\n"
                        chunk_num += 1

                # SECTION 2: NARRATIVE (Context)
                if selected_narrative_chunks:
                    llm_output += "=== NARRATIVE CONTEXT ===\n"
                    narrative_i = 1
                    for chunk in selected_narrative_chunks:
                        content = chunk.get("content", "")
                        content = self._clean_narrative_text(content)
                        content = self._truncate_for_llm(content, self.LLM_NARRATIVE_MAX_CHARS)
                        source = chunk.get("source_guideline", "ESVS")
                        
                        llm_output += f"[{chunk_num}] {source} - Narrative {narrative_i}: {_short_label(content)}\n"
                        llm_output += f"{content}\n\n"
                        chunk_num += 1
                        narrative_i += 1

                assets_block = self._format_assets_markdown(assets)
                if assets_block:
                    llm_output += assets_block
                
                llm_output += "=== CITATION RULES ===\n"
                llm_output += "1. Use simple numbered citations [1], [2], [3] inline after each fact.\n"
                llm_output += "2. Use ALL sources provided above at least once, so the Sources list matches cited evidence.\n"
                llm_output += "3. Do NOT add a separate References section; the UI already shows a Sources list.\n"
                llm_output += "4. Match the bracketed numbers [n] exactly to the evidence blocks above.\n"
                if assets_block:
                    llm_output += "5. If images help, include up to 3 markdown image lines from the FIGURES / TABLES section.\n"
                
                return llm_output
            else:
                await self._emit_status(
                    emitter, 
                    f"Consultation complete ({guideline_display})",
                    done=True
                )
                return "No specific guidelines found. Please answer based on general medical knowledge."
            
        except httpx.TimeoutException:
            await self._emit_status(emitter, "Request timed out", done=True)
            return "Error: Request timed out after 90 seconds"
        except httpx.HTTPStatusError as e:
            await self._emit_status(emitter, f"API error: {e.response.status_code}", done=True)
            return f"Error calling Vascular Expert API: HTTP {e.response.status_code}"
        except Exception as e:
            await self._emit_status(emitter, f"Error: {str(e)[:50]}", done=True)
            return f"Error calling Vascular Expert API: {str(e)}"
