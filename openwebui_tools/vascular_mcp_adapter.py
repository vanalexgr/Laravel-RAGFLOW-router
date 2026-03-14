"""
title: Vascular MCP Adapter
author: open-webui
version: 1.1.0
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
    CASE_STATE_MAX_CONTEXT_ITEMS   = 4
    CASE_STATE_MAX_REFERENCE_ITEMS = 4
    CASE_STATE_MAX_QUERY_CHARS     = 1600

    # ------------------------------------------------------------------ #
    # Gate: class-level regexes (verbatim from vascular_expert.py)        #
    # ------------------------------------------------------------------ #

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

    _CLINICAL_DETAIL_RE = re.compile(
        r"\b(\d+|symptomatic|asymptomatic|acute|chronic|bilateral|unilateral|"
        r"anticoag|aspirin|warfarin|doac|cancer|malignancy|prior|previous|"
        r"risk|recurrent|complicated|uncomplicated|mobile|sessile|"
        r"cm\b|mm\b|provoked|unprovoked|fit\b|unfit)\b",
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

    _FRESH_CASE_INTRO_RE = re.compile(
        r"\b(my\s+patient|patient\s+(?:with|who|has)|pt\s+(?:with|who|has)|"
        r"case\s+of|"
        r"\d{1,3}\s*(?:year[- ]old|yo)\b|"
        r"(?:male|female|man|woman)\s+with|"
        r"presents?\s+with|presented\s+with|admitted\s+with|referred\s+with|"
        r"was\s+found|incidentally|ασθεν|ετ[ωώ]ν)\b",
        re.IGNORECASE,
    )

    _EXPLICIT_NEW_CASE_RE = re.compile(
        r"\b(another|different|new|separate|next)\s+(?:patient|case)\b|"
        r"\bfor\s+a\s+different\s+patient\b",
        re.IGNORECASE,
    )

    _FOLLOW_UP_CUE_RE = re.compile(
        r"^(what\s+about|what\s+if|how\s+about|and|but|so|then|if|for\s+this\s+case|"
        r"in\s+this\s+case|for\s+this\s+patient|in\s+this\s+patient)\b",
        re.IGNORECASE,
    )

    _NUMBERED_ITEM_RE    = re.compile(r"^\s*(\d+)[\.\)]\s+(.*\S)\s*$")
    _ASSISTANT_GUIDELINE_RE = re.compile(r"Selecting guidelines:\s*(.+)", re.IGNORECASE)
    _RECOMMENDATION_REF_RE  = re.compile(r"\bRec(?:ommendation)?\s*\.?\s*(\d+)\b", re.IGNORECASE)

    _CAROTID_SEVERE_STROKE_RE = re.compile(
        r"\b(major\s+(?:ischaemic\s+|ischemic\s+)?stroke|"
        r"disabling\s+(?:ischaemic\s+|ischemic\s+)?stroke|"
        r"major\s+disabling\s+stroke|severe\s+stroke|large\s+infarct(?:ion)?|"
        r"(?:modified\s+)?rankin(?:\s+scale)?|mrs\b|"
        r"(?:hasn'?t|has\s+not|not)\s+yet\s+mobili[sz]ed|"
        r"unable\s+to\s+mobili[sz]e|dense\s+neurological\s+deficit)\b",
        re.IGNORECASE,
    )

    # Gate: scenario rules (verbatim from vascular_expert.py)
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
                r"\b(brachial|subclavian|axillary|cephalic|basilic)\s+vein\b.{0,80}\b(anticoag|heparin|lmwh|doac|treat)",
                r"\b(anticoag|heparin|lmwh|doac)\b.{0,80}\b(brachial|subclavian|axillary|cephalic|basilic)\s+vein\b",
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
                r"aorto.{0,12}(?:oesophageal|esophageal|enteric|bronchial)\s+fistul",
                r"(?:oesophageal|esophageal|enteric|bronchial).{0,20}fistul",
                r"haematemesis.{0,80}(?:tevar|endograft|aort|graft)",
                r"(?:tevar|endograft|aort|graft).{0,80}haematemesis",
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
        s = re.sub(
            r"<table[^>]*>.*?</table>",
            lambda m: self._html_table_to_text(m.group(0)),
            s,
            flags=re.S | re.I,
        )
        s = re.sub(r"<[^>]+>", "", s)
        s = html.unescape(s)
        s = self._strip_markdown(s)
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
    # Helpers: message / state utilities (from vascular_expert.py)        #
    # ------------------------------------------------------------------ #

    def _normalize_space(self, text: str) -> str:
        return re.sub(r"\s+", " ", (text or "").strip())

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

    def _message_snapshot(self, message: dict) -> str:
        """Simplified: no attachment extraction (adapter is stateless)."""
        return self._message_text(message)

    def _truncate_case_text(self, text: str, max_chars: int = 1600) -> str:
        s = self._normalize_space(text)
        if len(s) <= max_chars:
            return s
        return s[: max_chars - 3].rstrip() + "..."

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

    def _looks_like_fresh_case_intro(self, text: str) -> bool:
        content = (text or "").strip()
        if len(content) < 40:
            return False
        if not self._FRESH_CASE_INTRO_RE.search(content):
            return False
        return bool(self._case_anchor_terms(content))

    # ------------------------------------------------------------------ #
    # Gate: context gap detection (verbatim from vascular_expert.py)      #
    # ------------------------------------------------------------------ #

    def _case_anchor_terms(self, text: str) -> set:
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

    def _is_raw_guideline_knowledge_query(self, question: str, history: Optional[list] = None) -> bool:
        q = (question or "").strip()
        if not q:
            return False

        if self._PATIENT_CASE_RE.search(q):
            return False

        for item in history or []:
            if isinstance(item, str) and self._PATIENT_CASE_RE.search(item):
                return False

        combined = f"{q} {' '.join(history or [])}".strip()

        if self._GENERIC_PATIENT_POPULATION_RE.search(q):
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

    def _generic_case_follow_up_questions(self, full_text: str) -> list:
        """Return anatomy-aware clarification questions for cases that don't match a specific rule."""
        sparse_case = len(full_text.strip()) < 90 and not self._CLINICAL_DETAIL_RE.search(full_text)
        if sparse_case:
            return ["What are the key case details — exact diagnosis, clinical presentation, and the main management question?"]

        anchor_terms = self._case_anchor_terms(full_text)
        questions = []

        def _has(pattern: str) -> bool:
            return bool(re.search(pattern, full_text, re.IGNORECASE))

        if "aorta" in anchor_terms:
            fistula_context = _has(r"haematemesis|haemoptysis|haemorrhag.{0,60}(?:esophag|oesophag|bronch|tracheo)|(?:esophag|oesophag|bronch|tracheo).{0,60}haemorrhag|aorto.{0,15}(?:esophageal|oesophageal|enteric|bronchial)")
            has_infection_signs = _has(r"fever|sepsis|\bcrp\b|\bwbc\b|leukocyt|white\s+cell|infect|abscess|sinus\s+tract")
            has_imaging = _has(r"\bcta\b|\bct\s+(?:scan|angio|chest)\b|\bpet\b|endoscopy|upper\s+gi|imaging\s+(?:show|reveal|confirm)")
            has_complication = _has(r"complicated|uncomplicated|malperfusion|ruptur|symptom|asymptom|expand|enlarg|pain")
            has_size = _has(r"\d[\.,]\d\s*cm\b|\d{2,3}\s*mm\b|diameter|maximum\s+size")
            has_fitness = _has(r"fit\b|unfit|comorbid|cardiac|renal|pulmon|surgical\s+risk|asa\s+class|frail")
            has_prosthesis = _has(r"tevar|evar|endograft|graft|prosth|stent\s*graft|implant|repair\s+(?:was|done|performed)")

            if fistula_context:
                if not has_imaging:
                    questions.append("Has CT angiography or upper endoscopy confirmed communication between the aorta/endograft and the oesophagus or airway?")
                if not has_infection_signs:
                    questions.append("Are there signs of systemic infection — fever, raised CRP/WBC? Is the patient haemodynamically stable?")
                if not has_prosthesis:
                    questions.append("What type of aortic repair was performed, when was it done, and what prosthesis was used?")
            elif _has(r"dissect"):
                if not has_complication:
                    questions.append("Is this complicated (malperfusion, haemodynamic instability, refractory pain, rapid expansion) or uncomplicated?")
                if not _has(r"\bacute\b|\bsubacute\b|\bchronic\b|\d+\s*days?\b|\d+\s*weeks?\b"):
                    questions.append("When did symptoms start — acute (<14 days), subacute (14–90 days), or chronic (>90 days)?")
            else:
                if not has_size:
                    questions.append("What is the maximum aortic diameter or lesion extent (cm/mm)?")
                if not has_complication:
                    questions.append("Is this symptomatic or complicated (pain, haemodynamic instability, rapid growth), or an elective finding?")
                if not has_fitness:
                    questions.append("Any significant comorbidities affecting fitness for open or endovascular repair?")

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

        elif "renal_mesenteric" in anchor_terms:
            has_acuity = _has(r"\bacute\b|\bchronic\b|\bsudden\b|hours?|days?|angina|postprandial|weight\s+loss")
            has_imaging = _has(r"\bcta\b|\bduplex\b|\bmra\b|\bangio\b|imaging|flow|stenosis\s+(?:grade|degree|\d+\s*%)")

            if not has_acuity:
                questions.append("Is this acute (sudden-onset pain, bowel ischaemia) or chronic mesenteric/renal ischaemia (postprandial angina, weight loss, progressive renal impairment)?")
            if not has_imaging:
                questions.append("Has vascular imaging been performed — CTA, MRA, or duplex — and what is the degree of stenosis or occlusion?")

        else:
            if not _has(r"\bcta\b|\bct\b|\bduplex\b|\bmri\b|imaging|scan|findings"):
                questions.append("What imaging has been performed and what are the key findings?")
            if not _has(r"anticoag|heparin|lmwh|doac|warfarin|comorbid|renal|cardiac|prior\s+(?:intervention|surgery|bypass)"):
                questions.append("Is the patient on anticoagulation or antiplatelet therapy? Any relevant comorbidities or prior vascular interventions?")

        if not questions:
            questions.append("What is the single most important clinical detail that determines the guideline recommendation for this specific case?")

        return questions[:3]

    def _assess_context_gaps(self, question: str, history: list) -> tuple:
        """Check if a patient-case question is missing key clinical parameters.

        Returns (scenario_id, [question_strings]) if gaps were found, else ('', []).
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

        generic_questions = self._generic_case_follow_up_questions(full_text)
        if generic_questions:
            return "generic_case", generic_questions

        return "", []

    def _format_context_request(
        self,
        gap_questions: list,
        question: str,
        history: Optional[list] = None,
        scenario_id: str = "",
    ) -> str:
        """Return a tool response that instructs the LLM to ask expert clarification questions."""
        context_lines = []
        current = (question or "").strip()
        if current:
            context_lines.append(f"- Case: {current}")

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
            "GUIDELINE_RETRIEVAL_PAUSED — one or two clinical details are needed before ESVS retrieval.",
            "",
            "MANDATORY BEHAVIOR (no exceptions):",
            "1. Your entire reply to the user must be a focused clinical clarification — nothing else.",
            "2. Do NOT answer the question or offer any guideline recommendation yet.",
            "3. Do NOT say the scenario is or is not addressed by guidelines.",
            "4. Do NOT mention evidence gaps or tool internals.",
            "5. OPEN with 1-2 sentences that show your clinical understanding of the case:",
            "   (a) Name the exact diagnosis, anatomy, and any complications ALREADY described.",
            "       If stroke, carotid involvement, malperfusion, rupture, haemodynamic instability,",
            "       thrombus, or other complications are in the description — state them as FACTS",
            "       you have understood, not as open questions.",
            "   (b) Then state you need one or two additional details before retrieving guidelines.",
            "   EXAMPLE: 'From your description, I understand this is an acute aortic dissection with",
            "   arch involvement, complicated by carotid dissection and ischaemic stroke — this is",
            "   already a complicated presentation. Before I search the ESVS guidelines, I need to",
            "   clarify two things...'",
            "6. CRITICAL — Do NOT re-ask about features already present in the description.",
            "   If the case already mentions stroke, malperfusion, carotid involvement, rupture, or",
            "   haemodynamic instability — these are KNOWN. Only ask for parameters that are",
            "   genuinely absent and would materially change which evidence is retrieved.",
            "7. Act as a vascular surgery consultant asking a colleague for the key missing facts.",
            "   Use direct, natural language tailored to this specific case.",
            "8. Ask only what genuinely changes the guideline recommendation — typically 1–2 questions.",
            "9. Use plain conversational phrasing; avoid stiff bullet headers like '**Topic**:'.",
            "",
        ]
        if context_lines:
            lines.append("KNOWN CASE CONTEXT:")
            lines.extend(context_lines)
            lines.append("")
        lines += [
            "CLINICAL GAPS TO ADDRESS:",
            "The following are the MISSING parameters — skip any already answered in the case description.",
            "Adapt the wording to this specific case; do not copy them verbatim:",
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

    # ------------------------------------------------------------------ #
    # Same-case follow-up rewrite (from vascular_expert.py)               #
    # ------------------------------------------------------------------ #

    def _expand_retrieval_abbreviations(self, text: str) -> str:
        expanded = text or ""
        for pattern, replacement in [
            (r"\bCEA\b",   "carotid endarterectomy (CEA)"),
            (r"\bCAS\b",   "carotid artery stenting (CAS)"),
            (r"\bTCAR\b",  "transcarotid artery revascularisation (TCAR)"),
            (r"\bEVAR\b",  "endovascular aneurysm repair (EVAR)"),
            (r"\bTEVAR\b", "thoracic endovascular aortic repair (TEVAR)"),
            (r"\bDVT\b",   "deep vein thrombosis (DVT)"),
            (r"\bmRS\b",   "modified Rankin score (mRS)"),
        ]:
            expanded = re.sub(pattern, replacement, expanded)
        return self._normalize_space(expanded)

    def _enrich_dissection_query(self, text: str) -> str:
        """Append clinically precise terminology for arch/zone dissections and complications.

        Detects non-A non-B dissection language (origin in Zone 2, just above/proximal to
        the left subclavian) and appends the correct ESVS terminology so retrieval surfaces
        non-A non-B recommendations rather than generic type B recommendations.
        Also enriches malperfusion/complicated language.
        """
        if not text or not re.search(r"\bdissect", text, re.IGNORECASE):
            return text

        additions = []

        # Zone 2 / arch-origin dissection → non-A non-B
        arch_origin = re.search(
            r"\b(just\s+above|proximal\s+to|above\s+the|at\s+the|near\s+the)"
            r".{0,30}\bleft\s+subclavian\b"
            r"|\bzone\s*2\b"
            r"|\barch.{0,20}dissect"
            r"|\bdissect.{0,20}arch\b",
            text,
            re.IGNORECASE,
        )
        if arch_origin and not re.search(r"\btype\s*a\b|\bascending\b", text, re.IGNORECASE):
            if not re.search(r"non.?a.?non.?b", text, re.IGNORECASE):
                additions.append(
                    "non-A non-B aortic dissection zone 2 arch involvement"
                )

        # Carotid dissection or involvement in aortic context → malperfusion
        if re.search(r"\bcarotid.{0,30}dissect|dissect.{0,30}carotid", text, re.IGNORECASE):
            if not re.search(r"\bmalperfusion\b", text, re.IGNORECASE):
                additions.append("malperfusion carotid")

        # Stroke in aortic dissection context = cerebral malperfusion
        if re.search(r"\bstroke\b|\bcerebral\s+isch", text, re.IGNORECASE):
            if not re.search(r"\bcerebral\s+malperfusion\b", text, re.IGNORECASE):
                additions.append("cerebral malperfusion complicated")

        if not additions:
            return text

        return self._normalize_space(text + " " + " ".join(additions))

    def _last_case_gate_index(self, messages: Optional[list]) -> int:
        for idx in range(len(messages or []) - 1, -1, -1):
            msg = (messages or [])[idx]
            if isinstance(msg, dict) and msg.get("role") == "assistant":
                if self._CASE_GATE_ASSISTANT_RE.search(self._message_text(msg)):
                    return idx
        return -1

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

    def _extract_answered_clarifications(self, messages: Optional[list]) -> tuple:
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

    def _extract_previous_references(self, messages: Optional[list]) -> tuple:
        recommendation_refs = []
        citation_refs = []
        seen_recommendations = set()
        seen_citations = set()
        for message in messages or []:
            if not isinstance(message, dict) or message.get("role") != "assistant":
                continue
            if self._CASE_GATE_ASSISTANT_RE.search(self._message_text(message)):
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
            return GUIDELINE_NAMES.get(ordered[0], ordered[0])
        anchors = sorted(self._case_anchor_terms(combined_text))
        if anchors:
            return ", ".join(anchors)
        return "general vascular"

    def _build_conversation_state(
        self,
        question: str,
        messages: Optional[list],
        guidelines: Optional[list],
    ) -> dict:
        current_norm = self._normalize_space(question)
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

        context_summary = self._truncate_case_text("; ".join(p for p in context_parts if p), 1000)
        current_guidelines = self._extract_case_guidelines(case_messages, guidelines)
        topic = self._topic_from_state(
            " ".join(prior_user_contexts + [current_norm]), current_guidelines
        )
        return {
            "current_guidelines":        current_guidelines,
            "current_topic":             topic,
            "patient_problem_context":   context_summary,
            "anchor_question":           anchor_question,
            "clarified_facts":           clarified_facts,
            "previous_recommendations":  recommendation_refs[: self.CASE_STATE_MAX_REFERENCE_ITEMS],
            "previously_cited_chunks":   citation_refs[: self.CASE_STATE_MAX_REFERENCE_ITEMS],
            "unanswered_subquestions":   unanswered[: self.CASE_STATE_MAX_REFERENCE_ITEMS],
            "prior_user_contexts":       prior_user_contexts[-self.CASE_STATE_MAX_CONTEXT_ITEMS:],
        }

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

    def _should_rewrite_for_retrieval(self, question: str, state: dict) -> bool:
        if self._EXPLICIT_NEW_CASE_RE.search(question or ""):
            return False
        if self._looks_like_fresh_case_intro(question or ""):
            return False
        return bool(state.get("prior_user_contexts") or state.get("clarified_facts"))

    def _prepare_retrieval_query(self, question: str, state: dict) -> tuple:
        if not self._should_rewrite_for_retrieval(question, state):
            return self._expand_retrieval_abbreviations(question), False

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

        rewritten = self._truncate_case_text(
            " ".join(p for p in context_parts if p),
            self.CASE_STATE_MAX_QUERY_CHARS,
        )
        result = rewritten or self._expand_retrieval_abbreviations(question)
        return self._enrich_dissection_query(result), True

    # ------------------------------------------------------------------ #
    # Output enhancement                                                   #
    # ------------------------------------------------------------------ #

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
            q,
        ))

    def _needs_stroke_severity_scope(self, question: str, guidelines: Optional[list] = None) -> bool:
        q = (question or "").strip()
        if not q:
            return False
        has_carotid = "carotid_vertebral" in (guidelines or []) or bool(
            re.search(r"\b(carotid|cea|cas|tcar|endarterectomy|carotid\s+stenting)\b", q, re.IGNORECASE)
        )
        if not has_carotid:
            return False
        return bool(self._CAROTID_SEVERE_STROKE_RE.search(q))

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
        emitter = __event_emitter__
        guidelines = [g for g in [guideline_1, guideline_2, guideline_3] if g]
        gdisplay = ', '.join(GUIDELINE_NAMES.get(g, g) for g in guidelines)

        # ---- Gate: context gap check ---------------------------------- #
        # Extract text from prior messages for history context.
        history_texts = []
        for m in (__messages__ or []):
            if not isinstance(m, dict):
                continue
            content = m.get('content', '')
            if isinstance(content, list):
                content = ' '.join(
                    p.get('text', '') for p in content if isinstance(p, dict)
                )
            if isinstance(content, str) and content.strip():
                history_texts.append(content.strip())

        # Only fire the gate once per case. If a gate clarification already
        # appears in the conversation history, skip re-firing.
        gate_already_fired = any(
            self._CASE_GATE_ASSISTANT_RE.search(t) for t in history_texts
        )
        if not gate_already_fired:
            scenario_id, gap_questions = self._assess_context_gaps(question, history_texts)
            if gap_questions:
                await self._emit_status(emitter, 'Clarifying clinical context before retrieval...')
                return self._format_context_request(gap_questions, question, history_texts, scenario_id)

        # ---- 9.1 Conversation state + query rewrite ------------------- #
        state = self._build_conversation_state(question, __messages__, guidelines)
        retrieval_question, was_rewritten = self._prepare_retrieval_query(question, state)
        if not was_rewritten:
            retrieval_question = self._enrich_dissection_query(retrieval_question)

        retrieval_history = []
        for m in (__messages__ or []):
            txt = self._message_text(m) if isinstance(m, dict) else str(m)
            if txt.strip():
                retrieval_history.append(txt.strip())

        await self._emit_status(emitter, f'Selecting guidelines: {gdisplay}')

        url = f'{self.valves.VASCULAR_API_BASE_URL}/api/v1/vascular-consult'
        headers = {
            'Authorization': f'Bearer {self.valves.VASCULAR_API_KEY}',
            'Content-Type': 'application/json',
        }
        payload = {
            'question': retrieval_question,
            'history':  retrieval_history[-20:],
            'guidelines': guidelines,
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

            requires_decision_summary = self._requires_clinical_decision_summary(
                retrieval_question, data.get('intent_profile')
            )
            needs_stroke_scope = self._needs_stroke_severity_scope(retrieval_question, guidelines)

            elapsed = int(time.time() - start)
            await self._emit_status(emitter, f'✅ Retrieved in {elapsed}s — parsing results...')

            # ---- 9.2 Chunk assignment ---------------------------------- #
            llm_cit = data.get('llm_citation_chunks', [])
            llm_nar = data.get('llm_narrative_chunks', [])
            ui_cit  = data.get('ui_citation_chunks', [])
            ui_nar  = data.get('ui_narrative_chunks', [])
            assets  = data.get('assets', [])
            q_norm  = data.get('query_normalization', {})

            llm_cit_ids = {c.get('recommendation_id') or c.get('content', '')[:40] for c in llm_cit}
            llm_nar_ids = {c.get('content', '')[:40] for c in llm_nar}
            extra_ui_cit = [c for c in ui_cit if (c.get('recommendation_id') or c.get('content', '')[:40]) not in llm_cit_ids]
            extra_ui_nar = [c for c in ui_nar if c.get('content', '')[:40] not in llm_nar_ids]

            llm_total     = len(llm_cit) + len(llm_nar)
            ui_total      = len(ui_cit) + len(ui_nar)
            backend_total = len(data.get('citation_chunks', [])) + len(data.get('narrative_chunks', []))

            # ---- 9.3 Emit citation events (four passes) ---------------- #
            if emitter:
                chunk_number = 1

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

            if isinstance(q_norm, dict):
                frame = str(q_norm.get('clinical_frame') or '').strip()
                if frame:
                    llm_out += '=== CLINICAL FRAME (INTERPRETIVE / NON-GUIDELINE) ===\n'
                    llm_out += frame + '\n'
                    llm_out += (
                        'Guidance: You may include a brief interpretive framing note, '
                        'clearly labeled as non-guideline and without citations.\n\n'
                    )

            assets_block = self._format_assets_markdown(assets)
            if assets_block:
                llm_out += assets_block

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

            if llm_nar:
                llm_out += '=== NARRATIVE CONTEXT ===\n'
                nar_i = 1
                for chunk in llm_nar:
                    content = chunk.get('content', '')[:1500]
                    src_gl  = chunk.get('source_guideline', 'ESVS')
                    llm_out += f'[{chunk_num}] {src_gl} — Narrative {nar_i}\n{content}\n\n'
                    chunk_num += 1
                    nar_i += 1

            if requires_decision_summary:
                llm_out += '=== CLINICAL DECISION SUMMARY (REQUIRED) ===\n'
                llm_out += 'For management/treatment/clinical strategy questions, conclude with a section titled exactly: **Clinical Decision Summary**.\n'
                llm_out += 'Using the retrieved guideline evidence, you must:\n'
                llm_out += '1. Determine whether treatment thresholds are met.\n'
                llm_out += '2. Interpret the anatomical features provided.\n'
                llm_out += '3. Compare available treatment strategies supported by evidence.\n'
                llm_out += '4. State the guideline-consistent default/preferred strategy when inferable.\n'
                llm_out += '5. Explain why this strategy is preferred and identify the main alternative with when it may be chosen instead.\n'
                llm_out += "Do not stop at 'both options may be considered'; provide a reasoned decision.\n"
                llm_out += 'If anatomical measurements are provided (e.g., neck length, angulation, landing zones), interpret compatibility with standard EVAR, fenestrated/branched repair, and open repair.\n\n'
                llm_out += '=== PERIOPERATIVE RISK MITIGATION (GUIDELINE-BASED, REQUIRED) ===\n'
                llm_out += 'When discussing operative management, summarize key perioperative risk-reduction strategies, including when relevant:\n'
                llm_out += '- spinal cord ischemia prevention\n'
                llm_out += '- renal protection\n'
                llm_out += '- cardiac risk optimisation\n'
                llm_out += '- staged repair strategies\n'
                llm_out += '- preservation of critical branch vessels\n\n'

            if needs_stroke_scope:
                llm_out += '=== STROKE SEVERITY SCOPE (CASE-SPECIFIC) ===\n'
                llm_out += 'This case signals major/disabling stroke or severe neurological deficit.\n'
                llm_out += 'Prefer evidence that explicitly addresses disabling or major stroke.\n'
                llm_out += 'Do NOT apply TIA, minor-stroke, or non-disabling-stroke carotid intervention timing recommendations unless the retrieved evidence explicitly says they apply to this severity.\n\n'

            llm_out += '=== CITATION RULES ===\n'
            llm_out += '1. Use inline citations [1],[2] after each fact.\n'
            llm_out += '2. Cite only sources you actually use.\n'
            llm_out += '3. Do NOT add a References section — UI shows Sources list.\n'
            llm_out += '4. Match [n] numbers exactly to the evidence blocks above.\n'
            llm_out += '5. If evidence spans multiple guidelines, cite at least one recommendation from each used.\n'
            llm_out += (
                '6. SCOPE FILTER: before citing a recommendation, verify it directly addresses '
                'this specific case. Exclude recommendations for a different procedure or condition. '
                'When no directly applicable recommendation was retrieved, state this explicitly.\n'
            )
            if assets_block:
                llm_out += (
                    '7. Include a section titled exactly: 🖼️ Figures / Tables '
                    'and copy ALL markdown image lines from the FIGURES block verbatim.\n'
                )

            llm_out += '\n=== REQUIRED STRUCTURE (STRICT) ===\n'
            llm_out += 'IMPORTANT: Restrict every section to evidence DIRECTLY relevant to this case. Acknowledge evidence gaps in \'Evidence used\' rather than citing tangential recommendations.\n'
            llm_out += 'Assessment:\n'
            llm_out += 'Imaging:\n'
            llm_out += 'Indication for intervention:\n'
            llm_out += 'Treatment options:\n'
            if requires_decision_summary:
                llm_out += 'Clinical Decision Summary:\n'
                llm_out += 'Perioperative Risk Mitigation:\n'
            llm_out += 'Follow-up:\n'
            llm_out += 'Evidence used (Rec #, Class, Level):\n'

            return llm_out

        except httpx.TimeoutException:
            await self._emit_status(emitter, 'Request timed out', done=True)
            return 'Error: Request timed out after 120 seconds'
        except httpx.HTTPStatusError as e:
            await self._emit_status(emitter, f'API error: {e.response.status_code}', done=True)
            return f'Error: HTTP {e.response.status_code}'
        except Exception as e:
            await self._emit_status(emitter, f'Error: {str(e)[:50]}', done=True)
            return f'Error: {str(e)}'
