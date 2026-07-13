"""
title: Vascular Agent Adapter
author: open-webui
version: 3.7.0
description: Thin bridge to the Vizra ADK vascular agent on the Laravel backend.
Clarification gate runs locally (instant, no HTTP). Retrieval+synthesis via backend.
"""

import hashlib
import re
from typing import Awaitable, Callable, Optional

import httpx
from pydantic import BaseModel, Field


VALID_GUIDELINE_KEYS = {
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
    "vascular_access",
}


# ── Session key ──────────────────────────────────────────────────────────────

def _as_dict(value) -> dict:
    if isinstance(value, dict):
        return value
    return {}


def _session_key(user: dict, metadata: dict) -> str:
    uid = str(user.get("id", "anon"))
    chat = str(metadata.get("chat_id", metadata.get("conversation_id", "nochat")))
    return hashlib.sha256(f"{uid}:{chat}".encode()).hexdigest()[:24]


# ── History extraction ───────────────────────────────────────────────────────

def _extract_history(messages: Optional[list], question: str) -> list[str]:
    if not messages:
        return []

    history: list[str] = []
    for message in messages[-12:]:
        if not isinstance(message, dict):
            continue

        role = str(message.get("role") or "user").strip() or "user"
        if role not in {"user", "assistant"}:
            continue

        content = message.get("content")
        text = ""
        if isinstance(content, str):
            text = content.strip()
        elif isinstance(content, list):
            parts = []
            for item in content:
                if isinstance(item, str) and item.strip():
                    parts.append(item.strip())
                elif isinstance(item, dict):
                    chunk = item.get("text")
                    if isinstance(chunk, str) and chunk.strip():
                        parts.append(chunk.strip())
            text = "\n".join(parts).strip()

        if not text:
            continue
        if role == "user" and text == question.strip():
            continue

        history.append(f"{role}: {text}")

    return history[-10:]


# ── Instant clarification gate (Python-side, zero latency) ──────────────────

def _is_clinical_scenario(question: str) -> bool:
    """True when the question describes a patient case (not a general knowledge question)."""
    return bool(re.search(
        r'\b(my patient|this patient|the patient|patient\b|case\b'
        r'|\d{1,3}\s*[-]?\s*(?:year[s]?[-\s]?old|yo)\b'
        r'|male\b|female\b|man\b|woman\b'
        r'|presents?\s+with|presented\s+with|referred\s+with)\b',
        question, re.IGNORECASE,
    ))


def _detect_scenario(question: str) -> str:
    """Map question text to a clinical scenario key."""
    q = question.lower()

    if re.search(r'\biliac\b', q) and re.search(r'\baneurysm', q):
        return "iliac_aneurysm"
    if re.search(r'\b(aaa|abdominal aortic aneurysm)\b', q) and "iliac" not in q:
        return "aaa_treatment"
    if "carotid" in q and re.search(r'\b(stenosis|occlusion|endarterectomy|cea|cas|tia|stroke|amaurosis)\b', q):
        return "carotid_stenosis"
    if re.search(r'\b(dvt|deep vein thrombosis|pe\b|pulmonary embolism|vte)\b', q):
        return "dvt_pe"
    if re.search(r'\b(clti|critical limb|rest pain|tissue loss|gangrene)\b', q):
        return "clti"
    if re.search(r'\b(ali\b|acute limb ischaemi|acute limb ischemi)\b', q):
        return "ali"
    if re.search(r'\b(type b dissection|aortic dissection|tbad|type-b)\b', q):
        return "type_b_dissection"
    if re.search(r'\b(graft infection|endograft infection|prosth.*infect|infect.*graft)\b', q):
        return "graft_infection"
    if re.search(r'\b(svt\b|superficial vein thrombosis|superficial thrombophlebitis)\b', q):
        return "svt"
    if re.search(r'\b(aortic thrombus|mural thrombus|floating thrombus|aortic mural)\b', q):
        return "aortic_thrombus"
    return "generic_case"


# Each entry: (missing_context_fn, clarification_prompt)
_CLARIFICATION_RULES: dict[str, tuple] = {
    "iliac_aneurysm": (
        lambda q: not (
            re.search(r'\b(symptomatic|asymptomatic|pain|ruptur|embol)\b', q, re.I)
            and re.search(r'\b(common|internal|external|cia|iia|eia|fit|frail|comorbid|high.risk)\b', q, re.I)
        ),
        "For the iliac artery aneurysm, please clarify:\n"
        "1. Is it **symptomatic** (pain, rupture, distal embolisation) or asymptomatic?\n"
        "2. Which vessel: **common iliac (CIA)**, internal iliac (IIA), or external iliac (EIA)?\n"
        "3. Is there an **associated aortic or contralateral iliac aneurysm**?\n"
        "4. Is the patient **fit for intervention**, or are there significant comorbidities affecting operative risk?",
    ),
    "aaa_treatment": (
        lambda q: not (
            re.search(r'\b\d+\s*(mm|cm)\b', q, re.I)
            and re.search(r'\b(fit|frail|comorbid|surgical candidate|high.risk|operative risk)\b', q, re.I)
        ),
        "For AAA management, please provide:\n"
        "1. The **maximum aneurysm diameter** (mm or cm)\n"
        "2. Whether the patient is **fit for open/endovascular repair**, or has major comorbidities affecting operative risk",
    ),
    "carotid_stenosis": (
        lambda q: not (
            re.search(r'\b(symptomatic|asymptomatic|tia|stroke|amaurosis)\b', q, re.I)
            and re.search(r'\b\d{1,3}\s*%|\b(severe|moderate|mild)\s+stenosis\b|\bnascet\b', q, re.I)
        ),
        "For carotid artery management, please provide:\n"
        "1. Is the patient **symptomatic** (TIA, stroke, amaurosis fugax) or asymptomatic?\n"
        "2. What is the **degree of stenosis** (% by NASCET criteria)?",
    ),
    "dvt_pe": (
        lambda q: not (
            re.search(r'\b(provoked|unprovoked)\b', q, re.I)
            and re.search(r'\b(first|recurrent|repeat|previous|prior)\b', q, re.I)
        ),
        "For venous thrombosis management, please clarify:\n"
        "1. Is this a **provoked or unprovoked** event?\n"
        "2. Is this the **first episode** or a recurrent VTE?\n"
        "3. Is there active **malignancy** or another persistent risk factor?",
    ),
    "clti": (
        lambda q: not (
            re.search(r'\b(duplex|cta|runoff|abi|rutherford|wifi|glass)\b', q, re.I)
            and re.search(r'\b(fit|frail|life expectancy|comorbid)\b', q, re.I)
        ),
        "For CLTI management, please provide:\n"
        "1. **Anatomical workup** — has duplex/CTA runoff been performed? What does it show?\n"
        "2. **Rutherford/WIfI classification** if available\n"
        "3. Is the patient **fit for revascularisation**, or is primary amputation being considered?",
    ),
    "ali": (
        lambda q: not (
            re.search(r'\b(rutherford|motor|sensory|duration|6ps)\b', q, re.I)
            and re.search(r'\b(embolic|embolus|thrombotic|thrombosis)\b', q, re.I)
        ),
        "For acute limb ischaemia, please provide:\n"
        "1. **Rutherford class** (I / IIa / IIb / III) — any motor or sensory deficit?\n"
        "2. **Duration** of ischaemia (hours)\n"
        "3. Suspected **aetiology**: embolic vs in-situ thrombosis?",
    ),
    "type_b_dissection": (
        lambda q: not (
            re.search(r'\b(complicated|uncomplicated)\b', q, re.I)
            and re.search(r'\b(acute|subacute|chronic)\b', q, re.I)
        ),
        "For type B aortic dissection, please clarify:\n"
        "1. Is it **complicated** (malperfusion, rupture, refractory hypertension, rapid expansion) or uncomplicated?\n"
        "2. **Phase**: acute (<2 weeks), subacute (2–6 weeks), or chronic (>6 weeks)?",
    ),
    "graft_infection": (
        lambda q: not (
            re.search(r'\b(fever|sepsis|ct|pet|fistula|haemorrhage|hemorrhage)\b', q, re.I)
            and re.search(r'\b(days?|weeks?|months?|years?)\b', q, re.I)
        ),
        "For graft infection, please provide:\n"
        "1. **Clinical presentation** and relevant imaging (fever, sepsis, CT/PET findings, fistula, haemorrhage)\n"
        "2. **Prosthesis type** (Dacron / PTFE / endograft) and **time since implantation**",
    ),
    "svt": (
        lambda q: not (
            re.search(r'\b(sfj|spj|sapheno|junction)\b', q, re.I)
            and re.search(r'\b(risk|anticoag|dvt|extension|distance)\b', q, re.I)
        ),
        "For superficial vein thrombosis, please clarify:\n"
        "1. **Distance from the SFJ or SPJ** (cm) — confirmed on duplex?\n"
        "2. **Risk factors** for DVT extension or evidence of concurrent DVT?",
    ),
    "aortic_thrombus": (
        lambda q: not re.search(
            r'\b(anticoag|anticoagul|heparin|stroke|embol|morphology|mobile|mural|sessile|pedunculated)\b',
            q, re.I,
        ),
        "For aortic mural thrombus, please provide:\n"
        "1. **Anticoagulation status** and any contraindications\n"
        "2. **Thrombus morphology**: mobile / pedunculated or sessile / mural?\n"
        "3. **Stroke aetiology workup** — was an alternative embolic source excluded?",
    ),
    "generic_case": (
        lambda q: not re.search(
            r'\b(symptomatic|asymptomatic|diameter|cta|duplex|rutherford|wifi'
            r'|provoked|unprovoked|acute|chronic|fit|frail|comorbid|stenosis|occlusion)\b',
            q, re.I,
        ),
        "To give you an evidence-based recommendation, please clarify:\n"
        "1. The **exact diagnosis** and the primary vessel or territory involved\n"
        "2. Key **clinical features**: severity, acuity, relevant imaging findings\n"
        "3. The **main management question** you want answered",
    ),
}


def _check_clarification(question: str, history: list[str]) -> Optional[str]:
    """
    Return a clarification prompt string if the question is a sparse patient-specific
    case missing critical context.  Returns None for general knowledge questions
    or when sufficient context is present, or when there is prior history
    (follow-up turns already carry context).
    """
    if history:
        return None                      # follow-up turns — skip gate
    if not _is_clinical_scenario(question):
        return None                      # general knowledge — go straight to retrieval

    scenario = _detect_scenario(question)
    entry = _CLARIFICATION_RULES.get(scenario)
    if entry is None:
        return None

    missing_fn, prompt_text = entry
    if missing_fn(question):
        return prompt_text
    return None


# ── Main tool class ──────────────────────────────────────────────────────────

class Tools:
    class Valves(BaseModel):
        LARAVEL_URL: str = Field(
            default="https://lavarel.eastus2.cloudapp.azure.com",
            description="Laravel API base URL",
        )
        API_KEY: str = Field(default="", description="Laravel API key (X-API-Key header)")
        TIMEOUT: int = Field(default=120, description="Request timeout in seconds")

    def __init__(self):
        self.valves = self.Valves()

    async def _emit_status(self, emitter, message: str, done: bool = False):
        if emitter:
            await emitter({"type": "status", "data": {"description": message, "done": done}})

    async def _emit_citations(self, emitter, citations: list, narratives: list, assets: list):
        if not emitter:
            return

        index = 1

        for chunk in citations:
            rec_id = str(chunk.get("recommendation_id") or chunk.get("rec_id") or "").strip()
            rec_class = str(chunk.get("class") or "").strip()
            level = str(chunk.get("level") or "").strip()
            guideline = str(chunk.get("guideline") or chunk.get("source_guideline") or "ESVS").strip()
            text = str(chunk.get("text") or chunk.get("content") or "").strip()
            title = f"Rec {rec_id} from {guideline}" if rec_id else f"Recommendation from {guideline}"
            if rec_class or level:
                title += f" - Class {rec_class or 'N/A'}, Level {level or 'N/A'}"

            await emitter(
                {
                    "type": "citation",
                    "data": {
                        "document": [text],
                        "metadata": [
                            {
                                "source": title,
                                "guideline": guideline,
                                "recommendation_id": rec_id,
                                "class": rec_class,
                                "level": level,
                            }
                        ],
                        "source": {"id": str(index), "name": title},
                    },
                }
            )
            index += 1

        for chunk in narratives:
            guideline = str(chunk.get("source_guideline") or chunk.get("guideline") or "ESVS").strip()
            text = str(chunk.get("content") or "").strip()
            title = f"{guideline} - supporting evidence"

            await emitter(
                {
                    "type": "citation",
                    "data": {
                        "document": [text],
                        "metadata": [{"source": title, "guideline": guideline, "kind": "narrative"}],
                        "source": {"id": str(index), "name": title},
                    },
                }
            )
            index += 1

        for asset in assets:
            name = str(asset.get("label") or asset.get("title") or asset.get("name") or "Figure").strip()
            full_url = str(asset.get("url") or "").strip()
            thumb_url = str(asset.get("thumbnail_url") or full_url).strip()
            caption = str(asset.get("caption") or "").strip()
            doc_text = f"{name}" + (f" — {caption}" if caption else "")
            await emitter(
                {
                    "type": "citation",
                    "data": {
                        # document = thumbnail shown in sidebar preview
                        "document": [f"![{name}]({thumb_url})" if thumb_url else doc_text],
                        "metadata": [{"source": name, "url": full_url, "thumbnail_url": thumb_url, "kind": "asset"}],
                        # source.url = the full image URL opened on click
                        "source": {"id": str(index), "name": name, "url": full_url},
                    },
                }
            )
            index += 1

    async def consult_vascular_guidelines(
        self,
        question: str,
        guideline_1: Optional[str] = None,
        guideline_2: Optional[str] = None,
        guideline_3: Optional[str] = None,
        __messages__: Optional[list] = None,
        __user__: Optional[dict] = None,
        __metadata__: Optional[dict] = None,
        __event_emitter__: Optional[Callable[[dict], Awaitable[None]]] = None,
    ) -> str:
        """
        Consult ESVS vascular surgery guidelines via the Vizra vascular agent.
        Use for clinical vascular questions, follow-up management questions, or clarification-driven case consults.

        guideline_1/2/3: optional guideline hints - valid keys:
        aortic_arch, descending_thoracic_aorta, abdominal_aortic_aneurysm,
        mesenteric_renal, asymptomatic_pad, clti, acute_limb_ischaemia,
        carotid_vertebral, venous_thrombosis, chronic_venous_disease,
        antithrombotic_therapy, vascular_trauma, vascular_graft_infections, vascular_access
        """
        if not self.valves.API_KEY:
            return "Configuration error: API_KEY not set in tool valves."

        emitter = __event_emitter__
        messages = __messages__ or []
        user = _as_dict(__user__)
        metadata = _as_dict(__metadata__)
        session_key = _session_key(user, metadata)
        history = _extract_history(messages, question)

        # ── Instant clarification gate (zero latency, no HTTP call) ─────────
        clarification = _check_clarification(question, history)
        if clarification:
            await self._emit_status(emitter, "Clarification requested", done=True)
            return "GUIDELINE_RETRIEVAL_PAUSED\n\n" + clarification

        # ── Full retrieval + synthesis via backend ───────────────────────────
        await self._emit_status(emitter, "Consulting ESVS guidelines...")

        hints = [
            key
            for key in [guideline_1, guideline_2, guideline_3]
            if key and key in VALID_GUIDELINE_KEYS
        ]

        try:
            async with httpx.AsyncClient(timeout=self.valves.TIMEOUT) as client:
                response = await client.post(
                    f"{self.valves.LARAVEL_URL.rstrip('/')}/api/v1/agent-consult",
                    json={
                        "question": question,
                        "session_key": session_key,
                        "guidelines": hints,
                        "history": history,
                    },
                    headers={
                        "X-API-Key": self.valves.API_KEY,
                        "Accept": "application/json",
                        "Content-Type": "application/json",
                    },
                )
                response.raise_for_status()
                data = response.json()
        except httpx.TimeoutException:
            await self._emit_status(emitter, "Timeout", done=True)
            return "The guideline retrieval timed out. Please try again."
        except Exception as exc:
            await self._emit_status(emitter, f"Error: {str(exc)[:80]}", done=True)
            return f"Error consulting guidelines: {exc}"

        # ── Rich status from response metadata ──────────────────────────────
        citations = data.get("citations", [])
        narratives = data.get("narratives", [])
        assets = data.get("assets", [])
        gap = data.get("gap_assessment", {})
        mode = data.get("mode", "")

        n_cit = len(citations)
        n_nar = len(narratives)
        n_total = n_cit + n_nar

        gl_set: list[str] = []
        seen: set[str] = set()
        for c in citations + narratives:
            gl = str(c.get("guideline") or c.get("source_guideline") or "").strip()
            if gl and gl not in seen:
                gl_set.append(gl)
                seen.add(gl)
        gl_label = ", ".join(gl_set[:3]) if gl_set else "ESVS guidelines"

        if gap.get("has_guideline_gap"):
            facets = gap.get("uncovered_facets", [])
            facet_str = (", ".join(str(f) for f in facets[:3])) if facets else ""
            status_msg = (
                "Pre-retrieval signal: possible guideline gap"
                + (f" (partial: {facet_str})" if facet_str else "")
                + " — supplementary reasoning section included"
            )
        elif n_total > 0:
            status_msg = (
                f"Retrieved {n_total} chunks from {gl_label}; "
                f"using {n_cit} citation{'s' if n_cit != 1 else ''} for answer, "
                f"exposing {n_total} in Sources"
            )
        else:
            status_msg = "Answer ready"

        await self._emit_status(emitter, status_msg, done=False)
        await self._emit_citations(emitter, citations, narratives, assets)
        await self._emit_status(emitter, "Done", done=True)

        response_text = data.get("response") or data.get("result") or "No response from agent."

        # ── Asset injection fallback ─────────────────────────────────────────
        # Inject clickable thumbnail markdown when the model omits asset blocks.
        if assets and "🖼️" not in response_text and "Figures" not in response_text:
            lines = ["\n\n## 🖼️ Figures / Tables"]
            for asset in assets:
                if not isinstance(asset, dict):
                    continue
                full_url = str(asset.get("full_url") or asset.get("url") or "").strip()
                thumb = str(asset.get("thumb_url") or full_url).strip()
                label = str(asset.get("label") or asset.get("title") or asset.get("name") or "Figure").strip()
                caption = str(asset.get("caption") or "").strip()
                if thumb:
                    line = f"![{label}]({thumb})"
                    if full_url and full_url != thumb:
                        line += f"\n[Full-size]({full_url})"
                    if caption:
                        line += f"\n*{caption}*"
                    lines.append(line)
            if len(lines) > 1:
                response_text += "\n".join(lines)

        # ── Correct marker for outer gpt-5-chat ─────────────────────────────
        # GUIDELINE_RETRIEVAL_PAUSED  → clarification gate (handled above, instant)
        # === ANSWER STYLE === / === OUTPUT BLUEPRINT === → evidence answer pass-through
        return (
            "=== ANSWER STYLE (MANDATORY) ===\n"
            "=== OUTPUT BLUEPRINT (STRICT) ===\n\n"
            + response_text
        )

    async def explain_app_capabilities(
        self,
        question: str = "",
        __messages__: Optional[list] = None,
        __user__: Optional[dict] = None,
        __metadata__: Optional[dict] = None,
        __event_emitter__: Optional[Callable[[dict], Awaitable[None]]] = None,
    ) -> str:
        """
        Explain what this app does and when to use it.
        """
        return (
            "This app retrieves and applies ESVS vascular surgery guideline evidence for vascular clinical questions. "
            "Use it for case-based questions about aneurysms, carotid disease, limb ischaemia, venous thrombosis, "
            "vascular trauma, graft infections, vascular access, and antithrombotic decisions in vascular care. "
            "It is not a general-knowledge or non-vascular assistant."
        )
