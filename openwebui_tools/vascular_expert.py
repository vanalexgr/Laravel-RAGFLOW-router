"""
title: Vascular Expert Tools
author: open-webui
author_url: https://github.com/open-webui
funding_url: https://github.com/open-webui
version: 2.1.10
"""

import httpx
import asyncio
import os
import time
import base64
import io
from pydantic import BaseModel, Field
from typing import Literal, Optional, Callable, Awaitable
import re
import html
import zipfile


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
    ATTACHMENT_MAX_BYTES = 512 * 1024
    ATTACHMENT_MAX_FILES = 2
    ATTACHMENT_HISTORY_MAX_CHARS = 1500
    ATTACHMENT_PROMPT_MAX_CHARS = 1200
    ATTACHMENT_QUESTION_MAX_CHARS = 5000
    ATTACHMENT_SKIP_GENERIC_GATE_CHARS = 250
    CASE_STATE_MAX_CONTEXT_ITEMS = 4
    CASE_STATE_MAX_REFERENCE_ITEMS = 4
    CASE_STATE_MAX_QUERY_CHARS = 1600
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

    # Detects specific patient-case language (not generic population questions).
    _PATIENT_CASE_RE = re.compile(
        r"\b(my\s+patient|this\s+patient|the\s+patient|"
        r"patient\s+(?:with|who|has)|pt\s+(?:with|who|has)|"
        r"case\s+of|this\s+case|the\s+case|"
        r"\d{1,3}\s*(?:year[- ]old|yo)\b|"
        r"(?:male|female|man|woman)\s+with|"
        r"presents?\s+with|presented\s+with|admitted\s+with|referred\s+with|"
        r"was\s+found|incidentally|ασθεν|ετ[ωώ]ν)\b",
        re.IGNORECASE,
    )

    _FRESH_CASE_INTRO_RE = re.compile(
        r"\b(my\s+patient|patient\s+(?:with|who|has)|pt\s+(?:with|who|has)|"
        r"case\s+of|"
        r"\d{1,3}\s*(?:year[- ]old|yo)\b|"
        r"(?:male|female|man|woman)\s+with|"
        r"presents?\s+with|presented\s+with|admitted\s+with|referred\s+with|"
        r"was\s+found|incidentally|ασθεν|ετ[ωώ]ν)\b",
        re.IGNORECASE,
    )

    # Distinguish raw guideline-knowledge questions from case-specific consultations.
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

    _CASE_GATE_ASSISTANT_RE = re.compile(
        r"\b(to answer this case accurately,\s*i need a few more details|"
        r"i need a few more details|"
        r"before i (?:can\s+)?(?:retrieve|search|access|check|look up|pull)(?:\s+the)?\s+(?:relevant\s+)?(?:esvs\s+)?guidelines|"
        r"before (?:retrieving|searching|accessing|checking)\s+(?:the\s+)?(?:relevant\s+)?(?:esvs\s+)?guidelines|"
        r"to\s+(?:retrieve|search|access)\s+(?:the\s+)?(?:right|relevant|correct|appropriate)\s+guidelines)\b",
        re.IGNORECASE,
    )

    _EXPLICIT_NEW_CASE_RE = re.compile(
        r"\b(another|different|new|separate|next)\s+(?:patient|case)\b|"
        r"\bfor\s+a\s+different\s+patient\b",
        re.IGNORECASE,
    )

    _NUMBERED_ITEM_RE = re.compile(r"^\s*(\d+)[\.\)]\s+(.*\S)\s*$")
    _ASSISTANT_GUIDELINE_RE = re.compile(r"Selecting guidelines:\s*(.+)", re.IGNORECASE)
    _RECOMMENDATION_REF_RE = re.compile(r"\bRec(?:ommendation)?\s*\.?\s*(\d+)\b", re.IGNORECASE)
    _FOLLOW_UP_CUE_RE = re.compile(
        r"^(what\s+about|what\s+if|how\s+about|and|but|so|then|if|for\s+this\s+case|"
        r"in\s+this\s+case|for\s+this\s+patient|in\s+this\s+patient)\b",
        re.IGNORECASE,
    )

    _ANTITHROMBOTIC_DECISION_RE = re.compile(
        r"\b(antithrombotic|anticoag(?:ulation|ulant|ulate)?|antiplatelet|"
        r"dual\s+antiplatelet|single\s+antiplatelet|dapt|sapt|dual\s+pathway|"
        r"aspirin|clopidogrel|ticagrelor|prasugrel|warfarin|vka|doac|"
        r"apixaban|rivaroxaban|dabigatran|edoxaban|heparin|lmwh|fondaparinux|"
        r"bridge|bridging|bleed(?:ing)?|haemorrhag\w*|hemorrhag\w*)\b",
        re.IGNORECASE,
    )

    _THROMBUS_ANTITHROMBOTIC_RE = re.compile(
        r"\b(aortic\s+mural\s+thrombus|mural\s+thrombus|aortic\s+thrombus|"
        r"free[-\s]*floating\s+thrombus)\b",
        re.IGNORECASE,
    )

    _CAROTID_SEVERE_STROKE_RE = re.compile(
        r"\b(major\s+(?:ischaemic\s+|ischemic\s+)?stroke|"
        r"disabling\s+(?:ischaemic\s+|ischemic\s+)?stroke|"
        r"major\s+disabling\s+stroke|severe\s+stroke|large\s+infarct(?:ion)?|"
        r"(?:modified\s+)?rankin(?:\s+scale)?|mrs\b|"
        r"(?:hasn'?t|has\s+not|not)\s+yet\s+mobili[sz]ed|"
        r"unable\s+to\s+mobili[sz]e|dense\s+neurological\s+deficit)\b",
        re.IGNORECASE,
    )

    # Scenarios that commonly need extra clinical parameters for a useful guideline answer.
    # Each category within a scenario has synonyms (any match = parameter present).
    # If >= min_absent categories are absent from question+history, ask for clarification.
    _CONTEXT_GAP_RULES = [
        {
            "id": "aortic_thrombus",
            "detect": [
                r"\bmural\s+thrombus\b", r"\baortic\s+thrombus\b",
                r"\bthrombus\b.{0,60}\baort", r"\baort.{0,60}\bthrombus\b",
            ],
            "categories": [
                {
                    "present_if": [
                        r"\banticoag", r"\bwarfarin\b", r"\bdoac\b", r"\bheparin\b",
                        r"\bantiplatelet\b", r"\baspirin\b", r"not\s+on\b",
                        r"no\s+(?:anticoag|treatment|therapy|medication)",
                        r"contraindic.{0,30}anticoag",
                    ],
                    "question": "**Antithrombotic/anticoagulation status**: Is the patient currently on anticoagulation or antiplatelet therapy? Are there contraindications to anticoagulation (e.g., recent haemorrhagic stroke, active bleeding)?",
                },
                {
                    "present_if": [
                        r"\bmobile\b", r"\bsessile\b", r"\bpedunculated\b",
                        r"\bprotruding\b", r"\badherent\b", r"\battached\b",
                        r"\bfloating\b", r"\bfixed\b",
                    ],
                    "question": "**Thrombus morphology**: Is the thrombus mobile/pedunculated (higher embolic risk) or sessile/mural (adherent to the wall)?",
                },
                {
                    "present_if": [
                        r"\bembolic\s+source\b", r"cause\s+of\s+(?:the\s+)?stroke\b",
                        r"\bincidental\b", r"\b(?:af|atrial\s+fibril)\b",
                        r"\bcardiac\s+(?:source|echo|workup)\b",
                        r"\bsource\s+(?:identified|found|excluded)\b",
                        r"\bworkup\s+complete\b",
                    ],
                    "question": "**Stroke aetiology workup**: Is the aortic thrombus the presumed embolic source of the stroke, or has a cardiac source (AF, intracardiac thrombus) been evaluated or excluded?",
                },
            ],
            "min_absent": 2,
        },
        {
            "id": "carotid_stenosis",
            "detect": [
                r"\bcarotid\s+(?:artery\s+)?stenosis\b", r"\bcea\b",
                r"\bcarotid\s+endarterectomy\b", r"\bcarotid\s+stenting\b",
            ],
            "categories": [
                {
                    "present_if": [
                        r"\bsymptomatic\b", r"\basymptomatic\b", r"\btia\b",
                        r"\btransient\s+ischaem\b", r"\bamaurosis\b",
                        r"recent\s+stroke", r"neurological\s+symptom",
                    ],
                    "question": "**Symptomatic status**: Is this symptomatic (recent TIA, stroke, or amaurosis fugax within 6 months) or asymptomatic carotid stenosis?",
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
            "detect": [
                r"\baaa\b", r"\babdominal\s+aortic\s+aneurysm\b",
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
                    "question": "**Patient fitness**: Any significant comorbidities (cardiac, renal, pulmonary) affecting surgical/endovascular risk?",
                },
            ],
            "min_absent": 2,
        },
        {
            "id": "dvt_pe",
            "detect": [
                r"\bdvt\b", r"deep\s+vein\s+thrombosis",
                r"\bpe\b.{0,20}(?:pulmonary|lung)",
                r"\bpulmonary\s+embol", r"\bvte\b", r"venous\s+thromboembol",
                r"\buedvt\b", r"upper.{0,20}extremity.{0,20}(?:dvt|thrombosis)",
                r"\b(brachial|subclavian|axillary|cephalic|basilic|arm).{0,30}(?:vein\s+)?thrombos",
                r"thrombos.{0,30}\b(brachial|subclavian|axillary)\s+vein",
                # UEDVT without explicit thrombosis word: named vein + anticoag/treatment context
                r"\b(brachial|subclavian|axillary|cephalic|basilic)\s+vein\b.{0,80}\b(anticoag|heparin|lmwh|doac|treat)",
                r"\b(anticoag|heparin|lmwh|doac)\b.{0,80}\b(brachial|subclavian|axillary|cephalic|basilic)\s+vein\b",
                # venous compression by tumor/mass
                r"compress.{0,40}\b(brachial|subclavian|axillary|cephalic|basilic)\s+vein",
                r"\b(brachial|subclavian|axillary|cephalic|basilic)\s+vein\b.{0,40}compress",
            ],
            "exclude_if": [
                r"\bno\s+(?:confirmed\s+)?dvt\b",
                r"\bwithout\s+dvt\b",
                r"\bno\s+deep\s+vein\s+thrombosis\b",
                r"\bno\s+(?:venous\s+thromboembol(?:ism)?|vte)\b",
                r"\bno\s+(?:pulmonary\s+embol(?:ism)?|pe)\b",
            ],
            "categories": [
                {
                    "present_if": [
                        r"\bprovoked\b", r"\bunprovoked\b", r"\bidiopathic\b",
                        r"\bcancer\b", r"\bmalignancy\b", r"\bimmob\b",
                        r"\btravel\b", r"surgery.{0,15}related",
                        r"\bocp\b", r"\bcontraceptive\b",
                        r"\bthrombophilia\b", r"\bhereditary\b",
                    ],
                    "question": "**Provoking factors**: Is this provoked (recent surgery, active malignancy, immobility, OCP) or unprovoked DVT/PE?",
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
            "detect": [
                r"\bclti\b", r"chronic\s+limb.{0,20}threat", r"critical\s+limb",
                r"\brest\s+pain\b", r"\btissue\s+loss\b", r"\bgangrene\b",
            ],
            "categories": [
                {
                    "present_if": [
                        r"\brutherford\b", r"\bwifi\b", r"\babi\b",
                        r"ankle.{0,20}brachial", r"\bduplex\b", r"\bcta\b",
                        r"vascular\s+anatomy", r"runoff",
                    ],
                    "question": "**Anatomical workup**: Is vascular anatomy known (duplex/CTA runoff)? Any available ABI or Rutherford/WIfI classification?",
                },
                {
                    "present_if": [
                        r"\bfit\b", r"\bunfit\b", r"\bdialysis\b", r"\brenal\b",
                        r"\bcardiac\b", r"\bcomorbid\b", r"surgical\s+risk",
                        r"life\s+expectancy", r"\bfrail\b",
                    ],
                    "question": "**Patient fitness and life expectancy**: Any comorbidities or frailty that influence the choice between revascularisation, minor amputation, or primary major amputation?",
                },
            ],
            "min_absent": 2,
        },
        {
            "id": "svt",
            "detect": [
                r"\bsvt\b", r"saphenous.*thrombos", r"thrombos.*saphenous",
                r"superficial.*vein.*thrombos", r"superficial.*thrombophlebit",
                r"\bthrombophlebit\b",
                # Greek
                r"σαφην.*θρόμβ", r"θρόμβ.*σαφην", r"μείζον.*σαφην",
            ],
            "categories": [
                {
                    "present_if": [
                        r"\bcm\b", r"\bmm\b", r"\bsfj\b", r"\bspj\b",
                        r"junction", r"saphenofemoral", r"saphenopopliteal",
                        r"distance", r"\d+\s*cm", r"proximal", r"distal",
                        r"extension\s+to", r"above\s+the", r"below\s+the",
                        r"from\s+the\s+(?:sfj|spj|junction)",
                    ],
                    "question": "**Distance from junction**: How far is the proximal thrombus end from the saphenofemoral (or saphenopopliteal for SSV) junction? This is the primary treatment determinant (e.g., <3 cm, 3–10 cm, >10 cm).",
                },
                {
                    "present_if": [
                        r"\bcancer\b", r"\bmalignan\b", r"\boncol\b",
                        r"\bthrombophilia\b", r"\bleiden\b", r"factor\s+v",
                        r"antiphospholipid", r"prior\s+vte", r"previous\s+dvt",
                        r"\bimmob\b", r"\bbilateral\b", r"extensive\s+svt",
                        r"without\s+varicose", r"absent\s+varicose", r"no\s+varicose",
                        r"\brecurrent\b",
                    ],
                    "question": "**Risk stratification**: Are high-risk features present (active cancer, prior DVT/PE, thrombophilia, absence of varicose veins, bilateral or extensive SVT)?",
                },
            ],
            "min_absent": 2,
        },
        {
            "id": "type_b_dissection",
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
                    "question": "**Complicated vs uncomplicated**: Is this complicated (malperfusion, impending rupture, refractory hypertension/pain, rapid expansion) or uncomplicated type B dissection?",
                },
                {
                    "present_if": [
                        r"\bacute\b", r"\bsubacute\b", r"\bchronic\b",
                        r"\d+\s*days?\b", r"\d+\s*weeks?\b", r"\d+\s*months?\b",
                        r"within\s+\d+", r"onset",
                    ],
                    "question": "**Phase**: Is this acute (<14 days), subacute (14–90 days), or chronic (>90 days) from symptom onset?",
                },
            ],
            "min_absent": 2,
        },
        {
            "id": "ali",
            "detect": [
                r"\bali\b", r"acute\s+limb\s+isch",
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
                    "question": "**Severity**: Rutherford class (viable / marginally threatened / immediately threatened / irreversible)? Motor or sensory deficits? Duration of ischaemia (hours)?",
                },
                {
                    "present_if": [
                        r"\bemboli\b", r"\bthrombos\b", r"embolic", r"in.situ",
                        r"graft\s+occl", r"native.*thromb", r"prior\s+(?:bypass|graft|stent)",
                        r"known\s+pad", r"\baf\b", r"atrial\s+fibril", r"cardiac\s+source",
                    ],
                    "question": "**Aetiology**: Suspected thrombotic (in-situ, known PAD/prior bypass) or embolic (AF, cardiac source, no prior arterial disease)?",
                },
            ],
            "min_absent": 2,
        },
        {
            "id": "graft_infection",
            "detect": [
                r"graft\s+infect", r"endograft\s+infect", r"prosth.{0,10}infect",
                r"infect.{0,20}(?:graft|bypass|endograft)", r"infected\s+(?:graft|bypass)",
                # Aorto-enteric / aorto-oesophageal / aortobronchial fistula presentations
                r"aorto.{0,12}(?:oesophageal|esophageal|enteric|bronchial)\s+fistul",
                r"(?:oesophageal|esophageal|enteric|bronchial).{0,20}fistul",
                r"haematemesis.{0,80}(?:tevar|endograft|aort|graft)",
                r"(?:tevar|endograft|aort|graft).{0,80}haematemesis",
                # Contaminated surgical field after aortic repair
                r"(?:tevar|endograft|aort).{0,60}(?:oesophag|esophag|bronch|tracheo).{0,30}fistul",
            ],
            "categories": [
                {
                    "present_if": [
                        r"fever", r"sepsis", r"\bcrp\b", r"wbc", r"leukocyt",
                        r"wound\s+break", r"abscess", r"sinus\s+tract",
                        r"fistul", r"haemorrhag", r"anastomot",
                        r"ct\s+(?:scan|angio|imaging)", r"fdg", r"pet",
                    ],
                    "question": "**Clinical presentation**: Systemic signs (fever, sepsis, raised CRP/WBC)? Local signs (wound breakdown, abscess, sinus tract, anastomotic haemorrhage)? CT/PET findings?",
                },
                {
                    "present_if": [
                        r"days?", r"weeks?", r"months?", r"years?", r"early", r"late",
                        r"time\s+(?:since|after|from)", r"post.{0,10}op",
                        r"dacron", r"ptfe", r"polyest", r"prosth",
                        r"aortic", r"femoral", r"bypass\s+type",
                    ],
                    "question": "**Prosthesis and timing**: Type of graft/bypass (aortic, peripheral, PTFE, Dacron)? Time from original implant to presentation of infection?",
                },
            ],
            "min_absent": 2,
        },
    ]

    # Clinical detail markers — presence of any of these suggests sufficient context
    _CLINICAL_DETAIL_RE = re.compile(
        r"\b(\d+|symptomatic|asymptomatic|acute|chronic|bilateral|unilateral|"
        r"anticoag|aspirin|warfarin|doac|cancer|malignancy|prior|previous|"
        r"risk|recurrent|complicated|uncomplicated|mobile|sessile|"
        r"cm\b|mm\b|provoked|unprovoked|fit\b|unfit)\b",
        re.IGNORECASE,
    )

    def __init__(self):
        self.valves = self.Valves()
        self._last_status_text = ""
        self._last_status_ts = 0.0

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

    def _assess_context_gaps(self, question: str, history: list) -> tuple:
        """Check if a patient-case question is missing key clinical parameters.

        Returns (scenario_id, [question_strings]) if gaps were found, else ('', []).
        Known scenarios use targeted follow-up questions; all other patient-case
        consultations always fall back to a generic clinical-context bundle
        before retrieval.
        """
        if not self._should_request_case_follow_up(question, history):
            return "", []

        full_text = (question + " " + " ".join(history or [])).lower()

        for rule in self._CONTEXT_GAP_RULES:
            if not any(re.search(p, full_text) for p in rule["detect"]):
                continue
            if any(re.search(p, full_text) for p in rule.get("exclude_if", [])):
                continue

            absent = []
            for cat in rule["categories"]:
                present = any(re.search(p, full_text) for p in cat["present_if"])
                if not present:
                    absent.append(cat["question"])

            if len(absent) >= rule["min_absent"]:
                return rule["id"], absent

        # Expand follow-up collection to all patient-specific consultations, even when
        # a specific scenario rule does not identify enough missing fields on its own.
        generic_questions = self._generic_case_follow_up_questions(full_text)
        if generic_questions:
            return "generic_case", generic_questions

        return "", []

    def _is_raw_guideline_knowledge_query(self, question: str, history: Optional[list] = None) -> bool:
        q = (question or "").strip()
        if not q:
            return False

        # Specific patient cases should never be downgraded to generic knowledge.
        if self._PATIENT_CASE_RE.search(q):
            return False

        # If any history item contains patient-case language, this is a case consultation
        # even if the LLM reformulated the question as "what does ESVS recommend for...".
        for item in history or []:
            if isinstance(item, str) and self._PATIENT_CASE_RE.search(item):
                return False

        combined = f"{q} {' '.join(history or [])}".strip()
        intent = str(self._infer_intent_profile(question, None).get("intent") or "").strip().lower()

        if self._GENERIC_PATIENT_POPULATION_RE.search(q):
            return True

        if intent in {"definition", "threshold"}:
            return True

        return bool(self._RAW_GUIDELINE_KNOWLEDGE_RE.search(combined))

    def _should_request_case_follow_up(self, question: str, history: Optional[list] = None) -> bool:
        q = (question or "").strip()
        if not q:
            return False

        if self._is_raw_guideline_knowledge_query(question, history):
            return False

        if self._PATIENT_CASE_RE.search(q):
            return True

        for item in history or []:
            if isinstance(item, str) and self._PATIENT_CASE_RE.search(item):
                return True

        return False

    def _needs_antithrombotic_companion(self, question: str) -> bool:
        q = (question or "").strip()
        if not q:
            return False

        return bool(
            self._ANTITHROMBOTIC_DECISION_RE.search(q)
            or self._THROMBUS_ANTITHROMBOTIC_RE.search(q)
        )

    def _filter_requested_guidelines(self, guidelines: list, question: str) -> list:
        filtered = [g for g in guidelines if g]
        if len(filtered) <= 1:
            return filtered

        if "antithrombotic_therapy" in filtered and not self._needs_antithrombotic_companion(question):
            filtered = [g for g in filtered if g != "antithrombotic_therapy"]

        return filtered

    def _message_text(self, message: dict) -> str:
        if not isinstance(message, dict):
            return ""

        content = message.get("content")
        if isinstance(content, str):
            return content.strip()
        if isinstance(content, list):
            parts = []
            for item in content:
                if isinstance(item, str):
                    parts.append(item.strip())
                elif isinstance(item, dict):
                    text = item.get("text")
                    if isinstance(text, str) and text.strip():
                        parts.append(text.strip())
            return "\n".join(part for part in parts if part).strip()

        return ""

    def _normalize_space(self, text: str) -> str:
        return re.sub(r"\s+", " ", (text or "").strip())

    def _truncate_case_text(self, text: str, max_chars: int = CASE_STATE_MAX_QUERY_CHARS) -> str:
        s = self._normalize_space(text)
        if len(s) <= max_chars:
            return s
        return s[: max_chars - 3].rstrip() + "..."

    def _message_snapshot(self, message: dict, attachment_limit: int = ATTACHMENT_HISTORY_MAX_CHARS) -> str:
        if not isinstance(message, dict):
            return ""

        text = self._message_text(message)
        attachment = self._extract_message_attachment_context(message, attachment_limit)
        return self._merge_attachment_into_question(text, attachment)

    def _extract_numbered_items(self, text: str) -> dict:
        items = {}
        for line in (text or "").splitlines():
            match = self._NUMBERED_ITEM_RE.match(line.strip())
            if not match:
                continue
            items[int(match.group(1))] = self._normalize_space(match.group(2))
        return items

    def _is_answer_only_turn(self, text: str) -> bool:
        content = self._normalize_space(text)
        if not content or "?" in content:
            return False

        numbered = self._extract_numbered_items(text)
        if numbered:
            raw_lines = [line.strip() for line in (text or "").splitlines() if line.strip()]
            return len(numbered) >= max(1, len(raw_lines) - 1)

        if len(content) > 120:
            return False

        return bool(re.fullmatch(
            r"(yes|no|unknown|n/?a|not sure|symptomatic|asymptomatic|"
            r"\d+(?:\.\d+)?\s*(?:%|mm|cm)?(?:\s*[a-z0-9/\-]+)?|"
            r"[a-z0-9 ,;/()=%\-]{1,120})",
            content,
            re.IGNORECASE,
        ))

    def _current_case_start_index(self, messages: Optional[list]) -> int:
        start_idx = 0
        for idx, message in enumerate(messages or []):
            if not isinstance(message, dict) or message.get("role") != "user":
                continue
            snapshot = self._message_snapshot(message)
            if self._EXPLICIT_NEW_CASE_RE.search(snapshot) or self._looks_like_fresh_case_intro(snapshot):
                start_idx = idx
        return start_idx

    def _extract_case_guidelines(self, messages: Optional[list], guidelines: Optional[list] = None) -> list:
        ordered = []
        seen = set()

        for item in guidelines or []:
            key = str(item or "").strip()
            if key and key not in seen:
                ordered.append(key)
                seen.add(key)

        for message in messages or []:
            if not isinstance(message, dict) or message.get("role") != "assistant":
                continue
            match = self._ASSISTANT_GUIDELINE_RE.search(self._message_text(message))
            if not match:
                continue
            for label in match.group(1).split(","):
                label = self._normalize_space(label)
                if not label:
                    continue
                mapped = next(
                    (key for key, name in GUIDELINE_NAMES.items() if name.lower() == label.lower()),
                    label,
                )
                if mapped not in seen:
                    ordered.append(mapped)
                    seen.add(mapped)

        return ordered

    def _extract_answered_clarifications(self, messages: Optional[list]) -> tuple[list, list]:
        gate_index = self._last_case_gate_index(messages)
        if gate_index < 0:
            return [], []

        gate_questions = self._extract_numbered_items(self._message_text((messages or [])[gate_index]))
        if not gate_questions:
            return [], []

        answers = {}
        for message in (messages or [])[gate_index + 1:]:
            if not isinstance(message, dict) or message.get("role") != "user":
                continue
            for number, answer in self._extract_numbered_items(self._message_text(message)).items():
                if answer and number not in answers:
                    answers[number] = answer

        clarified = []
        unanswered = []
        for number in sorted(gate_questions.keys()):
            question = self._normalize_space(gate_questions[number].rstrip("?"))
            answer = answers.get(number, "")
            if answer:
                clarified.append(f"{question}: {answer}")
            else:
                unanswered.append(question)

        return clarified, unanswered

    def _extract_previous_references(self, messages: Optional[list]) -> tuple[list, list]:
        recommendation_refs = []
        citation_refs = []
        seen_recommendations = set()
        seen_citations = set()

        for message in messages or []:
            if not isinstance(message, dict) or message.get("role") != "assistant":
                continue
            if self._is_case_gate_assistant_message(message):
                continue

            text = self._message_text(message)
            if not text:
                continue

            for match in self._RECOMMENDATION_REF_RE.finditer(text):
                ref = f"Rec {match.group(1)}"
                if ref not in seen_recommendations:
                    recommendation_refs.append(ref)
                    seen_recommendations.add(ref)
                if len(recommendation_refs) >= self.CASE_STATE_MAX_REFERENCE_ITEMS:
                    break

            for line in text.splitlines():
                stripped = self._normalize_space(line)
                if not re.match(r"^\[\d+\]", stripped):
                    continue
                if stripped not in seen_citations:
                    citation_refs.append(self._truncate_case_text(stripped, 180))
                    seen_citations.add(stripped)
                if len(citation_refs) >= self.CASE_STATE_MAX_REFERENCE_ITEMS:
                    break

        return recommendation_refs, citation_refs

    def _topic_from_state(self, combined_text: str, guidelines: Optional[list]) -> str:
        ordered = self._extract_case_guidelines([], guidelines)
        if ordered:
            primary = ordered[0]
            return GUIDELINE_NAMES.get(primary, primary)

        anchors = sorted(self._case_anchor_terms(combined_text))
        if anchors:
            return ", ".join(anchors)
        return "general vascular"

    def _build_conversation_state(
        self,
        question: str,
        messages: Optional[list],
        guidelines: Optional[list],
        attachment_context: str = "",
    ) -> dict:
        current_snapshot = self._merge_attachment_into_question(question, attachment_context)
        current_norm = self._normalize_space(current_snapshot)
        case_start = self._current_case_start_index(messages)
        case_messages = list((messages or [])[case_start:])

        user_contexts = []
        for message in case_messages:
            if not isinstance(message, dict) or message.get("role") != "user":
                continue
            snapshot = self._message_snapshot(message)
            if snapshot:
                user_contexts.append(snapshot)

        if user_contexts and current_norm and self._normalize_space(user_contexts[-1]) == current_norm:
            prior_user_contexts = user_contexts[:-1]
        elif len(user_contexts) == 1:
            # Only one user message in the case — it IS the current turn.
            # The LLM may have reformulated the question differently, but there is
            # nothing "prior" yet. Avoid treating the first message as prior context,
            # which would incorrectly trigger query rewriting on the first turn.
            prior_user_contexts = []
        else:
            prior_user_contexts = user_contexts

        clarified_facts, unanswered = self._extract_answered_clarifications(case_messages)
        recommendation_refs, citation_refs = self._extract_previous_references(case_messages)

        anchor_question = ""
        for item in prior_user_contexts:
            if "?" in item:
                anchor_question = self._truncate_case_text(item, 700)
                break
        if not anchor_question and prior_user_contexts:
            anchor_question = self._truncate_case_text(prior_user_contexts[0], 700)

        context_parts = []
        if anchor_question:
            context_parts.append(anchor_question)

        extra_context = []
        for item in prior_user_contexts[1:]:
            if clarified_facts and self._is_answer_only_turn(item):
                continue
            cleaned = self._truncate_case_text(item, 240)
            if cleaned and cleaned not in context_parts and cleaned not in extra_context:
                extra_context.append(cleaned)
            if len(extra_context) >= self.CASE_STATE_MAX_CONTEXT_ITEMS - 1:
                break

        for fact in clarified_facts:
            if fact not in context_parts:
                context_parts.append(fact)

        for item in extra_context:
            if item not in context_parts:
                context_parts.append(item)

        if attachment_context:
            attachment_line = self._truncate_case_text(
                f"Attached case document: {attachment_context.replace(chr(10), ' ')}",
                320,
            )
            if attachment_line not in context_parts:
                context_parts.append(attachment_line)

        context_summary = self._truncate_case_text("; ".join(part for part in context_parts if part), 1000)
        current_guidelines = self._extract_case_guidelines(case_messages, guidelines)
        topic = self._topic_from_state(" ".join(prior_user_contexts + [current_snapshot]), current_guidelines)

        return {
            "current_guidelines": current_guidelines,
            "current_topic": topic,
            "patient_problem_context": context_summary,
            "anchor_question": anchor_question,
            "clarified_facts": clarified_facts,
            "previous_recommendations": recommendation_refs[: self.CASE_STATE_MAX_REFERENCE_ITEMS],
            "previously_cited_chunks": citation_refs[: self.CASE_STATE_MAX_REFERENCE_ITEMS],
            "unanswered_subquestions": unanswered[: self.CASE_STATE_MAX_REFERENCE_ITEMS],
            "prior_user_contexts": prior_user_contexts[-self.CASE_STATE_MAX_CONTEXT_ITEMS :],
        }

    def _expand_retrieval_abbreviations(self, text: str) -> str:
        expanded = text or ""
        replacements = [
            (r"\bCEA\b", "carotid endarterectomy (CEA)"),
            (r"\bCAS\b", "carotid artery stenting (CAS)"),
            (r"\bTCAR\b", "transcarotid artery revascularisation (TCAR)"),
            (r"\bEVAR\b", "endovascular aneurysm repair (EVAR)"),
            (r"\bTEVAR\b", "thoracic endovascular aortic repair (TEVAR)"),
            (r"\bDVT\b", "deep vein thrombosis (DVT)"),
            (r"\bmRS\b", "modified Rankin score (mRS)"),
        ]
        for pattern, replacement in replacements:
            expanded = re.sub(pattern, replacement, expanded)
        return self._normalize_space(expanded)

    def _rewrite_follow_up_question(self, question: str) -> str:
        q = self._expand_retrieval_abbreviations(question)
        if not q:
            return ""

        lower = q.lower()
        if self._FOLLOW_UP_CUE_RE.search(q):
            if lower.startswith("what about "):
                return "What does ESVS recommend about " + q[11:]
            if lower.startswith("how about "):
                return "What does ESVS recommend about " + q[10:]
            if lower.startswith("what if "):
                return "If " + q[8:] + ", what does ESVS recommend?"

        q = re.sub(r"\bthis case\b", "this patient", q, flags=re.IGNORECASE)
        return q

    def _should_rewrite_for_retrieval(self, question: str, state: dict, standalone: bool) -> bool:
        if standalone:
            return False

        if self._EXPLICIT_NEW_CASE_RE.search(question or ""):
            return False

        if self._looks_like_fresh_case_intro(question or ""):
            return False

        if state.get("prior_user_contexts") or state.get("clarified_facts"):
            return True

        return False

    def _prepare_retrieval_query(
        self,
        question: str,
        effective_question: str,
        state: dict,
        standalone: bool,
    ) -> tuple[str, list, bool]:
        if not self._should_rewrite_for_retrieval(question, state, standalone):
            return effective_question, [], False

        context_parts = []
        topic = self._normalize_space(state.get("current_topic") or "")
        if topic:
            context_parts.append(f"Topic: {topic}.")

        guidelines = [
            GUIDELINE_NAMES.get(item, item)
            for item in (state.get("current_guidelines") or [])
            if str(item or "").strip()
        ]
        if guidelines:
            context_parts.append(f"Current guideline(s): {', '.join(guidelines)}.")

        case_context = self._normalize_space(state.get("patient_problem_context") or "")
        if case_context:
            context_parts.append(f"Case context: {case_context}")

        recommendation_refs = state.get("previous_recommendations") or []
        if recommendation_refs and self._FOLLOW_UP_CUE_RE.search(question or ""):
            context_parts.append(
                "Previously discussed recommendation(s): " + ", ".join(recommendation_refs[:2]) + "."
            )

        follow_up = self._rewrite_follow_up_question(question)
        if self._is_answer_only_turn(question):
            clarified_facts = state.get("clarified_facts") or []
            if clarified_facts:
                context_parts.append("New case details from the user: " + "; ".join(clarified_facts[:4]) + ".")
            elif follow_up:
                context_parts.append(f"New case details from the user: {follow_up}.")
            follow_up = "What does ESVS recommend for this clarified case?"

        if follow_up:
            context_parts.append(f"Current question: {follow_up}")

        rewritten = self._truncate_case_text(" ".join(part for part in context_parts if part), self.CASE_STATE_MAX_QUERY_CHARS)
        return rewritten or effective_question, [], True

    def _is_case_gate_assistant_message(self, message: dict) -> bool:
        if not isinstance(message, dict) or message.get("role") != "assistant":
            return False

        return bool(self._CASE_GATE_ASSISTANT_RE.search(self._message_text(message)))

    def _last_case_gate_index(self, messages: Optional[list]) -> int:
        for idx in range(len(messages or []) - 1, -1, -1):
            msg = (messages or [])[idx]
            if self._is_case_gate_assistant_message(msg):
                return idx
        return -1

    def _case_anchor_terms(self, text: str) -> set[str]:
        content = (text or "").lower()
        if not content:
            return set()

        anchors = {
            "carotid": r"\b(carotid|cea|cas|tcar|endarterectomy|carotid\s+stenting)\b",
            "stroke": r"\b(stroke|tia|rankin|mrs|neurological)\b",
            "aorta": r"\b(aorta|aortic|aaa|aneurysm|evar|tevar|f/b?evar|dissection|thoracoabdominal)\b",
            "venous": r"\b(dvt|pe|vte|venous|brachial\s+vein|saphen|iliac\s+vein|ivc)\b",
            "thrombus": r"\b(thrombus|thrombosis|embol|embolism)\b",
            "graft": r"\b(graft|endograft|bypass|stump|infection|magic|patent\s+bypass)\b",
            "limb": r"\b(clti|ali|claudication|wifi|rutherford|rest\s+pain|gangrene|ulcer|amputation)\b",
            "renal_mesenteric": r"\b(renal|mesenteric|sma|coeliac|celiac|visceral)\b",
            "access": r"\b(avf|fistula|dialysis|vascular\s+access)\b",
            "trauma": r"\b(trauma|injury|penetrating|blunt|reboa)\b",
        }

        matched = set()
        for label, pattern in anchors.items():
            if re.search(pattern, content, re.IGNORECASE):
                matched.add(label)
        return matched

    def _prior_gate_case_snapshot(self, messages: Optional[list], gate_index: int) -> str:
        if gate_index < 0:
            return ""

        parts = []
        user_count = 0
        for idx in range(gate_index - 1, -1, -1):
            msg = (messages or [])[idx]
            if not isinstance(msg, dict) or msg.get("role") != "user":
                continue
            text = self._message_text(msg)
            if text:
                parts.append(text)
            attachment = self._extract_message_attachment_context(msg, self.ATTACHMENT_HISTORY_MAX_CHARS)
            if attachment:
                parts.append(attachment)
            user_count += 1
            if user_count >= 2:
                break

        parts.reverse()
        return "\n".join(part for part in parts if part).strip()

    def _looks_like_fresh_case_intro(self, text: str) -> bool:
        content = (text or "").strip()
        if len(content) < 40:
            return False
        if not self._FRESH_CASE_INTRO_RE.search(content):
            return False
        return bool(self._case_anchor_terms(content))

    def _should_open_case_gate(
        self,
        current_question: str,
        attachment_context: str,
        messages: Optional[list],
    ) -> bool:
        gate_index = self._last_case_gate_index(messages)
        if gate_index < 0:
            return True

        current_snapshot = self._merge_attachment_into_question(current_question, attachment_context)
        if self._EXPLICIT_NEW_CASE_RE.search(current_snapshot):
            return True

        # Also check the raw user message — the LLM may have reformulated the question
        # to remove patient-case language, causing _looks_like_fresh_case_intro to miss it.
        latest_user_snapshot = ""
        for msg in reversed(messages or []):
            if isinstance(msg, dict) and msg.get("role") == "user":
                latest_user_snapshot = self._message_snapshot(msg)
                break
        if latest_user_snapshot and self._EXPLICIT_NEW_CASE_RE.search(latest_user_snapshot):
            return True

        # Use whichever snapshot looks more like a fresh case intro
        check_snapshot = current_snapshot
        if not self._looks_like_fresh_case_intro(current_snapshot) and self._looks_like_fresh_case_intro(latest_user_snapshot):
            check_snapshot = latest_user_snapshot

        if not self._looks_like_fresh_case_intro(check_snapshot):
            return False

        previous_snapshot = self._prior_gate_case_snapshot(messages, gate_index)
        previous_terms = self._case_anchor_terms(previous_snapshot)
        current_terms = self._case_anchor_terms(check_snapshot)

        if not current_terms:
            return False
        if not previous_terms:
            return True

        return previous_terms.isdisjoint(current_terms)

    def _needs_stroke_severity_scope(self, question: str, guidelines: Optional[list] = None) -> bool:
        q = (question or "").strip()
        if not q:
            return False

        has_carotid_context = "carotid_vertebral" in (guidelines or []) or bool(
            re.search(r"\b(carotid|cea|cas|tcar|endarterectomy|carotid\s+stenting)\b", q, re.IGNORECASE)
        )
        if not has_carotid_context:
            return False

        return bool(self._CAROTID_SEVERE_STROKE_RE.search(q))

    def _generic_case_follow_up_questions(self, full_text: str) -> list:
        """Return anatomy-aware clarification questions for cases that don't match a specific rule."""
        sparse_case = len(full_text.strip()) < 90 and not self._CLINICAL_DETAIL_RE.search(full_text)
        if sparse_case:
            return ["What are the key case details — exact diagnosis, clinical presentation, and the main management question?"]

        anchor_terms = self._case_anchor_terms(full_text)
        questions = []

        def _has(pattern: str) -> bool:
            return bool(re.search(pattern, full_text, re.IGNORECASE))

        # ── AORTIC ──────────────────────────────────────────────────────────
        if "aorta" in anchor_terms:
            fistula_context = _has(r"haematemesis|haemoptysis|haemorrhag.{0,60}(?:esophag|oesophag|bronch|tracheo)|(?:esophag|oesophag|bronch|tracheo).{0,60}haemorrhag|aorto.{0,15}(?:esophageal|oesophageal|enteric|bronchial)")
            has_infection_signs = _has(r"fever|sepsis|\bcrp\b|\bwbc\b|leukocyt|white\s+cell|infect|abscess|sinus\s+tract")
            has_imaging = _has(r"\bcta\b|\bct\s+(?:scan|angio|chest)\b|\bpet\b|endoscopy|upper\s+gi|imaging\s+(?:show|reveal|confirm)")
            has_stability = _has(r"stabl|unstabl|haemodynamic|shock|pressure|bp\b|systolic|resuscitat")
            has_complication = _has(r"complicated|uncomplicated|malperfusion|ruptur|symptom|asymptom|expand|enlarg|pain")
            has_size = _has(r"\d[\.,]\d\s*cm\b|\d{2,3}\s*mm\b|diameter|maximum\s+size")
            has_fitness = _has(r"fit\b|unfit|comorbid|cardiac|renal|pulmon|surgical\s+risk|asa\s+class|frail")
            has_prosthesis = _has(r"tevar|evar|endograft|graft|prosth|stent\s*graft|implant|repair\s+(?:was|done|performed)")

            if fistula_context:
                # Aorto-enteric / aorto-oesophageal / aortobronchial presentation
                if not has_imaging:
                    questions.append("Has CT angiography or upper endoscopy confirmed communication between the aorta/endograft and the oesophagus or airway?")
                if not has_infection_signs:
                    questions.append("Are there signs of systemic infection — fever, raised CRP/WBC? Is the patient haemodynamically stable?")
                if not has_prosthesis:
                    questions.append("What type of aortic repair was performed, when was it done, and what prosthesis was used?")
            elif _has(r"dissect"):
                # Dissection-specific
                if not has_complication:
                    questions.append("Is this complicated (malperfusion, haemodynamic instability, refractory pain, rapid expansion) or uncomplicated?")
                if not _has(r"\bacute\b|\bsubacute\b|\bchronic\b|\d+\s*days?\b|\d+\s*weeks?\b"):
                    questions.append("When did symptoms start — acute (<14 days), subacute (14–90 days), or chronic (>90 days)?")
            else:
                # Aneurysm / general aortic
                if not has_size:
                    questions.append("What is the maximum aortic diameter or lesion extent (cm/mm)?")
                if not has_complication:
                    questions.append("Is this symptomatic or complicated (pain, haemodynamic instability, rapid growth), or an elective finding?")
                if not has_fitness:
                    questions.append("Any significant comorbidities affecting fitness for open or endovascular repair?")

        # ── LIMB ISCHAEMIA ───────────────────────────────────────────────────
        elif "limb" in anchor_terms:
            has_severity = _has(r"rutherford|wifi|\babi\b|ankle.{0,15}brachial|tissue\s+loss|gangrene|rest\s+pain|motor|sensory|deficit|puls")
            has_imaging = _has(r"\bcta\b|\bduplex\b|runoff|angiograph|ct\s+angio|mra|imaging")
            has_acuity = _has(r"\bacute\b|\bchronic\b|\bsudden\b|\bhours?\b|\bdays?\b|duration|onset")
            has_aetiology = _has(r"thrombotic|embolic|in.situ|\baf\b|atrial\s+fibril|cardiac\s+source|prior\s+bypass|prior\s+pad|known\s+pad")

            if not has_acuity:
                questions.append("Is this acute (sudden onset, hours) or chronic limb ischaemia?")
            if not has_severity:
                questions.append("What is the severity — is there motor or sensory deficit, tissue loss, or rest pain? Rutherford class if assessed?")
            if not has_imaging:
                questions.append("Has vascular imaging been done — CTA runoff or duplex — and what are the anatomical findings?")
            if not has_aetiology and _has(r"\bacute\b|\bali\b|acute\s+limb"):
                questions.append("What is the suspected aetiology — embolic (AF, cardiac source) or in-situ thrombosis (known PAD, prior bypass)?")

        # ── CAROTID / STROKE ─────────────────────────────────────────────────
        elif "carotid" in anchor_terms or "stroke" in anchor_terms:
            has_symptoms = _has(r"symptomat|asymptom|\btia\b|transient\s+ischaem|amaurosis|recent\s+stroke|neurolog")
            has_degree = _has(r"\d+\s*%|\bpercent\b|nascet|degree|stenosis\s+(?:grade|severe|moderate)")
            has_timing = _has(r"days?\s+ago|weeks?\s+ago|recent|within\s+\d|hours?\s+ago|months?\s+ago")
            has_severity = _has(r"rankin|mrs|disabling|severe\s+(?:stroke|deficit)|major\s+stroke|mobili[sz]|motor\s+defic")

            if not has_symptoms:
                questions.append("Is this symptomatic (recent TIA, stroke, or amaurosis fugax within 6 months) or asymptomatic carotid disease?")
            if not has_degree:
                questions.append("What is the degree of stenosis on imaging (% by NASCET criteria)?")
            if _has(r"stroke") and not has_severity:
                questions.append("How severe was the stroke — modified Rankin Scale or functional status? Has the patient mobilised?")
            if _has(r"stroke") and not has_timing:
                questions.append("When did the neurological event occur (days/weeks ago)?")

        # ── VENOUS THROMBOSIS ────────────────────────────────────────────────
        elif "venous" in anchor_terms or "thrombus" in anchor_terms:
            has_provocation = _has(r"provok|unprovok|idiopath|cancer|malignan|surgery|immob|travel|\bocp\b|contraceptive|thrombophilia|hereditary")
            has_history = _has(r"first\s+episode|recurrent|prior\s+vte|prior\s+dvt|previous\s+(?:dvt|pe|thrombosis)|history\s+of\s+(?:dvt|pe)")
            has_anticoag = _has(r"anticoag|heparin|lmwh|doac|warfarin|apixaban|rivaroxaban")
            is_svt = _has(r"\bsvt\b|superficial.*(?:vein|thrombos)|thrombophlebit|saphen")
            has_junction = _has(r"\bsfj\b|\bspj\b|saphenofemoral|saphenopopliteal|\d+\s*cm.{0,20}(?:junction|sfj)|junction.{0,20}\d+\s*cm")

            if is_svt:
                if not has_junction:
                    questions.append("How far is the thrombus from the saphenofemoral (or saphenopopliteal) junction? This determines the treatment threshold.")
                if not has_provocation:
                    questions.append("Are there high-risk features — active cancer, prior DVT/PE, thrombophilia, no visible varicose veins, or bilateral SVT?")
            else:
                if not has_provocation:
                    questions.append("Is there a clear provoking factor (recent surgery, active cancer, immobility, OCP), or is this unprovoked?")
                if not has_history:
                    questions.append("Is this a first VTE episode or recurrent thromboembolism?")
                if not has_anticoag:
                    questions.append("Is the patient currently anticoagulated, and are there any bleeding contraindications or relevant comorbidities (cancer, renal impairment)?")

        # ── GRAFT / INFECTION ────────────────────────────────────────────────
        elif "graft" in anchor_terms:
            has_clinical = _has(r"fever|sepsis|\bcrp\b|\bwbc\b|wound|abscess|sinus|haemorrhag|anastomot")
            has_timing = _has(r"days?\b|weeks?\b|months?\b|years?\b|early|late|when|since|after|post.{0,5}op")
            has_imaging = _has(r"\bfdg\b|\bpet\b|\bct\b|\bcta\b|imaging|scan")

            if not has_clinical:
                questions.append("Are there systemic signs (fever, raised CRP/WBC) or local signs (wound breakdown, sinus tract, anastomotic haemorrhage)?")
            if not has_timing:
                questions.append("What type of graft or endograft was implanted, and how long ago?")
            if not has_imaging:
                questions.append("Has CT or FDG-PET/CT been performed? What are the findings?")

        # ── MESENTERIC / RENAL ───────────────────────────────────────────────
        elif "renal_mesenteric" in anchor_terms:
            has_acuity = _has(r"\bacute\b|\bchronic\b|\bsudden\b|hours?|days?|angina|postprandial|weight\s+loss")
            has_imaging = _has(r"\bcta\b|\bduplex\b|\bmra\b|\bangio\b|imaging|flow|stenosis\s+(?:grade|degree|\d+\s*%)")

            if not has_acuity:
                questions.append("Is this acute (sudden-onset pain, bowel ischaemia) or chronic mesenteric/renal ischaemia (postprandial angina, weight loss, progressive renal impairment)?")
            if not has_imaging:
                questions.append("Has vascular imaging been performed — CTA, MRA, or duplex — and what is the degree of stenosis or occlusion?")

        # ── UNKNOWN ANATOMY FALLBACK ─────────────────────────────────────────
        else:
            if not _has(r"\bcta\b|\bct\b|\bduplex\b|\bmri\b|imaging|scan|findings"):
                questions.append("What imaging has been performed and what are the key findings?")
            if not _has(r"anticoag|heparin|lmwh|doac|warfarin|comorbid|renal|cardiac|prior\s+(?:intervention|surgery|bypass)"):
                questions.append("Is the patient on anticoagulation or antiplatelet therapy? Any relevant comorbidities or prior vascular interventions?")

        if not questions:
            questions.append("What is the single most important clinical detail that determines the guideline recommendation for this specific case?")

        return questions[:3]  # cap at 3 questions

    def _normalize_attachment_text(self, text: str, max_chars: int) -> str:
        if not text:
            return ""

        cleaned = html.unescape(str(text))
        cleaned = cleaned.replace("\r\n", "\n").replace("\r", "\n").replace("\x00", " ")
        cleaned = re.sub(r"[ \t]+\n", "\n", cleaned)
        cleaned = re.sub(r"\n[ \t]+", "\n", cleaned)
        cleaned = re.sub(r"\n{3,}", "\n\n", cleaned)
        cleaned = re.sub(r"[ \t]{2,}", " ", cleaned)
        cleaned = cleaned.strip()
        if max_chars and len(cleaned) > max_chars:
            return cleaned[:max_chars]
        return cleaned

    def _attachment_filename(self, file_info: dict) -> str:
        if not isinstance(file_info, dict):
            return ""

        nested = file_info.get("file")
        if isinstance(nested, dict):
            nested_name = self._attachment_filename(nested)
            if nested_name:
                return nested_name

        for value in (
            file_info.get("name"),
            file_info.get("filename"),
            ((file_info.get("meta") or {}).get("name") if isinstance(file_info.get("meta"), dict) else ""),
        ):
            if isinstance(value, str) and value.strip():
                return value.strip()

        return ""

    def _attachment_content_type(self, file_info: dict) -> str:
        if not isinstance(file_info, dict):
            return ""

        nested = file_info.get("file")
        if isinstance(nested, dict):
            nested_type = self._attachment_content_type(nested)
            if nested_type:
                return nested_type

        for value in (
            file_info.get("content_type"),
            file_info.get("mime_type"),
            ((file_info.get("meta") or {}).get("content_type") if isinstance(file_info.get("meta"), dict) else ""),
        ):
            if isinstance(value, str) and value.strip():
                return value.strip().lower()

        return ""

    def _extract_preprocessed_attachment_text(self, file_info: dict, max_chars: int) -> str:
        if not isinstance(file_info, dict):
            return ""

        for key in ("extracted_content", "text", "summary"):
            value = file_info.get(key)
            if isinstance(value, str) and value.strip():
                return self._normalize_attachment_text(value, max_chars)

        content = file_info.get("content")
        if isinstance(content, str) and content.strip() and "base64," not in content[:120]:
            return self._normalize_attachment_text(content, max_chars)

        nested = file_info.get("file")
        if isinstance(nested, dict):
            return self._extract_preprocessed_attachment_text(nested, max_chars)

        return ""

    def _read_attachment_bytes(self, file_info: dict) -> tuple[bytes, str, str]:
        if not isinstance(file_info, dict):
            return b"", "", ""

        nested = file_info.get("file")
        if isinstance(nested, dict):
            data, filename, content_type = self._read_attachment_bytes(nested)
            return (
                data,
                filename or self._attachment_filename(file_info),
                content_type or self._attachment_content_type(file_info),
            )

        filename = self._attachment_filename(file_info)
        content_type = self._attachment_content_type(file_info)

        content = file_info.get("content")
        if isinstance(content, bytes):
            return content[: self.ATTACHMENT_MAX_BYTES + 1], filename, content_type

        if isinstance(content, str) and content.strip() and "base64," in content[:120]:
            try:
                raw = base64.b64decode(content.split(",", 1)[1], validate=False)
                return raw[: self.ATTACHMENT_MAX_BYTES + 1], filename, content_type
            except Exception:
                pass

        data = file_info.get("data")
        if isinstance(data, str) and data.strip():
            try:
                raw = base64.b64decode(data, validate=False)
                return raw[: self.ATTACHMENT_MAX_BYTES + 1], filename, content_type
            except Exception:
                pass

        path = file_info.get("path")
        if isinstance(path, str) and path and os.path.exists(path):
            try:
                with open(path, "rb") as handle:
                    return handle.read(self.ATTACHMENT_MAX_BYTES + 1), filename, content_type
            except Exception:
                return b"", filename, content_type

        return b"", filename, content_type

    def _decode_text_bytes(self, raw: bytes) -> str:
        for encoding in ("utf-8", "utf-16", "latin-1"):
            try:
                return raw.decode(encoding)
            except UnicodeDecodeError:
                continue
        return raw.decode("utf-8", errors="ignore")

    def _extract_docx_text(self, raw: bytes) -> str:
        try:
            with zipfile.ZipFile(io.BytesIO(raw)) as archive:
                xml_parts = []
                preferred = [
                    name
                    for name in (
                        "word/document.xml",
                        "word/header1.xml",
                        "word/header2.xml",
                        "word/footer1.xml",
                        "word/footer2.xml",
                        "word/footnotes.xml",
                        "word/endnotes.xml",
                    )
                    if name in archive.namelist()
                ]
                for name in preferred:
                    xml = archive.read(name).decode("utf-8", errors="ignore")
                    xml = re.sub(r"</w:p[^>]*>", "\n", xml)
                    xml = re.sub(r"</w:tr[^>]*>", "\n", xml)
                    xml = re.sub(r"<w:tab[^>]*/>", "\t", xml)
                    xml = re.sub(r"<w:br[^>]*/>", "\n", xml)
                    xml = re.sub(r"<[^>]+>", "", xml)
                    xml_parts.append(xml)
        except Exception:
            return ""

        return self._normalize_attachment_text("\n".join(xml_parts), self.ATTACHMENT_QUESTION_MAX_CHARS)

    def _extract_attachment_text(self, file_info: dict, max_chars: int) -> str:
        preprocessed = self._extract_preprocessed_attachment_text(file_info, max_chars)
        if preprocessed:
            return preprocessed

        raw, filename, content_type = self._read_attachment_bytes(file_info)
        if not raw or len(raw) > self.ATTACHMENT_MAX_BYTES:
            return ""

        ext = ""
        if filename and "." in filename:
            ext = filename.rsplit(".", 1)[-1].lower()

        if ext == "docx" or "wordprocessingml.document" in content_type:
            return self._normalize_attachment_text(self._extract_docx_text(raw), max_chars)

        if ext in {"txt", "md", "csv", "json", "xml", "html", "htm", "yaml", "yml"}:
            return self._normalize_attachment_text(self._decode_text_bytes(raw), max_chars)

        if content_type.startswith("text/") or any(token in content_type for token in ("json", "xml", "html")):
            return self._normalize_attachment_text(self._decode_text_bytes(raw), max_chars)

        return ""

    def _extract_message_attachment_context(self, message: dict, max_chars: int) -> str:
        if not isinstance(message, dict):
            return ""

        chunks = []
        remaining = max_chars

        message_context = message.get("context")
        if isinstance(message_context, str) and len(message_context.strip()) > 40:
            cleaned = self._normalize_attachment_text(message_context, remaining)
            if cleaned:
                chunks.append(cleaned)
                remaining -= len(cleaned)

        for file_info in (message.get("files") or [])[: self.ATTACHMENT_MAX_FILES]:
            if remaining <= 0:
                break
            text = self._extract_attachment_text(file_info, remaining)
            if not text:
                continue
            chunks.append(text)
            remaining -= len(text)

        return "\n\n".join(chunk for chunk in chunks if chunk).strip()

    def _extract_top_level_attachment_context(
        self,
        files: Optional[list],
        max_chars: int,
    ) -> str:
        chunks = []
        remaining = max_chars

        for file_info in (files or [])[: self.ATTACHMENT_MAX_FILES]:
            if remaining <= 0:
                break
            text = self._extract_attachment_text(file_info, remaining)
            if not text:
                continue
            chunks.append(text)
            remaining -= len(text)

        return "\n\n".join(chunk for chunk in chunks if chunk).strip()

    def _merge_attachment_into_question(self, question: str, attachment_text: str) -> str:
        q = (question or "").strip()
        attachment = self._normalize_attachment_text(attachment_text, self.ATTACHMENT_QUESTION_MAX_CHARS)
        if not attachment:
            return q

        if q:
            return f"{q}\n\nAttached case document text:\n{attachment}"
        return f"Attached case document text:\n{attachment}"

    def _prepare_consult_inputs(
        self,
        question: str,
        messages: Optional[list],
        standalone: bool,
        files: Optional[list] = None,
        metadata: Optional[dict] = None,
    ) -> tuple[str, list[str], str]:
        q = (question or "").strip()
        if standalone or not messages:
            top_level_files = list(files or [])
            if isinstance(metadata, dict):
                top_level_files.extend((metadata.get("files") or []))
            attachment_text = self._extract_top_level_attachment_context(
                top_level_files,
                self.ATTACHMENT_QUESTION_MAX_CHARS,
            )
            return self._merge_attachment_into_question(q, attachment_text), [], attachment_text

        history = []
        current_attachment_context = ""
        user_messages = [msg for msg in messages[-6:] if isinstance(msg, dict) and msg.get("role") == "user"]

        for idx, msg in enumerate(user_messages):
            content = msg.get("content")
            if isinstance(content, str) and content.strip():
                trimmed = content.strip()
                history.append(trimmed[:500] if len(trimmed) > 500 else trimmed)

            attachment_limit = self.ATTACHMENT_QUESTION_MAX_CHARS if idx == len(user_messages) - 1 else self.ATTACHMENT_HISTORY_MAX_CHARS
            attachment_text = self._extract_message_attachment_context(msg, attachment_limit)
            if not attachment_text:
                continue

            if idx == len(user_messages) - 1:
                current_attachment_context = attachment_text
            else:
                history.append(f"[Attached case document]\n{attachment_text[: self.ATTACHMENT_HISTORY_MAX_CHARS]}")

        if not current_attachment_context:
            top_level_files = list(files or [])
            if isinstance(metadata, dict):
                top_level_files.extend((metadata.get("files") or []))
            current_attachment_context = self._extract_top_level_attachment_context(
                top_level_files,
                self.ATTACHMENT_QUESTION_MAX_CHARS,
            )

        return self._merge_attachment_into_question(q, current_attachment_context), history, current_attachment_context

    def _has_substantial_attachment_context(
        self,
        attachment_context: str = "",
        history: Optional[list] = None,
    ) -> bool:
        attachment = self._normalize_attachment_text(
            attachment_context,
            self.ATTACHMENT_QUESTION_MAX_CHARS,
        )
        if len(attachment) >= self.ATTACHMENT_SKIP_GENERIC_GATE_CHARS:
            return True

        for item in history or []:
            if not isinstance(item, str) or not item.startswith("[Attached case document]"):
                continue
            _, _, prior_attachment = item.partition("\n")
            prior_attachment = self._normalize_attachment_text(
                prior_attachment,
                self.ATTACHMENT_HISTORY_MAX_CHARS,
            )
            if len(prior_attachment) >= self.ATTACHMENT_SKIP_GENERIC_GATE_CHARS:
                return True

        return False

    def _thread_has_uploaded_document(
        self,
        messages: Optional[list],
        files: Optional[list] = None,
        metadata: Optional[dict] = None,
    ) -> bool:
        for msg in messages or []:
            if not isinstance(msg, dict) or msg.get("role") != "user":
                continue
            for key in ("files", "attachments"):
                items = msg.get(key) or []
                if isinstance(items, list) and items:
                    return True
        if isinstance(files, list) and files:
            return True
        if isinstance(metadata, dict) and isinstance(metadata.get("files"), list) and metadata.get("files"):
            return True
        return False

    def _format_context_request(
        self,
        gap_questions: list,
        question: str,
        history: Optional[list] = None,
        scenario_id: str = "",
        attachment_context: str = "",
    ) -> str:
        """Return a tool response that instructs the LLM to ask expert clarification questions."""
        context_lines = []
        current = (question or "").strip()
        if current:
            context_lines.append(f"- Case: {current}")
        attachment_excerpt = self._normalize_attachment_text(attachment_context, self.ATTACHMENT_PROMPT_MAX_CHARS).replace("\n", " ")
        if attachment_excerpt:
            context_lines.append(f"- Attached document: {attachment_excerpt}")

        history_lines = []
        for item in reversed(history or []):
            if not isinstance(item, str):
                continue
            text = item.strip()
            if not text or text == current or text in history_lines:
                continue
            history_lines.append(text)
            if len(history_lines) >= 2:
                break
        history_lines.reverse()
        for item in history_lines:
            context_lines.append(f"- Earlier context: {item}")

        lines = [
            "GUIDELINE_RETRIEVAL_PAUSED — key clinical details missing before ESVS retrieval.",
            "",
            "MANDATORY BEHAVIOR (no exceptions):",
            "1. Your entire reply to the user must be a focused clinical clarification — nothing else.",
            "2. Do NOT answer the question or offer any guideline recommendation yet.",
            "3. Do NOT say the scenario is or is not addressed by guidelines.",
            "4. Do NOT mention evidence gaps or tool internals.",
            "5. Begin with a single-sentence opener stating you need to clarify a couple of details",
            "   before you can retrieve the ESVS guidelines — for example:",
            "   'Before I retrieve the relevant ESVS guidelines, I need to clarify a couple of things about this case.'",
            "   or: 'Before I can search the ESVS guidelines, a few clinical details will help me find the right evidence.'",
            "6. Then ask the clinical questions. Act as a vascular surgery consultant asking a colleague",
            "   for the key missing facts — use direct, natural language tailored to this specific case.",
            "7. Tailor every question to the anatomy, pathology, and management decision presented.",
            "8. Ask only what genuinely changes the guideline recommendation — typically 2–3 questions.",
            "9. Use plain conversational phrasing; avoid stiff bullet headers like '**Topic**:'.",
            "",
        ]
        if context_lines:
            lines.append("KNOWN CASE CONTEXT:")
            lines.extend(context_lines)
            lines.append("")
        lines += [
            "CLINICAL GAPS TO ADDRESS:",
            "The following expert-identified questions need to be answered before retrieval.",
            "Adapt the wording to this specific case — do not copy them verbatim as generic questions:",
            "",
        ]
        for i, q in enumerate(gap_questions, 1):
            lines.append(f"{i}. {q}")
        lines += [
            "",
            "AFTER THE USER REPLIES:",
            "1. Merge ALL known context with the new answers into one complete standalone clinical scenario.",
            "2. Call `consult_vascular_guidelines` with that synthesized scenario — not just the user's short reply.",
            "3. If the user answers only partially, ask only the remaining unanswered questions first.",
            "",
            "END",
        ]
        return "\n".join(lines)

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

    def _requires_clinical_decision_summary(self, question: str, intent_profile: Optional[dict]) -> bool:
        q = (question or "").lower()
        intent = ""
        if isinstance(intent_profile, dict):
            intent = str(intent_profile.get("intent") or "").strip().lower()

        if intent in {"management", "treatment", "comparison", "procedure", "threshold", "indication"}:
            return True

        return bool(re.search(
            r"\b(manage(?:ment)?|treat(?:ment)?|strategy|best\s+(?:option|approach)|"
            r"choice|choose|preferred|versus|vs\.?|open\s+or\s+endovascular)\b",
            q
        ))

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
            # Use plain markdown image syntax for the inline thumbnail.
            # Nested linked-image markdown has been fragile across OpenWebUI
            # viewer/lightbox variants and can trigger inconsistent popup/tab
            # behavior depending on the client.
            lines.append(f"![{alt_text}]({thumb_url})")
            if full_url and full_url != thumb_url:
                lines.append(f"[Full-size]({full_url})")
            lines.append("")
            count += 1

        if count == 0:
            return ""

        lines.insert(1, f"ASSET_COUNT_REQUIRED: {count}")
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
        EMIT_STATUS_AS_MESSAGES: bool = Field(
            default=True,
            description="Emit retrieval progress as normal assistant messages (always visible, not collapsible).",
        )
        EMIT_STATUS_EVENTS: bool = Field(
            default=False,
            description="Also emit OpenWebUI status events (can appear in collapsible status UI).",
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

    async def _normalize_query(self, question: str, *, raw_user_text: str = "") -> str:
        """
        Translate a non-English query to English via the Laravel /normalize endpoint.
        Returns the original question unchanged if it appears to be English or if the
        endpoint is unavailable.

        Non-ASCII detection uses `question` first, then falls back to `raw_user_text`
        when the LLM reformulation has stripped accented characters (e.g. Italian 'è'→'e').
        """
        detect_source = question
        if raw_user_text and not re.search(r"[^\x00-\x7F]", question) and re.search(r"[^\x00-\x7F]", raw_user_text):
            # LLM stripped accents in its reformulation; use raw user message for detection.
            detect_source = raw_user_text
            print(f"[VascularExpert] Non-ASCII found in raw user message but not in LLM reformulation — using raw message for language detection")

        if not question or not re.search(r"[^\x00-\x7F]", detect_source):
            return question
        try:
            api_base = getattr(self.valves, "VASCULAR_API_BASE_URL", "").rstrip("/")
            api_key = getattr(self.valves, "VASCULAR_API_KEY", "")
            if not api_base or not api_key:
                return question
            async with httpx.AsyncClient(timeout=8.0) as client:
                resp = await client.post(
                    f"{api_base}/api/v1/normalize",
                    json={"question": question},
                    headers={
                        "Authorization": f"Bearer {api_key}",
                        "Content-Type": "application/json",
                    },
                )
                if resp.status_code == 200:
                    data = resp.json()
                    normalized = (data.get("normalized_query") or "").strip()
                    if normalized and data.get("changed"):
                        lang = data.get("language", "?")
                        print(f"[VascularExpert] Pre-translated query ({lang}→en): {normalized!r}")
                        return normalized
        except Exception as exc:
            print(f"[VascularExpert] Normalize endpoint unavailable (using original): {exc}")
        return question

    async def consult_vascular_guidelines(
        self,
        question: str,
        guideline_1: GuidelineKey,
        guideline_2: Optional[GuidelineKey] = None,
        guideline_3: Optional[GuidelineKey] = None,
        standalone: bool = False,
        __user__: dict = {}, 
        __messages__: list = [],
        __files__: list = [],
        __metadata__: dict = {},
        __event_emitter__: Callable[[dict], Awaitable[None]] = None
    ) -> str:
        """
        Consult ESVS Vascular Guidelines. Select 1-3 guidelines based on the clinical question.
        
        **CRITICAL**: Call this tool for concrete vascular clinical/guideline questions:
        1. ANY vascular surgery question (direct or follow-up)
        2. When the user attaches a patient case/document and asks about ESVS compliance
        3. When comparing patient management against guidelines
        4. ANY follow-up question in an ongoing case or guideline discussion — including
           terse dosing/drug/action questions — e.g.:
           - "So can I give 10mg rivaroxaban OD?"
           - "Can I use apixaban instead?"
           - "What dose should I use?"
           - "Should I switch to a DOAC?"
           - "Is that the correct dose?"
           - "Can you clarify how the guidelines define asymptomatic?"
           - "What threshold does the guideline use for treatment?"
           - "Can you explain what Rec 32 means?"
           ALWAYS call this tool — NEVER answer from a prior tool result in history.
           A prior 🩺 Clinical Synthesis in history is NOT a reason to skip retrieval;
           each new question requires its own fresh guideline lookup.
        5. **Regeneration rule**: If you are regenerating a response for a question where
           guideline retrieval already succeeded (the previous assistant turn contained a
           🩺 Clinical Synthesis section), you MUST call this tool again — do not answer
           from the prior tool result. This rule does NOT apply when the tool previously
           returned GUIDELINE_RETRIEVAL_PAUSED; in that case, collect the missing clinical
           parameters from the user before calling again.

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
        4. For patient-specific clinical cases, always ask for extra case context before retrieval
           so the final retrieval query is a complete standalone scenario, even if a case
           document has already been attached.
        
        SELECTION RULES:
        1. Match anatomical territory first (aorta, limb, cerebral, venous)
        2. Consider acuity (acute vs chronic)
        3. Add companion guidelines if question spans domains
        4. Add antithrombotic_therapy ONLY when the user is actually asking about
           anticoagulation/antithrombotic decisions: aortic mural thrombus, peri-procedural
           antiplatelet or anticoagulant management, bleeding risk assessment,
           DOAC/antiplatelet selection, or bridging therapy.
           Do NOT add antithrombotic_therapy just because the case mentions stroke,
           carotid stenosis, or another vascular condition.
        5. Add vascular_graft_infections as a companion whenever the question involves
           aorto-oesophageal fistula, aortobronchial fistula, haematemesis after TEVAR
           or aortic repair, or any contaminated/infected field after aortic endovascular
           or open repair — even if the primary guideline is descending_thoracic_aorta or
           abdominal_aortic_aneurysm.
        6. If the user presents a proposed plan or clinical assertion and asks whether it is
           correct or guideline-consistent, still call this tool. Do not answer from memory.

        GUIDELINE REFERENCE:
        - aortic_arch: Arch aneurysm, Zone 0-2, FET, hybrid arch
        - descending_thoracic_aorta: Type B dissection, TEVAR, thoracic aneurysm, aortic mural thrombus
        - abdominal_aortic_aneurysm: AAA, EVAR, rupture, endoleaks, iliac aneurysm
        - mesenteric_renal: Mesenteric ischemia (CMI/AMI), renal artery stenosis
        - asymptomatic_pad: Claudication, PAD screening, exercise therapy
        - clti: Rest pain, tissue loss, gangrene, limb salvage
        - acute_limb_ischaemia: ALI, sudden limb pain, 6Ps, embolism
        - carotid_vertebral: Stroke, TIA, carotid stenosis, CEA, CAS
        - venous_thrombosis: DVT, PE, VTE, anticoagulation
        - chronic_venous_disease: Varicose veins, venous ulcers, CEAP
        - antithrombotic_therapy: Aspirin, DOACs, DAPT, bleeding risk, aortic thrombus
          anticoagulation, post-stroke antithrombotic therapy, bridging
        - vascular_trauma: Penetrating/blunt injury, REBOA
        - vascular_graft_infections: Graft/endograft infection, post-procedure fever,
          aorto-oesophageal fistula, aortobronchial fistula, haematemesis after TEVAR,
          contaminated surgical field after aortic repair
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

        effective_question, history, current_attachment_context = self._prepare_consult_inputs(
            question,
            __messages__,
            standalone,
            __files__,
            __metadata__,
        )

        # --- PRE-TRANSLATION ---
        # Translate non-English queries to English before gate pattern-matching and retrieval.
        # Pass the raw user message as fallback so that LLM-stripped accents (e.g. è→e) don't
        # defeat the non-ASCII detection used to decide whether to call the normalize endpoint.
        _raw_user_text = ""
        for _msg in reversed(__messages__ or []):
            if isinstance(_msg, dict) and _msg.get("role") == "user":
                _raw_user_text = str(_msg.get("content") or "").strip()[:2000]
                break
        normalized_q = await self._normalize_query(effective_question, raw_user_text=_raw_user_text)
        if normalized_q != effective_question:
            await self._emit_status(emitter, "Translating query to English for guideline retrieval...")

        guidelines = self._filter_requested_guidelines(guidelines, normalized_q)

        # Build human-readable guideline display
        guideline_display = ", ".join(GUIDELINE_NAMES.get(g, g) for g in guidelines)
        await self._emit_status(emitter, f"Selecting guidelines: {guideline_display}")

        message_file_count = sum(
            len(msg.get("files") or [])
            for msg in (__messages__ or [])
            if isinstance(msg, dict)
        )
        top_level_file_count = len(__files__ or [])
        print(
            f"[VascularExpert] Input context: messages={len(__messages__ or [])}, "
            f"message_files={message_file_count}, top_level_files={top_level_file_count}"
        )
        has_uploaded_document = self._thread_has_uploaded_document(
            __messages__,
            __files__,
            __metadata__,
        )
        has_attachment_context = self._has_substantial_attachment_context(
            current_attachment_context,
            history,
        )
        if current_attachment_context:
            print(
                f"[VascularExpert] Added attachment context to question ({len(current_attachment_context)} chars)"
            )
        if has_uploaded_document:
            print("[VascularExpert] Uploaded document detected; including it in the clarification gate context")
        elif has_attachment_context:
            print("[VascularExpert] Substantial attachment context detected; still using clarification gate")
        
        # --- AGENTIC CONTEXT CHECK ---
        # For patient-case consultations, always collect clarifying details before hitting
        # the backend. Raw guideline-knowledge questions skip this and retrieve directly.
        # Use normalized_q so non-English cases are matched by English-only regex patterns.
        if (
            not standalone
            and self._should_request_case_follow_up(normalized_q, history)
            and self._should_open_case_gate(normalized_q, current_attachment_context, __messages__)
        ):
            gap_id, gap_questions = self._assess_context_gaps(normalized_q, history)
            if gap_questions:
                await self._emit_status(emitter, "Clarifying clinical context before retrieval...", done=True)
                return self._format_context_request(
                    gap_questions,
                    question,
                    history,
                    gap_id,
                    current_attachment_context,
                )

        conversation_state = self._build_conversation_state(
            question,
            __messages__,
            guidelines,
            current_attachment_context,
        )
        retrieval_question, retrieval_history, rewritten_follow_up = self._prepare_retrieval_query(
            question,
            normalized_q,
            conversation_state,
            standalone,
        )
        guidelines = self._filter_requested_guidelines(guidelines, retrieval_question)

        print(
            "[VascularExpert] Conversation state: "
            f"topic={conversation_state.get('current_topic')!r}, "
            f"guidelines={conversation_state.get('current_guidelines')}, "
            f"clarified={len(conversation_state.get('clarified_facts') or [])}, "
            f"previous_recs={conversation_state.get('previous_recommendations')}"
        )
        if rewritten_follow_up:
            await self._emit_status(emitter, "Rewriting same-case follow-up into a standalone retrieval query...")
            print(
                "[VascularExpert] Rewritten retrieval query: "
                f"{self._truncate_case_text(retrieval_question, 320)}"
            )

        await self._emit_status(emitter, f"Consulting {len(guidelines)} ESVS guideline(s)...")

        url = f"{self.valves.VASCULAR_API_BASE_URL}/api/v1/vascular-consult"
        headers = {
            "Authorization": f"Bearer {self.valves.VASCULAR_API_KEY}",
            "Content-Type": "application/json",
        }
        payload = {
            "question": retrieval_question,
            "history": retrieval_history,
            "guidelines": guidelines  # LLM-selected guidelines (enum-constrained)
        }

        try:
            import time
            start_time = time.time()
            
            await self._emit_status(emitter, "🔍 Sending query to backend router...")
            await asyncio.sleep(0.1)  # Give UI time to update
            
            await self._emit_status(emitter, "📚 Searching selected guideline set...")
            
            async with httpx.AsyncClient(timeout=120.0) as client:
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

            intent_profile = self._infer_intent_profile(effective_question, query_normalization)
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
                requires_decision_summary = self._requires_clinical_decision_summary(effective_question, intent_profile)

                if self._show_clinical_frame():
                    clinical_frame = ""
                    if isinstance(query_normalization, dict):
                        clinical_frame = str(query_normalization.get("clinical_frame") or "").strip()
                    if clinical_frame:
                        llm_output += "=== CLINICAL FRAME (INTERPRETIVE / NON-GUIDELINE) ===\n"
                        llm_output += clinical_frame + "\n"
                        llm_output += "Guidance: You may include a brief interpretive framing note, clearly labeled as non-guideline and without citations.\n\n"

                # Put assets early so the model consistently sees them even in long contexts.
                assets_block = self._format_assets_markdown(assets)
                if assets_block:
                    llm_output += assets_block

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

                if requires_decision_summary:
                    llm_output += "=== CLINICAL DECISION SUMMARY (REQUIRED) ===\n"
                    llm_output += "For management/treatment/clinical strategy questions, conclude with a section titled exactly: **Clinical Decision Summary**.\n"
                    llm_output += "Using the retrieved guideline evidence, you must:\n"
                    llm_output += "1. Determine whether treatment thresholds are met.\n"
                    llm_output += "2. Interpret the anatomical features provided.\n"
                    llm_output += "3. Compare available treatment strategies supported by evidence.\n"
                    llm_output += "4. State the guideline-consistent default/preferred strategy when inferable.\n"
                    llm_output += "5. Explain why this strategy is preferred and identify the main alternative strategy with when it may be chosen instead.\n"
                    llm_output += "Do not stop at 'both options may be considered'; provide a reasoned decision.\n"
                    llm_output += "If anatomical measurements are provided (e.g., neck length, angulation, landing zones), interpret compatibility with standard EVAR, fenestrated/branched endovascular repair, and open surgical repair, and explain how anatomy influences modality choice.\n\n"
                    llm_output += "=== PERIOPERATIVE RISK MITIGATION (GUIDELINE-BASED, REQUIRED) ===\n"
                    llm_output += "When discussing operative management, summarize key perioperative risk-reduction strategies mentioned in the guideline, including when relevant:\n"
                    llm_output += "- spinal cord ischemia prevention\n"
                    llm_output += "- renal protection\n"
                    llm_output += "- cardiac risk optimisation\n"
                    llm_output += "- staged repair strategies\n"
                    llm_output += "- preservation of critical branch vessels\n\n"

                if self._needs_stroke_severity_scope(effective_question, effective_guidelines):
                    llm_output += "=== STROKE SEVERITY SCOPE (CASE-SPECIFIC) ===\n"
                    llm_output += "This case signals major/disabling stroke or severe neurological deficit.\n"
                    llm_output += "Prefer evidence that explicitly addresses disabling or major stroke.\n"
                    llm_output += "Do NOT apply TIA, minor-stroke, or non-disabling-stroke carotid intervention timing recommendations unless the retrieved evidence explicitly says they apply to this severity.\n\n"

                llm_output += "=== CITATION RULES ===\n"
                llm_output += "1. Use simple numbered citations [1], [2], [3] inline after each fact.\n"
                llm_output += "2. Cite only sources you actually use; do not force-cite unrelated evidence.\n"
                llm_output += "3. Do NOT add a separate References section; the UI already shows a Sources list.\n"
                llm_output += "4. Match the bracketed numbers [n] exactly to the evidence blocks above.\n"
                llm_output += "5. If evidence spans multiple guidelines, cite at least one recommendation from each guideline used in your synthesis.\n"
                llm_output += "6. SCOPE FILTER: Before citing a recommendation, verify it directly addresses the specific case. Exclude any recommendation that pertains to a different procedure or condition than what was asked (e.g., if the case is aortic mural thrombus, do not cite TEVAR or LSA revascularisation recommendations unless the case explicitly involves TEVAR). When no directly applicable recommendation was retrieved, state this explicitly rather than citing tangential evidence.\n"
                if not selected_citation_chunks and selected_narrative_chunks:
                    llm_output += "7. It is valid to answer from narrative context only and explicitly say no direct recommendation chunk was retrieved.\n"
                if assets_block:
                    next_rule_num = 8 if (not selected_citation_chunks and selected_narrative_chunks) else 7
                    llm_output += f"{next_rule_num}. Include a final section titled exactly: 🖼️ Figures / Tables and copy ALL markdown image lines exactly from the FIGURES / TABLES block. The number of image lines must equal ASSET_COUNT_REQUIRED.\n"

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
                    llm_output += "IMPORTANT: Restrict every section below to evidence DIRECTLY relevant to the specific case. Do NOT include recommendations about procedures not involved in this case (e.g., if no TEVAR is planned, do not discuss LSA revascularisation during TEVAR). Acknowledge evidence gaps explicitly in 'Evidence used' rather than citing tangential recommendations.\n"
                    llm_output += "Assessment:\n"
                    llm_output += "Imaging:\n"
                    llm_output += "Indication for intervention:\n"
                    llm_output += "Treatment options:\n"
                    if requires_decision_summary:
                        llm_output += "Clinical Decision Summary:\n"
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
