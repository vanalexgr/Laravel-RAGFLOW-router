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

        # ---- 9.1 Setup and HTTP call ---------------------------------- #
        await self._emit_status(emitter, f'Selecting guidelines: {gdisplay}')

        url = f'{self.valves.VASCULAR_API_BASE_URL}/api/v1/vascular-consult'
        headers = {
            'Authorization': f'Bearer {self.valves.VASCULAR_API_KEY}',
            'Content-Type': 'application/json',
        }
        payload = {
            'question': question,
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

            llm_out += '\n=== REQUIRED STRUCTURE (STRICT) ===\n'
            llm_out += 'Restrict every section to evidence directly relevant to this case.\n'
            llm_out += 'Assessment:\nImaging:\nIndication for intervention:\n'
            llm_out += 'Treatment options:\nFollow-up:\nEvidence used (Rec #, Class, Level):\n'

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
