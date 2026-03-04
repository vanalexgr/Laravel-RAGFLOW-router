"""
title: Vascular Expert Tools
author: open-webui
author_url: https://github.com/open-webui
funding_url: https://github.com/open-webui
version: 2.1.4
"""

import httpx
import asyncio
import os
from pydantic import BaseModel, Field
from typing import Literal, Optional, Callable, Awaitable
import re
import html


# Match non-A non-B variants (commas/slashes/hyphen variants).
NON_A_NON_B_PATTERN = re.compile(
    r"\bnon\s*[-\u2010-\u2015\u2212\u00ad]?\s*a\s*[,/-]?\s*non\s*[-\u2010-\u2015\u2212\u00ad]?\s*b\b",
    re.IGNORECASE,
)

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
    LLM_NARRATIVE_MAX_CHUNKS_MULTI = 8
    LLM_REC_MAX_CHARS = 1200
    LLM_REC_MAX_CHUNKS = 6
    LLM_REC_MAX_CHUNKS_MULTI = 8
    UI_NARRATIVE_MAX_CHUNKS = 8
    UI_NARRATIVE_MAX_CHUNKS_MULTI = 12
    UI_REC_MAX_CHUNKS = 12
    UI_REC_MAX_CHUNKS_MULTI = 18
    LLM_ASSET_MAX_ITEMS = 3
    STRICT_TEMPLATE = True
    ALLOW_PARTIAL_MATCH_ANSWERS = str(os.getenv("ALLOW_PARTIAL_EVIDENCE_ANSWERS", "true")).lower() in ("1", "true", "yes", "y")

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

    def _select_chunk_caps(self, guideline_count: int) -> dict:
        multi = guideline_count > 1
        return {
            "llm_rec": self.LLM_REC_MAX_CHUNKS_MULTI if multi else self.LLM_REC_MAX_CHUNKS,
            "llm_narr": self.LLM_NARRATIVE_MAX_CHUNKS_MULTI if multi else self.LLM_NARRATIVE_MAX_CHUNKS,
            "ui_rec": self.UI_REC_MAX_CHUNKS_MULTI if multi else self.UI_REC_MAX_CHUNKS,
            "ui_narr": self.UI_NARRATIVE_MAX_CHUNKS_MULTI if multi else self.UI_NARRATIVE_MAX_CHUNKS,
        }

    def _chunk_guideline_label(self, chunk: dict, kind: str) -> str:
        if not isinstance(chunk, dict):
            return ""
        if kind == "citation":
            return str(chunk.get("guideline") or chunk.get("source_guideline") or "").strip()
        return str(chunk.get("source_guideline") or chunk.get("guideline") or "").strip()

    def _diversify_chunks(self, chunks: list, kind: str, guideline_count: int) -> list:
        """Round-robin by guideline label for multi-guideline queries."""
        if guideline_count <= 1 or len(chunks) <= 1:
            return chunks

        buckets = {}
        order = []
        unlabeled = []

        for chunk in chunks:
            label = self._chunk_guideline_label(chunk, kind)
            if not label:
                unlabeled.append(chunk)
                continue
            if label not in buckets:
                buckets[label] = []
                order.append(label)
            buckets[label].append(chunk)

        if len(order) <= 1:
            return chunks

        diversified = []
        while True:
            progressed = False
            for label in order:
                bucket = buckets.get(label, [])
                if bucket:
                    diversified.append(bucket.pop(0))
                    progressed = True
            if not progressed:
                break

        diversified.extend(unlabeled)
        return diversified

    def _ensure_multi_guideline_citation_coverage(self, ui_citation_chunks: list, llm_limit: int) -> list:
        """Ensure LLM citation subset includes at least one chunk per guideline label when possible."""
        if llm_limit <= 0 or not ui_citation_chunks:
            return []

        selected = list(ui_citation_chunks[:llm_limit])
        if len(selected) <= 1:
            return selected

        def label_of(chunk: dict) -> str:
            return self._chunk_guideline_label(chunk, "citation")

        ui_labels = []
        seen = set()
        for chunk in ui_citation_chunks:
            label = label_of(chunk)
            if label and label not in seen:
                seen.add(label)
                ui_labels.append(label)

        if len(ui_labels) <= 1:
            return selected

        def selected_labels(chunks: list) -> set:
            out = set()
            for c in chunks:
                label = label_of(c)
                if label:
                    out.add(label)
            return out

        have = selected_labels(selected)
        for missing in ui_labels:
            if missing in have:
                continue

            candidate = None
            for c in ui_citation_chunks:
                if label_of(c) == missing and c not in selected:
                    candidate = c
                    break
            if candidate is None:
                continue

            replace_idx = len(selected) - 1
            for idx in range(len(selected) - 1, -1, -1):
                if not label_of(selected[idx]):
                    replace_idx = idx
                    break

            selected[replace_idx] = candidate
            have = selected_labels(selected)
            if have == set(ui_labels):
                break

        return selected

    def _chunk_key(self, chunk: dict, kind: str) -> str:
        if not isinstance(chunk, dict):
            return ""
        if kind == "citation":
            rec_id = str(chunk.get("recommendation_id") or "").strip()
            guideline = str(chunk.get("guideline") or "").strip()
            if rec_id:
                return f"{guideline}|{rec_id}"
            text = str(chunk.get("text") or chunk.get("content") or "").strip().lower()
            return f"{guideline}|{text[:160]}"
        source = str(chunk.get("source_guideline") or chunk.get("guideline") or "").strip()
        content = str(chunk.get("content") or "").strip().lower()
        return f"{source}|{content[:160]}"

    def _select_balanced_llm_chunks(self, chunks: list, kind: str, llm_limit: int, guideline_count: int) -> list:
        """
        Smarter selection:
        - keep highest-ranked chunks first
        - de-duplicate near-identical items
        - in multi-guideline mode, seed one item per guideline label when available
        """
        if llm_limit <= 0 or not chunks:
            return []

        # 1) De-duplicate while preserving rank order.
        deduped = []
        seen = set()
        for chunk in chunks:
            key = self._chunk_key(chunk, kind)
            if key and key in seen:
                continue
            if key:
                seen.add(key)
            deduped.append(chunk)

        if not deduped:
            return []

        if guideline_count <= 1:
            return deduped[:llm_limit]

        # 2) Seed one chunk per guideline label to keep cross-guideline coverage.
        selected = []
        used_keys = set()
        labels = []
        for chunk in deduped:
            label = self._chunk_guideline_label(chunk, kind)
            if label and label not in labels:
                labels.append(label)

        for label in labels:
            if len(selected) >= llm_limit:
                break
            for chunk in deduped:
                if self._chunk_guideline_label(chunk, kind) != label:
                    continue
                key = self._chunk_key(chunk, kind)
                if key and key in used_keys:
                    continue
                selected.append(chunk)
                if key:
                    used_keys.add(key)
                break

        # 3) Fill remaining slots by original ranked order.
        if len(selected) < llm_limit:
            for chunk in deduped:
                if len(selected) >= llm_limit:
                    break
                key = self._chunk_key(chunk, kind)
                if key and key in used_keys:
                    continue
                selected.append(chunk)
                if key:
                    used_keys.add(key)

        return selected[:llm_limit]

    def _infer_intent_profile(self, question: str, query_normalization: Optional[dict]) -> dict:
        q = (question or "").lower()
        norm = query_normalization if isinstance(query_normalization, dict) else {}
        normalized_q = str(norm.get("normalized_query") or "").lower()
        intent = str(norm.get("intent") or "").strip().lower() or None
        question_type = str(norm.get("question_type") or "").strip().lower() or None
        key_terms = [str(t).strip().lower() for t in (norm.get("key_terms") or []) if str(t).strip()]
        extra_terms = []
        for t in (norm.get("interpretation_terms") or []):
            t = str(t).strip().lower()
            if t:
                extra_terms.append(t)
        for t in (norm.get("must_include_terms") or []):
            t = str(t).strip().lower()
            if t:
                extra_terms.append(t)
        if extra_terms:
            key_terms.extend(extra_terms)
            seen = set()
            key_terms = [t for t in key_terms if not (t in seen or seen.add(t))]

        combined = f"{q} {normalized_q}".strip()
        if not intent:
            if re.search(r"\b(when|threshold|diameter|size|mm|cm|operate|repair|surgery|χειρουργ|επιδιόρθ|indication)\b", combined):
                intent = "threshold"
            elif re.search(r"\b(surveillance|follow[- ]?up|interval|monitor|παρακολ|surveil)\b", combined):
                intent = "surveillance"
            elif re.search(r"\b(imaging|scan|ultrasound|cta|cta\b|mra|mrv|dus|duplex|angiograph|απεικον|υπερηχο)\b", combined):
                intent = "imaging"
            elif re.search(r"\b(diagnos|workup|diagnostic|διάγν)\b", combined):
                intent = "diagnosis"
            elif re.search(r"\b(compare|versus|vs\\.?|difference|διαφορ)\b", combined):
                intent = "comparison"
            elif re.search(r"\b(risk|contraindicat|complication|bleed|αιμορρ|κίνδυ)\b", combined):
                intent = "risk"
            elif re.search(r"\b(what is|define|definition|τι ειναι|τι είναι)\b", combined):
                intent = "definition"
            else:
                intent = "management"

        return {
            "intent": intent,
            "question_type": question_type,
            "key_terms": key_terms[:8],
            "combined_query": combined,
        }

    def _intent_terms(self, intent: str) -> list[str]:
        table = {
            "threshold": ["threshold", "diameter", "size", "mm", "cm", "elective repair", "indication for repair", "operate", "surgery"],
            "indication": ["indication", "indicated", "considered for", "recommended", "should be considered"],
            "surveillance": ["surveillance", "follow-up", "follow up", "interval", "monitoring", "duplex", "ultrasound", "cta"],
            "imaging": ["imaging", "ultrasound", "duplex", "cta", "ct angiography", "mra", "mrv", "scan"],
            "diagnosis": ["diagnosis", "diagnostic", "work up", "workup", "ultrasound", "cta", "duplex"],
            "treatment": ["treatment", "management", "recommended", "considered", "therapy", "procedure"],
            "management": ["management", "recommended", "considered", "therapy", "treatment"],
            "procedure": ["procedure", "repair", "intervention", "stenting", "endarterectomy", "evar", "tevar"],
            "timing": ["timing", "when", "urgent", "delay", "early", "perioperative"],
            "comparison": ["versus", "vs", "compared", "difference", "rather than", "preference"],
            "risk": ["risk", "complication", "bleeding", "contraindication", "contraindicated"],
            "prognosis": ["prognosis", "outcome", "survival", "mortality"],
            "definition": ["definition", "is defined", "what is", "classification"],
            "general": [],
        }
        return table.get(intent or "general", [])

    def _key_term_candidates(self, intent_profile: dict) -> list[str]:
        terms = []
        if isinstance(intent_profile, dict):
            for t in intent_profile.get("key_terms", []) or []:
                t = str(t).strip().lower()
                if not t:
                    continue
                terms.append(t)
        stop = {
            "management", "treatment", "therapy", "guideline", "recommendation", "patient", "patients",
            "disease", "surgery", "repair", "aorta", "aneurysm"
        }
        def strip_anatomic_modifiers(term: str) -> str:
            modifiers = {
                "thoracic", "abdominal", "ascending", "descending", "arch", "thoracoabdominal",
                "thoraco-abdominal", "suprarenal", "infrarenal", "juxtarenal", "iliac",
            }
            words = [w for w in term.split() if w not in modifiers]
            return " ".join(words).strip()

        filtered = []
        variants = []
        for t in terms:
            if t in stop:
                continue
            if len(t) < 4:
                continue
            filtered.append(t)
            if " " in t:
                v = strip_anatomic_modifiers(t)
                if v and v != t and len(v) >= 4 and v not in stop:
                    variants.append(v)
        # Prefer multi-word terms; keep order, de-dup
        seen = set()
        ordered = []
        for t in filtered + variants:
            if t in seen:
                continue
            seen.add(t)
            ordered.append(t)
        return ordered[:8]

    def _term_match_score(self, text: str, terms: list[str]) -> int:
        if not text or not terms:
            return 0
        t = text.lower()
        score = 0
        for term in terms:
            if term and term in t:
                # Prefer multi-word/longer terms
                score += 3 if " " in term else 1
                score += min(len(term) // 10, 3)
        return score

    def _find_must_include_citation(self, chunks: list, terms: list[str]):
        best = None
        best_score = 0
        for chunk in chunks or []:
            text = self._chunk_text_for_scoring(chunk, "citation")
            score = self._term_match_score(text, terms)
            if score > best_score:
                best = chunk
                best_score = score
        return best, best_score

    def _chunk_text_for_scoring(self, chunk: dict, kind: str) -> str:
        if not isinstance(chunk, dict):
            return ""
        fields = []
        if kind == "citation":
            fields.extend([
                str(chunk.get("text") or chunk.get("content") or ""),
                str(chunk.get("guideline") or ""),
                str(chunk.get("category") or ""),
                str(chunk.get("category_name") or ""),
                str(chunk.get("class") or ""),
                str(chunk.get("level") or ""),
            ])
        else:
            fields.extend([
                str(chunk.get("content") or ""),
                str(chunk.get("source_guideline") or ""),
            ])
        return " ".join(fields).lower()

    def _score_chunk_for_intent(self, chunk: dict, kind: str, profile: dict) -> int:
        text = self._chunk_text_for_scoring(chunk, kind)
        if not text:
            return 0

        score = 0
        intent = str(profile.get("intent") or "general")
        for term in self._intent_terms(intent):
            if term and term.lower() in text:
                score += 4

        for term in profile.get("key_terms") or []:
            t = str(term).lower().strip()
            if t and t in text:
                score += 3

        combined_query = str(profile.get("combined_query") or "")
        if NON_A_NON_B_PATTERN.search(combined_query):
            if NON_A_NON_B_PATTERN.search(text):
                score += 12
        # Boost chunks that match decisive verbs/phrasing from the query.
        for cue in ["recommended", "should be considered", "indicated", "surveillance", "imaging", "diagnosis", "repair"]:
            if cue in combined_query and cue in text:
                score += 2

        # Prefer recommendation chunks for recommendation-like questions.
        question_type = str(profile.get("question_type") or "")
        if kind == "citation" and question_type in {"recommendation", "treatment_decision", "perioperative"}:
            score += 2

        # Small de-prioritization of generic methodology/front matter narrative chunks.
        if kind == "narrative":
            if "clinical practice guideline document" in text or "methodology" in text:
                score -= 2
            if "editor's choice" in text:
                score -= 1

        return score

    def _rank_chunks_by_intent(self, chunks: list, kind: str, profile: dict) -> list:
        if not chunks:
            return chunks
        scored = []
        for idx, chunk in enumerate(chunks):
            scored.append((self._score_chunk_for_intent(chunk, kind, profile), idx, chunk))
        # stable: keep original order as tie-breaker
        scored.sort(key=lambda x: (-x[0], x[1]))
        return [c for _, _, c in scored]

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

            headline = f"- {label}"
            if guideline_key:
                headline += f" ({guideline_key})"
            if caption:
                headline += f": {self._truncate_for_llm(caption, 180)}"

            lines.append(headline)
            # Thumbnail in chat + full-size target for custom UI popup handling.
            lines.append(f"  [![{alt_text}]({thumb_url})]({full_url})")
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
        ALLOW_PARTIAL_EVIDENCE_ANSWERS: bool = Field(
            default=True,
            description="Allow best-fit answers with explicit caveats when evidence is relevant but not exact",
        )
        SHOW_CLINICAL_FRAME: bool = Field(
            default=True,
            description="Expose interpretive clinical framing if provided by the API",
        )

    def _allow_partial_answers(self) -> bool:
        try:
            return bool(getattr(self.valves, "ALLOW_PARTIAL_EVIDENCE_ANSWERS"))
        except Exception:
            return bool(self.ALLOW_PARTIAL_MATCH_ANSWERS)

    def _show_clinical_frame(self) -> bool:
        try:
            return bool(getattr(self.valves, "SHOW_CLINICAL_FRAME"))
        except Exception:
            return True

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

    def _capabilities_response(self, question: str = "") -> str:
        """Static predefined guidance for out-of-scope or non-specific questions."""
        q = (question or "").strip()
        lines = []
        if q:
            lines.append(
                "This question is outside the supported ESVS retrieval scope (or not specific enough for guideline retrieval): "
                + q
            )
            lines.append("")

        lines.extend(
            [
                "=== APP CAPABILITIES GUIDANCE ===",
                "What this app is for",
                "- ESVS vascular guideline retrieval and evidence support for specific vascular clinical questions.",
                "- Case-to-guideline comparison using retrieved ESVS recommendations and supporting statements.",
                "- Figures/tables display when relevant assets exist.",
                "",
                "What to expect",
                "- For in-scope queries, the app retrieves ESVS evidence chunks and returns a citation-based answer.",
                "- For out-of-scope or vague queries, the app returns this usage guidance instead of guessing a guideline.",
                "",
                "How to use it properly (best results)",
                "1. Ask a specific clinical question (condition + decision/problem).",
                "2. Include anatomy/territory, acuity, and treatment context if known.",
                "3. For case review, include key patient details and what decision you want checked against ESVS.",
                "4. Ask for a standalone answer if you want it to ignore prior chat context.",
                "5. Ask one main clinical question per message when possible.",
                "",
                "Good examples",
                '- \"For CLTI with tissue loss, what does ESVS recommend for revascularization strategy?\"',
                '- \"Does this carotid stenosis management plan align with ESVS guidance?\"',
                '- \"What are ESVS recommendations for superficial/saphenous venous thrombosis treatment?\"',
                "",
                "Out of scope examples",
                "- General app onboarding without a clinical question (e.g., 'Can this app help me?').",
                "- Non-vascular or broad internal medicine questions without an ESVS vascular guideline target.",
                "- Technical/IT support questions (Linux, VPN, coding, server issues).",
                "",
                "Scope note",
                "- This app is focused on ESVS vascular guidelines, not general internal medicine or non-medical support.",
                "- It supports clinical reasoning with citations but does not replace clinical judgment or local protocols.",
            ]
        )
        return "\n".join(lines).strip()

    def _ensure_capabilities_marker(self, text: str, question: str = "") -> str:
        s = (text or "").strip()
        if not s:
            return self._capabilities_response(question)
        if "=== APP CAPABILITIES GUIDANCE ===" in s:
            return s
        return f"=== APP CAPABILITIES GUIDANCE ===\n\n{s}"

    async def explain_app_capabilities(
        self,
        question: str = "",
        __messages__: list = [],
        __event_emitter__: Callable[[dict], Awaitable[None]] = None,
    ) -> str:
        """
        Explain what this ESVS guideline app does and how to use it correctly.

        Use this tool instead of consult_vascular_guidelines for general onboarding/scope questions
        that do not ask about a specific vascular condition, patient case, or guideline recommendation.

        Examples:
        - "I am a vascular surgeon, how can you help me?"
        - "I am an internal medicine physician. Can this app help me?"
        - "What does this app do?"
        - "How should I ask questions to get the best results?"

        DO NOT use this tool for a concrete clinical question (e.g. AAA, CLTI, carotid stenosis,
        DVT/PE, venous thrombosis, antithrombotic therapy decisions). Use consult_vascular_guidelines instead.
        """
        emitter = __event_emitter__
        if (not question) and __messages__:
            # Recover gracefully if the function-calling model omitted parameters.
            for msg in reversed(__messages__):
                if msg.get("role") == "user" and isinstance(msg.get("content"), str):
                    question = msg["content"].strip()
                    if question:
                        break
        await self._emit_status(emitter, "Providing app usage guidance...")
        await asyncio.sleep(0.05)
        await self._emit_status(emitter, "Usage guidance ready", done=True)
        return self._capabilities_response(question)

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
        
        **CRITICAL**: Call this tool for concrete vascular clinical/guideline questions:
        1. ANY vascular surgery question (direct or follow-up)
        2. When the user attaches a patient case/document and asks about ESVS compliance
        3. When comparing patient management against guidelines

        **DO NOT CALL THIS TOOL** for general onboarding/capability questions such as:
        - "How can you help me?"
        - "Can this app help me?"
        - "What does this app do?"
        In those cases, call `explain_app_capabilities` instead.
        
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
            
            await self._emit_status(emitter, "🔍 Sending query to backend router...")
            await asyncio.sleep(0.1)  # Give UI time to update
            
            await self._emit_status(emitter, "📚 Searching selected guideline set...")
            
            async with httpx.AsyncClient(timeout=90.0) as client:
                # Start the request
                response_task = asyncio.create_task(
                    client.post(url, json=payload, headers=headers)
                )
                multi_guideline_progress = len(guidelines) > 1
                
                # Emit progress updates while waiting
                elapsed = 0
                while not response_task.done():
                    elapsed = int(time.time() - start_time)
                    
                    if elapsed < 5:
                        await self._emit_status(emitter, "📚 Searching selected guideline set...")
                    elif elapsed < 10:
                        await self._emit_status(emitter, f"🔎 Retrieving evidence chunks... ({elapsed}s)")
                    elif elapsed < 20:
                        if multi_guideline_progress:
                            await self._emit_status(emitter, f"📊 Processing multi-guideline results... ({elapsed}s)")
                        else:
                            await self._emit_status(emitter, f"📊 Processing retrieved evidence... ({elapsed}s)")
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

                guardrail = data.get("guardrail") or {}
                if isinstance(guardrail, dict) and guardrail.get("short_circuited"):
                    await self._emit_status(
                        emitter,
                        "Capability/onboarding query detected - skipping retrieval",
                        done=True,
                    )
                    msg = str(data.get("result") or "").strip()
                    return self._ensure_capabilities_marker(msg, question)
                
            elapsed = int(time.time() - start_time)
            await self._emit_status(emitter, f"✅ Retrieved in {elapsed}s - parsing results...")
            
            print(f"[VascularExpert] Response keys: {data.keys()}")
            
            # Extract chunks from response
            narrative_chunks = data.get("narrative_chunks", [])
            citation_chunks = data.get("citation_chunks", [])
            assets = data.get("assets", [])
            query_normalization = data.get("query_normalization", {})
            backend_selected = data.get("selected_guidelines", {})
            effective_guidelines = guidelines
            effective_guideline_display = guideline_display
            if isinstance(backend_selected, dict) and backend_selected:
                effective_guidelines = list(backend_selected.keys())
                display_names = []
                for key in effective_guidelines:
                    info = backend_selected.get(key) or {}
                    if isinstance(info, dict) and info.get("name"):
                        display_names.append(str(info.get("name")))
                    else:
                        display_names.append(GUIDELINE_NAMES.get(key, key))
                effective_guideline_display = ", ".join(display_names)
                await self._emit_status(
                    emitter,
                    f"🧭 Backend selected {len(effective_guidelines)} guideline(s): {effective_guideline_display}",
                )
            print(f"[VascularExpert] Chunks: narrative={len(narrative_chunks)}, citation={len(citation_chunks)}")

            intent_profile = self._infer_intent_profile(question, query_normalization)
            print(f"[VascularExpert] Intent profile: {intent_profile}")
            citation_chunks = self._rank_chunks_by_intent(citation_chunks, "citation", intent_profile)
            narrative_chunks = self._rank_chunks_by_intent(narrative_chunks, "narrative", intent_profile)

            caps = self._select_chunk_caps(len(effective_guidelines))
            prioritized_citation_chunks = self._diversify_chunks(citation_chunks, "citation", len(effective_guidelines))
            prioritized_narrative_chunks = self._diversify_chunks(narrative_chunks, "narrative", len(effective_guidelines))

            # UI Sources can expose more evidence than the LLM reads.
            ui_citation_chunks = prioritized_citation_chunks[: caps["ui_rec"]]
            ui_narrative_chunks = prioritized_narrative_chunks[: caps["ui_narr"]]

            # Ensure a term-matched citation is visible to the LLM when present.
            key_terms = self._key_term_candidates(intent_profile)
            must_include, must_score = self._find_must_include_citation(prioritized_citation_chunks, key_terms)
            if must_include and caps["ui_rec"] > 0:
                if must_include not in ui_citation_chunks:
                    if len(ui_citation_chunks) < caps["ui_rec"]:
                        ui_citation_chunks.append(must_include)
                    else:
                        ui_citation_chunks[-1] = must_include
                if caps["llm_rec"] > 0:
                    idx = ui_citation_chunks.index(must_include)
                    if idx >= caps["llm_rec"]:
                        swap_idx = caps["llm_rec"] - 1
                        ui_citation_chunks[idx], ui_citation_chunks[swap_idx] = (
                            ui_citation_chunks[swap_idx],
                            ui_citation_chunks[idx],
                        )
                if must_score > 0:
                    print(f"[VascularExpert] Forced citation include by terms {key_terms}: score={must_score}")

            # LLM subset stays bounded but uses smarter balanced selection.
            selected_citation_chunks = self._select_balanced_llm_chunks(
                ui_citation_chunks,
                "citation",
                caps["llm_rec"],
                len(effective_guidelines),
            )
            selected_narrative_chunks = self._select_balanced_llm_chunks(
                ui_narrative_chunks,
                "narrative",
                caps["llm_narr"],
                len(effective_guidelines),
            )
            selected_citation_keys = {self._chunk_key(c, "citation") for c in selected_citation_chunks}
            selected_narrative_keys = {self._chunk_key(c, "narrative") for c in selected_narrative_chunks}
            extra_ui_citation_chunks = [c for c in ui_citation_chunks if self._chunk_key(c, "citation") not in selected_citation_keys]
            extra_ui_narrative_chunks = [c for c in ui_narrative_chunks if self._chunk_key(c, "narrative") not in selected_narrative_keys]

            llm_total_chunks = len(selected_narrative_chunks) + len(selected_citation_chunks)
            ui_total_chunks = len(ui_narrative_chunks) + len(ui_citation_chunks)
            backend_total_chunks = len(narrative_chunks) + len(citation_chunks)
            
            # EMIT INDIVIDUAL CITATIONS for each chunk
            # This enables per-chunk citation popups in OpenWebUI
            if emitter:
                emitted_count = 0
                chunk_number = 1
                
                # Emit LLM-visible recommendations first so answer [n] numbering maps to the UI.
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
                
                # Emit LLM-visible narrative chunks next so numbering remains contiguous.
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

                # Emit extra UI-only recommendations after the LLM-visible sources.
                for chunk in extra_ui_citation_chunks:
                    text = chunk.get("text", chunk.get("content", ""))
                    rec_id = chunk.get("recommendation_id", "")
                    cls = chunk.get("class", "")
                    level = chunk.get("level", "")
                    guideline = chunk.get("guideline", "ESVS")
                    if rec_id:
                        title = f"Recommendation {rec_id} from {guideline} - Class {cls}, Level {level}"
                    else:
                        title = f"Recommendation from {guideline}"
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

                # Emit extra UI-only narrative chunks last.
                extra_narrative_i = len(selected_narrative_chunks) + 1
                for chunk in extra_ui_narrative_chunks:
                    content = chunk.get("content", "")
                    source_guideline = chunk.get("source_guideline", "ESVS")
                    title = f"{source_guideline} - Narrative {extra_narrative_i}: {_short_label(content)}"
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
                                    "chunk": extra_narrative_i,
                                }],
                                "source": {"id": f"{chunk_number}", "name": title},
                            }
                        })
                        emitted_count += 1
                    except Exception as e:
                        print(f"Error emitting citation: {e}")
                    chunk_number += 1
                    extra_narrative_i += 1
            
            if llm_total_chunks > 0:
                status_msg = (
                    f"Retrieved {backend_total_chunks} chunks from {effective_guideline_display}; "
                    f"using {llm_total_chunks} for answer, exposing {ui_total_chunks} in Sources"
                )
                await self._emit_status(emitter, status_msg, done=True)
                
                # Build formatted text for the LLM
                # We format strict headers to match the System Prompt requirements
                llm_output = (
                    "Consultation successful. "
                    f"Using {llm_total_chunks} evidence sources for answer synthesis "
                    f"(from {backend_total_chunks} retrieved chunks; {ui_total_chunks} shown in Sources).\n\n"
                )

                if self._show_clinical_frame():
                    clinical_frame = ""
                    if isinstance(query_normalization, dict):
                        clinical_frame = str(query_normalization.get("clinical_frame") or "").strip()
                    if clinical_frame:
                        llm_output += "=== CLINICAL FRAME (INTERPRETIVE / NON-GUIDELINE) ===\n"
                        llm_output += clinical_frame + "\n"
                        llm_output += "Guidance: You may include a brief interpretive framing note, clearly labeled as non-guideline and without citations.\n\n"

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
                elif selected_narrative_chunks:
                    llm_output += "=== RECOMMENDATIONS ===\n"
                    llm_output += "No guideline-specific recommendation chunks were retrieved for this query. Use narrative context to answer and state that no direct recommendation chunk was retrieved.\n\n"

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

                llm_output += "=== CLINICAL DECISION SYNTHESIS (REQUIRED) ===\n"
                llm_output += "Using the retrieved recommendations, synthesize the best management strategy for this patient.\n"
                llm_output += "Explicitly explain:\n"
                llm_output += "1. Whether treatment thresholds are met\n"
                llm_output += "2. Interpretation of the anatomical features provided\n"
                llm_output += "3. Comparison of available treatment strategies\n"
                llm_output += "4. Most guideline-consistent strategy\n"
                llm_output += "5. Why alternative strategies may also be considered\n"
                llm_output += "If anatomical measurements are provided (e.g., neck length, angulation, landing zones), interpret whether they are compatible with: standard EVAR, fenestrated/branched endovascular repair, and open surgical repair.\n"
                llm_output += "Explain how anatomy influences treatment modality choice.\n\n"
                llm_output += "=== PERIOPERATIVE RISK MITIGATION (GUIDELINE-BASED, REQUIRED) ===\n"
                llm_output += "When discussing operative management, summarize key perioperative risk-reduction strategies mentioned in the guideline, including when relevant:\n"
                llm_output += "- spinal cord ischemia prevention\n"
                llm_output += "- renal protection\n"
                llm_output += "- cardiac risk optimisation\n"
                llm_output += "- staged repair strategies\n"
                llm_output += "- preservation of critical branch vessels\n\n"

                assets_block = self._format_assets_markdown(assets)
                if assets_block:
                    llm_output += assets_block
                
                llm_output += "=== CITATION RULES ===\n"
                llm_output += "1. Use simple numbered citations [1], [2], [3] inline after each fact.\n"
                llm_output += "2. Cite only sources you actually use; do not force-cite unrelated evidence.\n"
                llm_output += "3. Do NOT add a separate References section; the UI already shows a Sources list.\n"
                llm_output += "4. Match the bracketed numbers [n] exactly to the evidence blocks above.\n"
                llm_output += "5. If evidence spans multiple guidelines, cite at least one recommendation from each guideline used in your synthesis.\n"
                if not selected_citation_chunks and selected_narrative_chunks:
                    llm_output += "6. It is valid to answer from narrative context only and explicitly say no direct recommendation chunk was retrieved.\n"
                if assets_block:
                    next_rule_num = 7 if (not selected_citation_chunks and selected_narrative_chunks) else 6
                    llm_output += f"{next_rule_num}. If images help, include up to 3 markdown image lines from the FIGURES / TABLES section.\n"

                if self._allow_partial_answers():
                    llm_output += "\n=== PARTIAL MATCH GUIDANCE ===\n"
                    llm_output += "If the evidence is relevant but does not exactly match the user's scenario, you MUST still provide a best-fit answer based on the closest evidence.\n"
                    llm_output += "Explicitly state which parts are directly supported vs extrapolated or missing.\n"
                    llm_output += "Do NOT reply with a blanket 'not explicitly addressed' statement unless there is zero relevant evidence.\n"
                    llm_output += "Invite the user to decide which elements are applicable to their case.\n"
                    if self.STRICT_TEMPLATE:
                        llm_output += "Place the fit/limitations note within Assessment or Evidence used to preserve the required structure.\n"

                if self.STRICT_TEMPLATE:
                    llm_output += "\n=== REQUIRED STRUCTURE (STRICT) ===\n"
                    llm_output += "Assessment:\n"
                    llm_output += "Imaging:\n"
                    llm_output += "Indication for intervention:\n"
                    llm_output += "Treatment options:\n"
                    llm_output += "Clinical Decision Synthesis:\n"
                    llm_output += "Perioperative Risk Mitigation:\n"
                    llm_output += "Follow-up:\n"
                    llm_output += "Evidence used (Rec #, Class, Level):\n"
                
                return llm_output
            else:
                await self._emit_status(
                    emitter, 
                    f"Consultation complete ({effective_guideline_display})",
                    done=True
                )
                return self._capabilities_response(question)
            
        except httpx.TimeoutException:
            await self._emit_status(emitter, "Request timed out", done=True)
            return "Error: Request timed out after 90 seconds"
        except httpx.HTTPStatusError as e:
            await self._emit_status(emitter, f"API error: {e.response.status_code}", done=True)
            return f"Error calling Vascular Expert API: HTTP {e.response.status_code}"
        except Exception as e:
            await self._emit_status(emitter, f"Error: {str(e)[:50]}", done=True)
            return f"Error calling Vascular Expert API: {str(e)}"
