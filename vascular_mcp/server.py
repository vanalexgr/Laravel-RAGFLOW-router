#!/usr/bin/env python3
"""Vascular MCP Server — FastMCP wrapper around the Laravel RAGFlow vascular guidelines API.

Usage:
    python server.py stdio                  # stdio transport (testing / Claude Desktop)
    python server.py streamable_http 8080   # streamable HTTP transport (production)
"""

import json
import os
import re
import sys
from enum import Enum
from typing import Any

import httpx
from dotenv import load_dotenv
from mcp.server.fastmcp import FastMCP

load_dotenv(os.path.join(os.path.dirname(__file__), ".env"))  # load .env when running outside systemd (e.g. pytest)

# ─── Configuration ────────────────────────────────────────────────────────────

LARAVEL_BASE_URL = os.environ.get("LARAVEL_BASE_URL", "http://127.0.0.1:8001")
LARAVEL_API_KEY  = os.environ.get("LARAVEL_API_KEY", "")
LARAVEL_TIMEOUT  = 120.0

# ─── Response mode ─────────────────────────────────────────────────────────────

class ResponseMode(str, Enum):
    NARRATIVE = "narrative"   # current OpenWebUI Markdown output (default)
    AGENT     = "agent"       # structured JSON output for MCP/Codex clients

# ─── Available guideline datasets ─────────────────────────────────────────────

GUIDELINE_NAMES: dict[str, str] = {
    "aortic_arch":               "Aortic Arch",
    "descending_thoracic_aorta": "Descending Thoracic Aorta",
    "abdominal_aortic_aneurysm": "Abdominal Aortic Aneurysm (AAA)",
    "mesenteric_renal":          "Mesenteric and Renal Artery",
    "asymptomatic_pad":          "Asymptomatic PAD",
    "clti":                      "Chronic Limb-Threatening Ischaemia (CLTI)",
    "acute_limb_ischaemia":      "Acute Limb Ischaemia (ALI)",
    "carotid_vertebral":         "Carotid and Vertebral Artery",
    "venous_thrombosis":         "Venous Thrombosis (DVT / PE / SVT)",
    "chronic_venous_disease":    "Chronic Venous Disease",
    "antithrombotic_therapy":    "Antithrombotic Therapy",
    "vascular_trauma":           "Vascular Trauma",
    "vascular_graft_infections": "Vascular Graft Infections",
    "vascular_access":           "Vascular Access",
}

_GUIDELINE_DESCRIPTIONS: dict[str, str] = {
    "aortic_arch":               "Surgical and endovascular management of aortic arch pathology including hybrid and total arch repair",
    "descending_thoracic_aorta": "Descending thoracic aorta pathology — note: no figures/tables currently configured",
    "abdominal_aortic_aneurysm": "Management of abdominal and thoracic aortic aneurysms including EVAR and open repair indications",
    "mesenteric_renal":          "Diagnosis and treatment of mesenteric and renal artery stenosis and aneurysms",
    "asymptomatic_pad":          "Medical and interventional management of PAD including claudication and ABI thresholds",
    "clti":                      "Assessment and revascularisation for CLTI including bypass, angioplasty, and amputation decisions",
    "acute_limb_ischaemia":      "Emergency management of ALI including Rutherford classification and revascularisation timing",
    "carotid_vertebral":         "Diagnosis and treatment of carotid and vertebral artery stenosis including CEA and CAS",
    "venous_thrombosis":         "Management of DVT, PE, and superficial vein thrombosis including anticoagulation decisions",
    "chronic_venous_disease":    "Varicose veins, chronic venous insufficiency, and superficial vein thrombosis management",
    "antithrombotic_therapy":    "Anticoagulation and antiplatelet decisions in vascular patients — use only when anticoagulation is the question",
    "vascular_trauma":           "Assessment and management of vascular injuries including endovascular and open repair",
    "vascular_graft_infections": "Diagnosis and management of prosthetic graft and endograft infections",
    "vascular_access":           "Arteriovenous fistula, graft, and central venous access creation and maintenance",
}

_GUIDELINE_HAS_ASSETS: dict[str, bool] = {
    key: (key != "descending_thoracic_aorta") for key in GUIDELINE_NAMES
}

# ─── Context-gate regexes ─────────────────────────────────────────────────────

_PATIENT_CASE_RE = re.compile(
    r"\b(my\s+patient|this\s+patient|the\s+patient|"
    r"patient\s+(?:with|who|has)|pt\s+(?:with|who|has)|"
    r"case\s+of|this\s+case|the\s+case|"
    r"\d{1,3}\s*(?:year[- ]old|yo)\b|"
    r"(?:male|female|man|woman)\s+with|"
    r"presents?\s+with|presented\s+with|admitted\s+with|referred\s+with|"
    r"was\s+found|incidentally)\b",
    re.IGNORECASE,
)

_RAW_GUIDELINE_KNOWLEDGE_RE = re.compile(
    r"\b(what\s+is|what\s+does|define|definition|how\s+is.{0,40}defined|"
    r"classification|criteria|index|score|staging|stage|"
    r"threshold|cut[- ]?off|diameter\s+threshold|treatment\s+threshold|"
    r"surveillance\s+interval|recommendation\s+\d+|rec\s+\d+)\b",
    re.IGNORECASE,
)

_GENERIC_PATIENT_POPULATION_RE = re.compile(
    r"\b(in|for|among|which)\s+patients?\b|\bpatients?\s+with\b",
    re.IGNORECASE,
)

# ─── Clinical context-gap rules (7 scenarios + generic catch-all) ─────────────

_CONTEXT_GAP_RULES: list[dict] = [
    {
        "id": "carotid_stenosis",
        "suggested_guidelines": ["carotid_vertebral"],
        "detect": [
            r"\bcarotid\s+(?:artery\s+)?stenosis\b",
            r"\bcea\b",
            r"\bcarotid\s+endarterectomy\b",
            r"\bcarotid\s+stenting\b",
            r"\bcas\b.{0,30}carotid",
        ],
        "categories": [
            {
                "present_if": [
                    r"\bsymptomatic\b", r"\basymptomatic\b", r"\btia\b",
                    r"\btransient\s+ischaem\b", r"\bamaurosis\b",
                    r"recent\s+stroke", r"neurological\s+symptom",
                ],
                "question": (
                    "**Symptomatic status**: Is this symptomatic (recent TIA, stroke, "
                    "or amaurosis fugax within 6 months) or asymptomatic carotid stenosis?"
                ),
            },
            {
                "present_if": [
                    r"\d+\s*%", r"\bpercent\b", r"\bsevere\s+stenosis\b",
                    r"\bmoderate\b", r"\bnascet\b", r"degree\s+of\s+stenosis",
                ],
                "question": "**Stenosis degree**: What is the degree of stenosis (% by NASCET criteria)?",
            },
        ],
        "min_absent": 2,
    },
    {
        "id": "aaa_treatment",
        "suggested_guidelines": ["abdominal_aortic_aneurysm"],
        "detect": [
            r"\baaa\b",
            r"\babdominal\s+aortic\s+aneurysm\b",
            r"aortic\s+aneurysm.{0,40}(?:infra|supra|juxta)renal",
        ],
        "categories": [
            {
                "present_if": [
                    r"\d[\.,]\d\s*cm\b", r"\d{2,3}\s*mm\b", r"\bdiameter\b",
                    r"maximum\s+diameter", r"\bsize\b.{0,20}\bcm\b",
                ],
                "question": "**Aneurysm size**: What is the maximum transverse diameter (cm or mm)?",
            },
            {
                "present_if": [
                    r"\bfit\b", r"\bunfit\b", r"\bcardiac\b", r"\bpulmonary\b",
                    r"\brenal\s+(?:function|failure|impair)\b", r"\bcomorbid\b",
                    r"surgical\s+risk", r"\basa\s+class\b", r"\bfitness\b",
                ],
                "question": (
                    "**Patient fitness**: Any significant comorbidities (cardiac, renal, pulmonary) "
                    "affecting surgical/endovascular risk?"
                ),
            },
        ],
        "min_absent": 2,
    },
    {
        "id": "type_b_dissection",
        "suggested_guidelines": ["descending_thoracic_aorta", "aortic_arch"],
        "detect": [
            r"type\s*b\s*(aortic\s*)?dissect",
            r"\btbad\b",
            r"dissect.{0,30}type\s+b",
            r"aortic\s+dissect.{0,40}(?:descending|type\s*b)",
        ],
        "categories": [
            {
                "present_if": [
                    r"complicated", r"uncomplicated", r"malperfusion",
                    r"rupture", r"expansion", r"refractory",
                    r"organ\s+isch", r"limb\s+isch", r"visceral\s+isch",
                    r"true\s+lumen\s+compres", r"false\s+lumen",
                    r"\btevar\b",
                ],
                "question": (
                    "**Complicated vs uncomplicated**: Is this complicated (malperfusion, "
                    "impending rupture, refractory hypertension/pain, rapid expansion) "
                    "or uncomplicated type B dissection?"
                ),
            },
            {
                "present_if": [
                    r"\bacute\b", r"\bsubacute\b", r"\bchronic\b",
                    r"\d+\s*days?\b", r"\d+\s*weeks?\b", r"\d+\s*months?\b",
                    r"within\s+\d+", r"onset",
                ],
                "question": (
                    "**Phase**: Is this acute (<14 days), subacute (14–90 days), "
                    "or chronic (>90 days) from symptom onset?"
                ),
            },
        ],
        "min_absent": 2,
    },
    {
        "id": "ali",
        "suggested_guidelines": ["acute_limb_ischaemia"],
        "detect": [
            r"\bali\b",
            r"acute\s+limb\s+isch",
            r"acute\s+(?:arterial\s+)?isch.{0,20}(?:limb|leg|arm|extremit)",
            r"sudden\s+(?:limb|leg|arm).{0,20}(?:pain|pale|puls)",
        ],
        "categories": [
            {
                "present_if": [
                    r"\brutherford\b", r"class\s+[123i]\b", r"grade\s+[123i]\b",
                    r"\bviable\b", r"\bthreatened\b", r"\birreversible\b",
                    r"motor\s+defic", r"sensory\s+loss", r"motor\s+loss",
                    r"\d+\s*hours?\b", r"duration",
                ],
                "question": (
                    "**Severity**: Rutherford class (viable / marginally threatened / "
                    "immediately threatened / irreversible)? Motor or sensory deficits? "
                    "Duration of ischaemia (hours)?"
                ),
            },
            {
                "present_if": [
                    r"\bemboli\b", r"\bthrombos\b", r"embolic", r"in.situ",
                    r"graft\s+occl", r"native.*thromb",
                    r"prior\s+(?:bypass|graft|stent)",
                    r"known\s+pad", r"\baf\b", r"atrial\s+fibril", r"cardiac\s+source",
                ],
                "question": (
                    "**Aetiology**: Suspected thrombotic (in-situ, known PAD/prior bypass) "
                    "or embolic (AF, cardiac source, no prior arterial disease)?"
                ),
            },
        ],
        "min_absent": 2,
    },
    {
        "id": "dvt_pe",
        "suggested_guidelines": ["venous_thrombosis"],
        "detect": [
            r"\bdvt\b",
            r"deep\s+vein\s+thrombosis",
            r"\bpulmonary\s+embol",
            r"\bvte\b",
            r"venous\s+thromboembol",
        ],
        "exclude_if": [
            r"\bno\s+(?:confirmed\s+)?dvt\b",
            r"\bwithout\s+dvt\b",
            r"\bno\s+deep\s+vein\s+thrombosis\b",
            r"\bno\s+(?:venous\s+thromboembol(?:ism)?|vte)\b",
        ],
        "categories": [
            {
                "present_if": [
                    r"\bprovoked\b", r"\bunprovoked\b", r"\bidiopathic\b",
                    r"\bcancer\b", r"\bmalignancy\b", r"\bimmob\b",
                    r"\btravel\b", r"surgery.{0,15}related",
                    r"\bthrombophilia\b", r"\bhereditary\b",
                ],
                "question": (
                    "**Provoking factors**: Is this provoked (recent surgery, active malignancy, "
                    "immobility, OCP) or unprovoked DVT/PE?"
                ),
            },
            {
                "present_if": [
                    r"prior\s+vte", r"first\s+episode", r"recurrent",
                    r"second\s+episode", r"history\s+of\s+(?:dvt|pe|vte)",
                    r"previous\s+(?:dvt|pe|vte|thrombosis)",
                ],
                "question": "**Prior VTE history**: Is this a first episode or recurrent VTE?",
            },
        ],
        "min_absent": 2,
    },
    {
        "id": "clti",
        "suggested_guidelines": ["clti"],
        "detect": [
            r"\bclti\b",
            r"chronic\s+limb.{0,20}threat",
            r"critical\s+limb",
            r"\brest\s+pain\b",
            r"\btissue\s+loss\b",
            r"\bgangrene\b",
        ],
        "categories": [
            {
                "present_if": [
                    r"\brutherford\b", r"\bwifi\b", r"\babi\b",
                    r"ankle.{0,20}brachial", r"\bduplex\b", r"\bcta\b",
                    r"vascular\s+anatomy", r"runoff",
                ],
                "question": (
                    "**Anatomical workup**: Is vascular anatomy known (duplex/CTA runoff)? "
                    "Any available ABI or Rutherford/WIfI classification?"
                ),
            },
            {
                "present_if": [
                    r"\bfit\b", r"\bunfit\b", r"\bdialysis\b", r"\brenal\b",
                    r"\bcardiac\b", r"\bcomorbid\b", r"surgical\s+risk",
                    r"life\s+expectancy", r"\bfrail\b",
                ],
                "question": (
                    "**Patient fitness and life expectancy**: Any comorbidities or frailty "
                    "that influence the choice between revascularisation, minor amputation, "
                    "or primary major amputation?"
                ),
            },
        ],
        "min_absent": 2,
    },
    {
        "id": "graft_infection",
        "suggested_guidelines": ["vascular_graft_infections"],
        "detect": [
            r"graft\s+infect",
            r"endograft\s+infect",
            r"prosth.{0,10}infect",
            r"infect.{0,20}(?:graft|bypass|endograft)",
            r"infected\s+(?:graft|bypass)",
        ],
        "categories": [
            {
                "present_if": [
                    r"fever", r"sepsis", r"\bcrp\b", r"wbc", r"leukocyt",
                    r"wound\s+break", r"abscess", r"sinus\s+tract",
                    r"fistul", r"haemorrhag", r"anastomot",
                    r"ct\s+(?:scan|angio|imaging)", r"fdg", r"pet",
                ],
                "question": (
                    "**Clinical presentation**: Systemic signs (fever, sepsis, raised CRP/WBC)? "
                    "Local signs (wound breakdown, abscess, sinus tract, anastomotic haemorrhage)? "
                    "CT/PET findings?"
                ),
            },
            {
                "present_if": [
                    r"days?", r"weeks?", r"months?", r"years?", r"early", r"late",
                    r"time\s+(?:since|after|from)", r"post.{0,10}op",
                    r"dacron", r"ptfe", r"polyest", r"prosth",
                    r"aortic", r"femoral", r"bypass\s+type",
                ],
                "question": (
                    "**Prosthesis and timing**: Type of graft/bypass (aortic, peripheral, "
                    "PTFE, Dacron)? Time from original implant to presentation of infection?"
                ),
            },
        ],
        "min_absent": 2,
    },
]

# ─── Gate helper functions ─────────────────────────────────────────────────────

def _is_knowledge_question(question: str, history: list[str]) -> bool:
    """Return True if this is a raw guideline knowledge question (not a patient case)."""
    q = question.strip()
    if not q:
        return True
    # Specific patient cases take priority
    if _PATIENT_CASE_RE.search(q):
        return False
    for item in history:
        if _PATIENT_CASE_RE.search(item):
            return False
    # Population-level or definitional questions
    if _GENERIC_PATIENT_POPULATION_RE.search(q):
        return True
    return bool(_RAW_GUIDELINE_KNOWLEDGE_RE.search(q))


def _is_patient_case(question: str, history: list[str]) -> bool:
    """Return True if question or history contains patient-case language."""
    if _PATIENT_CASE_RE.search(question):
        return True
    return any(_PATIENT_CASE_RE.search(item) for item in history)


def _check_context_gaps(
    question: str, history: list[str]
) -> tuple[str, list[str], list[str]]:
    """
    Run the clinical context gate against all 7 scenario rules.
    Returns (scenario_id, [clarification_questions], [suggested_guidelines]) if gaps found,
    else ('', [], []).
    """
    full_text = (question + " " + " ".join(history)).lower()

    for rule in _CONTEXT_GAP_RULES:
        if not any(re.search(p, full_text) for p in rule["detect"]):
            continue
        if any(re.search(p, full_text) for p in rule.get("exclude_if", [])):
            continue

        absent = [
            cat["question"]
            for cat in rule["categories"]
            if not any(re.search(p, full_text) for p in cat["present_if"])
        ]

        if len(absent) >= rule["min_absent"]:
            return rule["id"], absent, rule.get("suggested_guidelines", [])

    return "", [], []


# ─── Agent-mode formatters ─────────────────────────────────────────────────────

def _map_recommendation(chunk: dict) -> dict:
    return {
        "rec_id":    chunk.get("rec_id") or chunk.get("recommendation_id"),
        "statement": chunk.get("recommendation") or chunk.get("content", ""),
        "class":     chunk.get("class") or chunk.get("recommendation_class", ""),
        "level":     chunk.get("level") or chunk.get("evidence_level") or chunk.get("grade", ""),
        "guideline": chunk.get("guideline") or chunk.get("document_name", ""),
    }


def _derive_confidence(retrieval_mode: str, n_recs: int) -> str:
    if retrieval_mode == "full" and n_recs >= 3:
        return "high"
    elif n_recs >= 1:
        return "medium"
    else:
        return "low"


def _format_consult_narrative(data: dict, query: str) -> str:
    """Return the pre-formatted Markdown from Laravel (OpenWebUI narrative mode)."""
    output_text = data.get("result") or data.get("llm_output") or data.get("output") or ""
    if output_text:
        return str(output_text)
    # Fallback: summarise chunk counts
    n_narr = len(data.get("narrative_chunks", []))
    n_cite = len(data.get("citation_chunks", []))
    guidelines_used = data.get("selected_guidelines", [])
    return (
        f"Retrieved {n_narr} narrative and {n_cite} citation chunks "
        f"from: {', '.join(guidelines_used) or 'auto-selected'}."
    )


def _format_consult_agent(data: dict, query: str, history: list) -> str:
    """Return structured JSON string for agent/Codex clients."""
    norm       = data.get("query_normalization") or {}
    citations  = data.get("citation_chunks") or []
    narrative  = data.get("narrative_chunks") or []
    assets     = data.get("assets") or []
    _gl_raw  = data.get("selected_guidelines") or []
    guidelines = list(_gl_raw.keys()) if isinstance(_gl_raw, dict) else list(_gl_raw)
    llm_out    = data.get("result") or data.get("llm_output") or data.get("output") or ""

    recs           = [_map_recommendation(c) for c in citations]
    retrieval_mode = "lean" if len(recs) <= 3 else "full"
    question_type  = "patient_case" if history else "knowledge"

    answer = ""
    if llm_out:
        answer = llm_out.split("\n")[0].strip()
    elif narrative:
        answer = narrative[0].get("content", "").strip()[:300]

    result = {
        "query_normalized":     norm.get("normalized_query", query),
        "question_type":        question_type,
        "guidelines_used":      guidelines,
        "retrieval_mode":       retrieval_mode,
        "answer":               answer,
        "recommendations":      recs,
        "supporting_narrative": [
            {"section": c.get("document_name", ""), "text": c.get("content", "")}
            for c in narrative
        ],
        "figures_or_tables": [
            {
                "type":    a.get("type", "figure"),
                "label":   a.get("label", ""),
                "caption": a.get("caption", ""),
                "url":     a.get("url"),
            }
            for a in assets
        ],
        "confidence":              _derive_confidence(retrieval_mode, len(recs)),
        "needs_clinical_judgment": question_type == "patient_case",
    }
    return json.dumps(result, indent=2, ensure_ascii=False)


# ─── FastMCP server ────────────────────────────────────────────────────────────

def _make_server(host: str = "0.0.0.0", port: int = 8080) -> FastMCP:
    return FastMCP(
        "vascular_mcp",
        host=host,
        port=port,
        stateless_http=True,        # allow per-request calls without session management
        streamable_http_path="/",   # serve at root; Caddy strips /mcp prefix before forwarding
        instructions=(
            "This server provides access to ESVS (European Society for Vascular Surgery) "
            "clinical guideline evidence via a RAGFlow-backed retrieval system. "
            "For patient-case questions: call vascular_assess_context_gaps first, then "
            "vascular_consult_guidelines if status is PROCEED. "
            "For knowledge questions (definitions, thresholds, classifications): call "
            "vascular_consult_guidelines directly. "
            "Use vascular_list_guidelines to discover available dataset keys."
        ),
    )


# Parse transport/port from argv early so the FastMCP instance is constructed
# with the correct host/port before the @mcp.tool decorators run.
# Guard against non-integer argv[2] when imported by pytest.
_transport = sys.argv[1] if len(sys.argv) > 1 else "stdio"
try:
    _port = int(sys.argv[2]) if len(sys.argv) > 2 else 8080
except ValueError:
    _port = 8080
mcp = _make_server(host="0.0.0.0", port=_port)


@mcp.tool(
    description=(
        "Retrieve ESVS guideline evidence for a vascular surgery query. "
        "Returns structured clinical evidence including graded recommendations, "
        "narrative context, and optional figures/tables. "
        "For patient-case questions call vascular_assess_context_gaps first; "
        "for knowledge questions call this tool directly."
    ),
    annotations={"readOnlyHint": True},
)
async def vascular_consult_guidelines(
    query: str,
    guidelines: list[str] | None = None,
    history: list[str] | None = None,
    response_mode: str = "narrative",
) -> str:
    """
    Query ESVS guidelines and return evidence chunks.

    Args:
        query:         The clinical question or case description.
        guidelines:    Optional list of guideline keys to restrict retrieval.
                       If omitted, the backend router selects automatically.
                       Use vascular_list_guidelines to see available keys.
        history:       Optional list of prior conversation turns (plain strings).
        response_mode: 'narrative' returns formatted Markdown for human-readable
                       interfaces (default). 'agent' returns structured JSON with
                       explicit fields for agent reasoning.
    """
    query = (query or "").strip()
    if not query:
        return "Error: query cannot be empty."

    # Normalise and validate response_mode; unknown values fall back to narrative
    try:
        mode = ResponseMode(response_mode)
    except ValueError:
        mode = ResponseMode.NARRATIVE

    payload: dict[str, Any] = {
        "question": query,
        "history":  [str(h) for h in (history or [])],
    }
    if guidelines:
        payload["guidelines"] = [str(g) for g in guidelines]

    headers = {
        "Authorization": f"Bearer {LARAVEL_API_KEY}",
        "Content-Type":  "application/json",
        "Accept":        "application/json",
    }

    try:
        async with httpx.AsyncClient(timeout=LARAVEL_TIMEOUT) as client:
            resp = await client.post(
                f"{LARAVEL_BASE_URL}/api/v1/vascular-consult",
                json=payload,
                headers=headers,
            )
    except httpx.TimeoutException:
        return (
            "Error: Request to Laravel API timed out (>120s). "
            "Try a more specific query or fewer guidelines."
        )
    except httpx.RequestError as exc:
        return f"Error: Could not reach Laravel API — {exc}"

    if resp.status_code == 401:
        return "Error: Invalid API key. Check LARAVEL_API_KEY in .env."
    if resp.status_code == 422:
        return f"Error: Validation failed — {resp.text[:300]}"
    if resp.status_code == 429:
        return "Error: Rate limit exceeded (60 req/min). Please wait and retry."
    if resp.status_code >= 400:
        return f"Error: Laravel API returned HTTP {resp.status_code}."

    try:
        data = resp.json()
    except Exception:
        return f"Error: Non-JSON response from Laravel API (status {resp.status_code})."

    if mode == ResponseMode.AGENT:
        return _format_consult_agent(data, query, history or [])

    return _format_consult_narrative(data, query)


@mcp.tool(
    description=(
        "Check whether a patient-case question has sufficient clinical context "
        "for ESVS guideline retrieval. Returns status PROCEED (context adequate) "
        "or NEEDS_CLARIFICATION with specific questions to ask the user. "
        "Knowledge questions (definitions, thresholds, population-level guidelines) "
        "always return PROCEED without going through the gate."
    ),
    annotations={"readOnlyHint": True},
)
async def vascular_assess_context_gaps(
    question: str,
    history: list[str] | None = None,
) -> str:
    """
    Assess whether a clinical question has enough context for guideline retrieval.

    Args:
        question: The user's question or case description.
        history:  Optional list of prior conversation turns (plain strings).
                  Include prior user + assistant turns so the gate can detect
                  that context was already provided in an earlier turn.

    Returns a JSON string with:
        status:                 "PROCEED" | "NEEDS_CLARIFICATION"
        scenario:               matched scenario id, or null
        missing_parameters:     list of clarification questions (on NEEDS_CLARIFICATION)
        clarification_question: combined question string to present to the user, or null
        suggested_guidelines:   guideline IDs relevant to this scenario
    """
    q = (question or "").strip()
    h = [str(x) for x in (history or []) if x]

    # Knowledge questions bypass the gate
    if _is_knowledge_question(q, h):
        return json.dumps({
            "status":                 "PROCEED",
            "reason":                 "knowledge_question",
            "scenario":               None,
            "missing_parameters":     [],
            "clarification_question": None,
            "suggested_guidelines":   [],
        })

    # No patient-case language detected — proceed
    if not _is_patient_case(q, h):
        return json.dumps({
            "status":                 "PROCEED",
            "reason":                 "not_a_patient_case",
            "scenario":               None,
            "missing_parameters":     [],
            "clarification_question": None,
            "suggested_guidelines":   [],
        })

    # Follow-up heuristic: a prior history item that is a long assistant response
    # indicates the gate already fired and the user replied with more context.
    has_prior_assistant_response = any(len(x) > 300 for x in h)
    if has_prior_assistant_response:
        return json.dumps({
            "status":                 "PROCEED",
            "reason":                 "follow_up_turn",
            "scenario":               None,
            "missing_parameters":     [],
            "clarification_question": None,
            "suggested_guidelines":   [],
        })

    scenario_id, questions, suggested = _check_context_gaps(q, h)

    if scenario_id and questions:
        return json.dumps({
            "status":                 "NEEDS_CLARIFICATION",
            "scenario":               scenario_id,
            "missing_parameters":     questions,
            "clarification_questions": questions,           # backward-compat alias
            "clarification_question": "\n\n".join(questions),
            "suggested_guidelines":   suggested,
        })

    return json.dumps({
        "status":                 "PROCEED",
        "reason":                 "sufficient_context",
        "scenario":               None,
        "missing_parameters":     [],
        "clarification_question": None,
        "suggested_guidelines":   [],
    })


@mcp.tool(
    description=(
        "List all available ESVS guideline datasets with their keys and human-readable names. "
        "Pass these keys in the guidelines parameter of vascular_consult_guidelines "
        "to restrict retrieval to specific datasets."
    ),
    annotations={"readOnlyHint": True},
)
async def vascular_list_guidelines() -> str:
    """Return all available ESVS guideline dataset keys and names as structured JSON."""
    guidelines = [
        {
            "id":          key,
            "label":       label,
            "has_assets":  _GUIDELINE_HAS_ASSETS[key],
            "description": _GUIDELINE_DESCRIPTIONS[key],
        }
        for key, label in GUIDELINE_NAMES.items()
    ]
    return json.dumps({"guidelines": guidelines}, indent=2, ensure_ascii=False)


# ─── Entry point ───────────────────────────────────────────────────────────────

if __name__ == "__main__":
    if _transport == "streamable_http":
        mcp.run(transport="streamable-http")
    elif _transport == "sse":
        mcp.run(transport="sse")
    else:
        mcp.run(transport="stdio")
