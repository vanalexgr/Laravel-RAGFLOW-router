"""
title: Vascular MCP Adapter
author: open-webui
version: 1.0.0
"""
import html
import httpx
import asyncio
import time
import re
from pydantic import BaseModel, Field
from typing import Literal, Optional, Callable, Awaitable

GuidelineKey = Literal[
    'aortic_arch',
    'descending_thoracic_aorta',
    'abdominal_aortic_aneurysm',
    'mesenteric_renal',
    'asymptomatic_pad',
    'clti',
    'acute_limb_ischaemia',
    'carotid_vertebral',
    'venous_thrombosis',
    'chronic_venous_disease',
    'antithrombotic_therapy',
    'vascular_trauma',
    'vascular_graft_infections',
    'vascular_access',
]

GUIDELINE_NAMES = {
    'aortic_arch':               'Aortic Arch',
    'descending_thoracic_aorta': 'Thoracic Aorta',
    'abdominal_aortic_aneurysm': 'AAA',
    'mesenteric_renal':          'Mesenteric/Renal',
    'asymptomatic_pad':          'Asymptomatic PAD',
    'clti':                      'CLTI',
    'acute_limb_ischaemia':      'ALI',
    'carotid_vertebral':         'Carotid/Vertebral',
    'venous_thrombosis':         'Venous Thrombosis',
    'chronic_venous_disease':    'CVD',
    'antithrombotic_therapy':    'Antithrombotics',
    'vascular_trauma':           'Vascular Trauma',
    'vascular_graft_infections': 'Graft Infections',
    'vascular_access':           'Vascular Access',
}


class Tools:
    LLM_ASSET_MAX_ITEMS = 3

    class Valves(BaseModel):
        VASCULAR_API_BASE_URL: str = Field(
            default='https://your-domain.com',
            description='Base URL for Vascular Expert API',
        )
        VASCULAR_API_KEY: str = Field(
            default='your-api-key',
            description='API Key for authentication',
        )
        EMIT_STATUS_AS_MESSAGES: bool = Field(
            default=True,
            description='Emit retrieval progress as normal assistant messages (always visible, not collapsible).',
        )
        EMIT_STATUS_EVENTS: bool = Field(
            default=False,
            description='Also emit OpenWebUI status events (can appear in collapsible status UI).',
        )

    def __init__(self):
        self.valves = self.Valves()
        self._last_status_text = ''
        self._last_status_ts = 0.0

    # ------------------------------------------------------------------ #
    # Helpers (verbatim from vascular_expert.py)                          #
    # ------------------------------------------------------------------ #

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
            "=== FIGURES / TABLES (MANDATORY VERBATIM OUTPUT) ===",
            "In the final answer, include a section titled exactly: 🖼️ Figures / Tables",
            "Copy EVERY markdown image line below exactly as written.",
            "Do not modify URLs, do not remove items, and do not add [n] citations to image lines.",
        ]
        count = 0

        for asset in assets:
            if count >= self.LLM_ASSET_MAX_ITEMS:
                break

            if not isinstance(asset, dict):
                continue

            thumb_url = str(asset.get("thumbnail_url") or "").strip()
            full_url = str(asset.get("url") or "").strip()
            if not full_url.startswith(("http://", "https://")) and not thumb_url.startswith(("http://", "https://")):
                continue
            if not thumb_url:
                thumb_url = full_url
            if not full_url:
                full_url = thumb_url

            label = self._clean_narrative_text(str(asset.get("label", "")).strip()) or f"Figure {count + 1}"
            caption = self._clean_narrative_text(str(asset.get("caption", "")).strip())
            guideline_key = self._clean_narrative_text(str(asset.get("guideline_key", "")).strip())

            alt_text = caption or label
            alt_text = self._truncate_for_llm(alt_text, 140).replace("[", "(").replace("]", ")")

            headline = f"{label}"
            if guideline_key:
                headline += f" ({guideline_key})"
            if caption:
                headline += f": {self._truncate_for_llm(caption, 180)}"

            lines.append(headline)
            # Use linked thumbnail so click/tap opens full-size image consistently across clients.
            if full_url and full_url != thumb_url:
                lines.append(f"[![{alt_text}]({thumb_url})]({full_url})")
                lines.append(f"[Full-size]({full_url})")
            else:
                lines.append(f"![{alt_text}]({thumb_url})")
            lines.append("")
            count += 1

        if count == 0:
            return ""

        lines.insert(1, f"ASSET_COUNT_REQUIRED: {count}")
        return "\n".join(lines) + "\n\n"

    async def _emit_status(self, emitter, description: str, done: bool = False):
        """Emit a status update to OpenWebUI UI (replaces pulsating dot)."""
        if emitter:
            try:
                emit_messages = bool(getattr(self.valves, "EMIT_STATUS_AS_MESSAGES", True))
                emit_status = bool(getattr(self.valves, "EMIT_STATUS_EVENTS", False))

                # Message-mode: keep progress always visible and avoid rapid duplicate spam.
                if emit_messages:
                    now = time.monotonic()
                    same_text = description == self._last_status_text
                    if done or (not same_text) or (now - self._last_status_ts >= 8.0):
                        await emitter(
                            {
                                "type": "message",
                                "data": {"content": f"{description}\n"},
                            }
                        )
                        self._last_status_text = description
                        self._last_status_ts = now

                # Optional status-mode (collapsed UI component in many clients).
                if emit_status:
                    await emitter(
                        {
                            "type": "status",
                            "data": {"description": description, "done": done, "hidden": False},
                        }
                    )
            except Exception as e:
                print(f"[Adapter] Status emit error: {e}")

    # ------------------------------------------------------------------ #
    # Main tool                                                            #
    # ------------------------------------------------------------------ #

    async def consult_vascular_guidelines(
        self,
        question: str,
        guideline_1: GuidelineKey,
        guideline_2: Optional[GuidelineKey] = None,
        guideline_3: Optional[GuidelineKey] = None,
        __user__: dict = {},
        __messages__: list = [],
        __event_emitter__: Callable[[dict], Awaitable[None]] = None,
    ) -> str:
        """
        Consult ESVS Vascular Guidelines.
        Select 1-3 guidelines based on the clinical question.
        Call this tool for any vascular surgery clinical or guideline question.
        Select guidelines matching the anatomical territory and acuity.
        Add antithrombotic_therapy ONLY when the question specifically concerns
        anticoagulation or antithrombotic decisions.

        GUIDELINE REFERENCE:
        - aortic_arch: Arch aneurysm, Zone 0-2, FET, hybrid arch
        - descending_thoracic_aorta: Type B dissection, TEVAR, thoracic aneurysm, mural thrombus
        - abdominal_aortic_aneurysm: AAA, EVAR, rupture, endoleaks, iliac aneurysm
        - mesenteric_renal: Mesenteric ischemia, renal artery stenosis
        - asymptomatic_pad: Claudication, PAD screening, exercise therapy
        - clti: Rest pain, tissue loss, gangrene, limb salvage
        - acute_limb_ischaemia: ALI, sudden limb pain, 6Ps, embolism
        - carotid_vertebral: Stroke, TIA, carotid stenosis, CEA, CAS
        - venous_thrombosis: DVT, PE, VTE, anticoagulation
        - chronic_venous_disease: Varicose veins, venous ulcers, CEAP
        - antithrombotic_therapy: Aspirin, DOACs, DAPT, bleeding risk, bridging
        - vascular_trauma: Penetrating/blunt injury, REBOA
        - vascular_graft_infections: Graft infection, aorto-oesophageal fistula
        - vascular_access: Dialysis AVF, steal syndrome

        :param question: The clinical question
        :param guideline_1: Primary guideline (required)
        :param guideline_2: Secondary guideline (optional)
        :param guideline_3: Tertiary guideline (optional)
        :return: Evidence-based answer with structured LLM context
        """
        # ---- 9.1 Setup and HTTP call ---------------------------------- #
        emitter = __event_emitter__
        guidelines = [g for g in [guideline_1, guideline_2, guideline_3] if g]
        gdisplay = ', '.join(GUIDELINE_NAMES.get(g, g) for g in guidelines)
        await self._emit_status(emitter, f'Selecting guidelines: {gdisplay}')

        url = f'{self.valves.VASCULAR_API_BASE_URL}/api/v1/vascular-consult'
        headers = {
            'Authorization': f'Bearer {self.valves.VASCULAR_API_KEY}',
            'Content-Type': 'application/json',
        }
        payload = {
            'question': question,
            'guidelines': guidelines,
            # No history — adapter is stateless; LLM manages conversation context.
        }

        try:
            start = time.time()
            await self._emit_status(emitter, '🔍 Sending query to backend router...')
            async with httpx.AsyncClient(timeout=120.0) as client:
                task = asyncio.create_task(client.post(url, json=payload, headers=headers))
                while not task.done():
                    elapsed = int(time.time() - start)
                    if elapsed < 5:
                        msg = '📚 Searching selected guideline set...'
                    elif elapsed < 15:
                        msg = f'🔎 Retrieving evidence chunks... ({elapsed}s)'
                    elif elapsed < 35:
                        msg = f'📊 Processing results... ({elapsed}s)'
                    else:
                        msg = f'⏳ Finalizing... ({elapsed}s)'
                    await self._emit_status(emitter, msg)
                    try:
                        await asyncio.wait_for(asyncio.shield(task), timeout=2.0)
                    except asyncio.TimeoutError:
                        continue
                    break
                response = await task
                response.raise_for_status()
                data = response.json()

            elapsed = int(time.time() - start)
            await self._emit_status(emitter, f'✅ Retrieved in {elapsed}s — parsing results...')

            # ---- 9.2 Chunk assignment ---------------------------------- #
            # Use pre-orchestrated tiers from ChunkSelectionService.
            # No local ranking, diversification, or cap logic needed.
            llm_cit = data.get('llm_citation_chunks', [])
            llm_nar = data.get('llm_narrative_chunks', [])
            ui_cit  = data.get('ui_citation_chunks', [])
            ui_nar  = data.get('ui_narrative_chunks', [])
            assets  = data.get('assets', [])
            q_norm  = data.get('query_normalization', {})

            # UI-only extras: present in UI tier but not in LLM tier
            llm_cit_ids = {c.get('recommendation_id') or c.get('content', '')[:40] for c in llm_cit}
            llm_nar_ids = {c.get('content', '')[:40] for c in llm_nar}
            extra_ui_cit = [c for c in ui_cit if (c.get('recommendation_id') or c.get('content', '')[:40]) not in llm_cit_ids]
            extra_ui_nar = [c for c in ui_nar if c.get('content', '')[:40] not in llm_nar_ids]

            llm_total     = len(llm_cit) + len(llm_nar)
            ui_total      = len(ui_cit) + len(ui_nar)
            backend_total = len(data.get('citation_chunks', [])) + len(data.get('narrative_chunks', []))

            # ---- 9.3 Emit citation events (four passes) ---------------- #
            # Emit in this exact order so inline [n] numbering in the answer
            # maps to popup sources correctly.
            if emitter:
                chunk_number = 1

                # Pass 1: LLM-visible recommendation chunks
                for chunk in llm_cit:
                    text   = chunk.get('text', chunk.get('content', ''))
                    rec_id = chunk.get('recommendation_id', '')
                    cls    = chunk.get('class', '')
                    level  = chunk.get('level', '')
                    gl     = chunk.get('guideline', 'ESVS')
                    title  = (
                        f'Recommendation {rec_id} from {gl} — Class {cls}, Level {level}'
                        if rec_id else f'Recommendation from {gl}'
                    )
                    popup = self._format_rec_popup(text, title)
                    try:
                        await emitter({'type': 'citation', 'data': {
                            'document':  [popup],
                            'metadata':  [{'source': title, 'kind': 'recommendation', 'guideline': gl, 'recommendation_id': rec_id}],
                            'source':    {'id': str(chunk_number), 'name': title},
                        }})
                    except Exception as e:
                        print(f'[Adapter] Error emitting rec citation: {e}')
                    chunk_number += 1

                # Pass 2: LLM-visible narrative chunks
                nar_i = 1
                for chunk in llm_nar:
                    content = chunk.get('content', '')
                    src_gl  = chunk.get('source_guideline', 'ESVS')
                    excerpt = content[:6000]
                    title   = f'{src_gl} — Narrative {nar_i}: {excerpt[:80].strip()}'
                    try:
                        await emitter({'type': 'citation', 'data': {
                            'document':  [excerpt],
                            'metadata':  [{'source': title, 'kind': 'narrative', 'guideline': src_gl, 'chunk': nar_i}],
                            'source':    {'id': str(chunk_number), 'name': title},
                        }})
                    except Exception as e:
                        print(f'[Adapter] Error emitting narrative citation: {e}')
                    chunk_number += 1
                    nar_i += 1

                # Pass 3: Extra UI-only recommendation chunks
                for chunk in extra_ui_cit:
                    text   = chunk.get('text', chunk.get('content', ''))
                    rec_id = chunk.get('recommendation_id', '')
                    cls    = chunk.get('class', '')
                    level  = chunk.get('level', '')
                    gl     = chunk.get('guideline', 'ESVS')
                    title  = (
                        f'Recommendation {rec_id} from {gl} — Class {cls}, Level {level}'
                        if rec_id else f'Recommendation from {gl}'
                    )
                    popup = self._format_rec_popup(text, title)
                    try:
                        await emitter({'type': 'citation', 'data': {
                            'document':  [popup],
                            'metadata':  [{'source': title, 'kind': 'recommendation', 'guideline': gl, 'recommendation_id': rec_id}],
                            'source':    {'id': str(chunk_number), 'name': title},
                        }})
                    except Exception as e:
                        print(f'[Adapter] Error emitting extra rec: {e}')
                    chunk_number += 1

                # Pass 4: Extra UI-only narrative chunks
                extra_nar_i = len(llm_nar) + 1
                for chunk in extra_ui_nar:
                    content = chunk.get('content', '')
                    src_gl  = chunk.get('source_guideline', 'ESVS')
                    excerpt = content[:6000]
                    title   = f'{src_gl} — Narrative {extra_nar_i}: {excerpt[:80].strip()}'
                    try:
                        await emitter({'type': 'citation', 'data': {
                            'document':  [excerpt],
                            'metadata':  [{'source': title, 'kind': 'narrative', 'guideline': src_gl, 'chunk': extra_nar_i}],
                            'source':    {'id': str(chunk_number), 'name': title},
                        }})
                    except Exception as e:
                        print(f'[Adapter] Error emitting extra narrative: {e}')
                    chunk_number += 1
                    extra_nar_i += 1

            # ---- 9.4 No evidence case ---------------------------------- #
            if llm_total == 0:
                await self._emit_status(emitter, 'No evidence retrieved', done=True)
                return (
                    'The provided ESVS guideline context does not explicitly address '
                    'this scenario.'
                )

            # ---- 9.5 Status summary ------------------------------------ #
            await self._emit_status(
                emitter,
                f'Retrieved {backend_total} chunks from {gdisplay}; '
                f'using {llm_total} for answer, exposing {ui_total} in Sources',
                done=True,
            )

            # ---- 9.6 Build LLM context string -------------------------- #
            llm_out = (
                f'Consultation successful. Using {llm_total} evidence sources '
                f'(from {backend_total} retrieved; {ui_total} in Sources).\n\n'
            )

            # Clinical frame
            if isinstance(q_norm, dict):
                frame = str(q_norm.get('clinical_frame') or '').strip()
                if frame:
                    llm_out += '=== CLINICAL FRAME (INTERPRETIVE / NON-GUIDELINE) ===\n'
                    llm_out += frame + '\n'
                    llm_out += (
                        'Guidance: You may include a brief interpretive framing note, '
                        'clearly labeled as non-guideline and without citations.\n\n'
                    )

            # Assets block — placed early so model consistently sees figures
            assets_block = self._format_assets_markdown(assets)
            if assets_block:
                llm_out += assets_block

            # Recommendations
            chunk_num = 1
            if llm_cit:
                llm_out += '=== RECOMMENDATIONS ===\n'
                for chunk in llm_cit:
                    text   = chunk.get('text', chunk.get('content', ''))[:1200]
                    rec_id = chunk.get('recommendation_id', 'Rec')
                    cls    = chunk.get('class', 'N/A')
                    lvl    = chunk.get('level', 'N/A')
                    gl     = chunk.get('guideline', 'ESVS')
                    llm_out += f'[{chunk_num}] Rec {rec_id} (Class {cls}, Level {lvl}) — {gl}\n'
                    llm_out += f'> {text}\n\n'
                    chunk_num += 1
            else:
                llm_out += (
                    '=== RECOMMENDATIONS ===\n'
                    'No recommendation chunks retrieved. Use narrative context only.\n\n'
                )

            # Narrative context
            if llm_nar:
                llm_out += '=== NARRATIVE CONTEXT ===\n'
                nar_i = 1
                for chunk in llm_nar:
                    content = chunk.get('content', '')[:1500]
                    src_gl  = chunk.get('source_guideline', 'ESVS')
                    llm_out += f'[{chunk_num}] {src_gl} — Narrative {nar_i}\n{content}\n\n'
                    chunk_num += 1
                    nar_i += 1

            # Citation rules
            llm_out += '=== CITATION RULES ===\n'
            llm_out += '1. Use inline citations [1],[2] after each fact.\n'
            llm_out += '2. Cite only sources you actually use.\n'
            llm_out += '3. Do NOT add a References section — UI shows Sources list.\n'
            llm_out += '4. Match [n] numbers exactly to the evidence blocks above.\n'
            llm_out += (
                '5. SCOPE FILTER: only cite recommendations that directly address '
                'this specific case.\n'
            )
            if assets_block:
                llm_out += (
                    '6. Include a section titled exactly: 🖼️ Figures / Tables '
                    'and copy ALL markdown image lines from the FIGURES block verbatim.\n'
                )

            # Strict template
            llm_out += '\n=== REQUIRED STRUCTURE (STRICT) ===\n'
            llm_out += 'Restrict every section to evidence directly relevant to this case.\n'
            llm_out += 'Assessment:\nImaging:\nIndication for intervention:\n'
            llm_out += 'Treatment options:\nFollow-up:\nEvidence used (Rec #, Class, Level):\n'

            return llm_out

        # ---- 9.7 Error handlers --------------------------------------- #
        except httpx.TimeoutException:
            await self._emit_status(emitter, 'Request timed out', done=True)
            return 'Error: Request timed out after 120 seconds'
        except httpx.HTTPStatusError as e:
            await self._emit_status(emitter, f'API error: {e.response.status_code}', done=True)
            return f'Error: HTTP {e.response.status_code}'
        except Exception as e:
            await self._emit_status(emitter, f'Error: {str(e)[:50]}', done=True)
            return f'Error: {str(e)}'
