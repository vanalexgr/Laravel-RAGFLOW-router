import os
import logging
from typing import Optional
from semantic_router import Route
from semantic_router.routers import SemanticRouter
from semantic_router.encoders import FastEmbedEncoder

logger = logging.getLogger("semantic_router_service")

GUIDELINES_CONFIG = {
    "aortic_arch": {
        "id": "be20b02cdc4311f09021f2381272676b",
        "name": "Aortic Arch",
        "utterances": [
            "aortic arch aneurysm",
            "arch penetrating atherosclerotic ulcer PAU",
            "intramural haematoma IMH arch",
            "arch dissection Type A arch involvement",
            "supra-aortic trunk debranching",
            "zone 0 zone 1 zone 2 landing",
            "frozen elephant trunk FET",
            "hybrid arch repair",
            "arch branch stent-graft branched arch",
            "chimney to innominate carotid",
            "carotid-subclavian bypass",
            "left subclavian artery LSA revascularisation",
            "cerebral protection antegrade cerebral perfusion",
            "embolic stroke risk in arch repair",
            "bovine arch anatomy",
        ],
    },
    "descending_thoracic_aorta": {
        "id": "fd679d82dc3311f09021f2381272676b",
        "name": "Descending Thoracic Aorta",
        "utterances": [
            "TEVAR thoracic endovascular aortic repair",
            "Stanford type B dissection TBAD",
            "complicated TBAD malperfusion rupture",
            "thoracic aortic aneurysm DTAA",
            "traumatic thoracic aortic injury BTAI",
            "thoracic PAU",
            "thoracic IMH",
            "distal re-entry false lumen thrombosis",
            "spinal cord ischaemia SCI",
            "cerebrospinal fluid drainage CSFD",
            "left subclavian coverage",
            "proximal distal seal zone thoracic",
            "iliac conduit for TEVAR access",
            "endoleak type I III after TEVAR",
            "stent-graft induced new entry SINE",
        ],
    },
    "abdominal_aortic_aneurysm": {
        "id": "1e8b73dcf49911f09b845ef3771a102d",
        "name": "Abdominal Aortic Aneurysm",
        "utterances": [
            "infrarenal AAA",
            "juxtarenal AAA",
            "suprarenal fixation",
            "EVAR endovascular aneurysm repair",
            "open AAA repair",
            "ruptured AAA rAAA",
            "hostile neck short angulated conical",
            "fenestrated EVAR FEVAR",
            "branched EVAR BEVAR",
            "aorto-uni-iliac AUI fem-fem bypass",
            "endoleak type I II III",
            "aneurysm sac expansion",
            "iliac aneurysm iliac seal zone",
            "internal iliac artery embolisation coverage",
            "abdominal compartment syndrome post-rAAA",
        ],
    },
    "mesenteric_renal": {
        "id": "d94f2a06dc4111f09021f2381272676b",
        "name": "Mesenteric & Renal",
        "utterances": [
            "chronic mesenteric ischaemia CMI",
            "acute mesenteric ischaemia AMI",
            "SMA stenosis occlusion",
            "celiac artery stenosis",
            "median arcuate ligament syndrome MALS",
            "mesenteric stenting SMA stent",
            "mesenteric bypass antegrade retrograde",
            "non-occlusive mesenteric ischaemia NOMI",
            "renal artery stenosis RAS",
            "fibromuscular dysplasia FMD renal",
            "renal artery aneurysm",
            "renal infarction renal embolism",
            "atheroembolic renal disease",
            "renovascular hypertension",
            "visceral artery aneurysm splenic hepatic",
        ],
    },
    "carotid_vertebral": {
        "id": "29b2a1e84ed111f0b3bb3aabfab5e99c",
        "name": "Carotid & Vertebral",
        "utterances": [
            "carotid endarterectomy CEA",
            "carotid artery stenting CAS",
            "symptomatic carotid stenosis",
            "asymptomatic carotid stenosis",
            "NASCET grading",
            "near-occlusion carotid",
            "contralateral carotid occlusion",
            "carotid restenosis",
            "plaque ulceration intraplaque haemorrhage",
            "cerebral embolic protection device",
            "transcarotid artery revascularisation TCAR",
            "vertebral artery origin stenosis",
            "posterior circulation TIA stroke",
            "subclavian steal syndrome",
            "cranial nerve injury post-CEA",
        ],
    },
    "asymptomatic_pad": {
        "id": "c7c42f76507211f0b6356a892e29a549",
        "name": "Asymptomatic PAD",
        "utterances": [
            "asymptomatic lower limb PAD",
            "ankle-brachial index ABI screening",
            "toe-brachial index TBI",
            "intermittent claudication vs asymptomatic",
            "cardiovascular risk stratification PAD",
            "best medical therapy BMT",
            "supervised exercise therapy SET prevention conditioning",
            "smoking cessation PAD",
            "statin intensity PAD",
            "diabetes PAD screening",
            "carotid bruit polyvascular disease linkage",
            "antiplatelet primary prevention in PAD",
            "high-risk plaque burden calcification",
            "pulse examination femoral popliteal",
            "opportunistic ABI in high-risk cohorts",
        ],
    },
    "clti": {
        "id": "acd1930edc3411f09021f2381272676b",
        "name": "Chronic Limb-Threatening Ischemia",
        "utterances": [
            "chronic limb-threatening ischaemia CLTI",
            "Rutherford 4 5 6",
            "WIfI classification",
            "GLASS staging",
            "angiosome-directed revascularisation",
            "infrapopliteal tibial intervention",
            "pedal loop plantar arch",
            "endovascular-first strategy",
            "bypass-first autologous vein",
            "great saphenous vein GSV conduit",
            "minor amputation transmetatarsal amputation TMA",
            "wound care offloading",
            "toe pressure TcPO2",
            "no-option CLTI",
            "limb salvage endpoint",
        ],
    },
    "acute_limb_ischaemia": {
        "id": "7dcce66ef3eb11f0b82c5ef3771a102d",
        "name": "Acute Limb Ischaemia",
        "utterances": [
            "acute limb ischaemia ALI",
            "Rutherford I IIa IIb III",
            "embolus vs thrombosis in-situ",
            "urgent heparinisation",
            "Fogarty embolectomy",
            "catheter-directed thrombolysis CDT",
            "mechanical thrombectomy peripheral",
            "acute bypass graft occlusion",
            "acute stent thrombosis",
            "compartment syndrome fasciotomy",
            "reperfusion injury hyperkalaemia",
            "on-table angiography",
            "popliteal artery occlusion acute",
            "atrial fibrillation embolism source",
            "viability assessment motor sensory deficit",
        ],
    },
    "antithrombotic_therapy": {
        "id": "b404c5e0585611f0b053823a24ef0d59",
        "name": "Antithrombotic Therapy",
        "utterances": [
            "single antiplatelet therapy SAPT",
            "dual antiplatelet therapy DAPT",
            "aspirin clopidogrel",
            "DOAC apixaban rivaroxaban edoxaban dabigatran",
            "vitamin K antagonist VKA warfarin",
            "low-dose rivaroxaban aspirin dual pathway inhibition",
            "perioperative antithrombotic management",
            "bridging anticoagulation LMWH",
            "heparin-induced thrombocytopenia HIT",
            "antiplatelet after peripheral stenting",
            "anticoagulation after AF PAD",
            "bleeding risk HAS-BLED",
            "antithrombotic after bypass graft",
            "antithrombotic after EVAR TEVAR",
            "duration of therapy de-escalation",
        ],
    },
    "venous_thrombosis": {
        "id": "7104532adc4311f09021f2381272676b",
        "name": "Venous Thrombosis (DVT/PE)",
        "utterances": [
            "proximal DVT iliofemoral",
            "distal calf DVT",
            "pulmonary embolism PE",
            "provoked vs unprovoked VTE",
            "Wells score",
            "D-dimer strategy",
            "compression ultrasonography CUS",
            "thrombus extension surveillance",
            "catheter-directed thrombolysis iliofemoral DVT",
            "pharmacomechanical thrombectomy PMT",
            "post-thrombotic syndrome PTS",
            "inferior vena cava filter IVC filter",
            "cancer-associated thrombosis",
            "pregnancy-associated VTE",
            "anticoagulation duration 3 months extended",
        ],
    },
    "chronic_venous_disease": {
        "id": "ecb621444d8f11f09f7a2e382eabde98",
        "name": "Chronic Venous Disease",
        "utterances": [
            "chronic venous insufficiency CVI",
            "CEAP classification",
            "venous reflux GSV SSV",
            "saphenofemoral junction SFJ incompetence",
            "endovenous thermal ablation EVLA RFA",
            "ultrasound-guided foam sclerotherapy UGFS",
            "perforator incompetence",
            "iliac vein obstruction May-Thurner",
            "venous stenting iliocaval",
            "venous leg ulcer VLU",
            "compression therapy class II III stockings",
            "ambulatory venous hypertension",
            "lipodermatosclerosis",
            "corona phlebectatica",
            "recurrent varicose veins REVAS",
        ],
    },
    "vascular_trauma": {
        "id": "8f58aeadec9411f0a38066bc68590b9b",
        "name": "Vascular Trauma",
        "utterances": [
            "blunt vascular injury",
            "penetrating vascular injury",
            "hard signs soft signs vascular injury",
            "extremity arterial trauma",
            "temporary intravascular shunt TIVS",
            "damage control surgery",
            "tourniquet haemorrhage control",
            "REBOA resuscitative endovascular balloon occlusion",
            "pseudoaneurysm post-trauma",
            "arteriovenous fistula traumatic AVF",
            "intimal flap dissection trauma",
            "compartment syndrome trauma",
            "fasciotomy indications",
            "combined ortho-vascular mangled extremity",
            "vascular imaging in trauma CTA",
        ],
    },
    "vascular_graft_infections": {
        "id": "29981e72dc4311f09021f2381272676b",
        "name": "Vascular Graft Infections",
        "utterances": [
            "vascular graft infection VGI",
            "aortic graft infection",
            "groin graft infection",
            "biofilm staphylococcus aureus epidermidis",
            "Samson classification Szilagyi groin",
            "explantation of infected graft",
            "in situ reconstruction ISR",
            "extra-anatomic bypass EAB",
            "rifampicin-bonded graft",
            "cryopreserved allograft",
            "autologous vein reconstruction NAIS",
            "omental flap coverage",
            "long-term suppressive antibiotics",
            "aorto-enteric fistula AEF",
            "PET-CT for graft infection",
        ],
    },
    "vascular_access": {
        "id": "bbe0b3a0f39611f08b265ef3771a102d",
        "name": "Vascular Access",
        "utterances": [
            "arteriovenous fistula AVF",
            "arteriovenous graft AVG",
            "tunneled dialysis catheter TDC",
            "radiocephalic fistula RCF",
            "brachiocephalic fistula BCF",
            "brachiobasilic transposition BBT",
            "access maturation failure",
            "juxta-anastomotic stenosis",
            "access flow Qa surveillance",
            "steal syndrome DASS",
            "high-output cardiac failure access-related",
            "aneurysmal fistula degeneration",
            "central venous stenosis occlusion CVS",
            "percutaneous transluminal angioplasty PTA of access",
            "thrombectomy of AV access",
        ],
    },
}


class SemanticRouterService:
    _instance: Optional["SemanticRouterService"] = None
    _router: Optional[SemanticRouter] = None
    _initialized: bool = False

    def __new__(cls):
        if cls._instance is None:
            cls._instance = super().__new__(cls)
        return cls._instance

    def initialize(self):
        if self._initialized:
            logger.info("SemanticRouterService already initialized")
            return

        logger.info("Initializing SemanticRouterService with FastEmbed (local embeddings)...")

        try:
            encoder = FastEmbedEncoder()

            routes = []
            for key, config in GUIDELINES_CONFIG.items():
                route = Route(
                    name=key,
                    utterances=config["utterances"],
                )
                routes.append(route)
                logger.info(f"  Created route: {key} ({len(config['utterances'])} utterances)")

            self._router = SemanticRouter(
                encoder=encoder,
                routes=routes,
                auto_sync="local",
            )

            self._initialized = True
            logger.info(f"SemanticRouterService initialized with {len(routes)} routes")

        except Exception as e:
            logger.error(f"Failed to initialize SemanticRouterService: {e}")
            raise

    @property
    def is_initialized(self) -> bool:
        return self._initialized

    def route(self, query: str, top_k: int = 1) -> list[dict]:
        if not self._initialized or not self._router:
            raise RuntimeError("SemanticRouterService not initialized")

        result = self._router(query)

        matched_key = None
        confidence = None

        if isinstance(result, list) and len(result) > 0:
            first = result[0]
            matched_key = getattr(first, "name", None)
            confidence = getattr(first, "similarity", None) or getattr(first, "score", None)
        elif hasattr(result, "name"):
            matched_key = result.name  # type: ignore
            confidence = getattr(result, "similarity", None) or getattr(result, "score", None)

        if matched_key is None:
            return []

        if matched_key in GUIDELINES_CONFIG:
            config = GUIDELINES_CONFIG[matched_key]
            return [{
                "guideline_key": matched_key,
                "guideline_id": config["id"],
                "guideline_name": config["name"],
                "confidence": confidence,
            }]

        return []

    def route_multi(self, query: str, max_routes: int = 4, min_score_threshold: float = 0.68, min_confidence: float = 0.35) -> list[dict]:
        """
        Route a query to multiple guidelines using absolute score floor selection.
        
        Selection logic:
        1. Always include the top matching guideline (primary)
        2. Include ALL additional guidelines scoring above min_score_threshold (secondaries)
        3. This ensures complex multi-domain queries get all relevant guidelines
        4. Primary guideline is marked with is_primary=True for weighted chunk allocation
        
        Args:
            query: The clinical question
            max_routes: Maximum number of guidelines to return (default 4)
            min_score_threshold: Absolute score floor - include all guidelines above this (default 0.70)
            min_confidence: Minimum absolute similarity threshold for any result (default 0.35)
        """
        if not self._initialized or not self._router:
            raise RuntimeError("SemanticRouterService not initialized")

        try:
            import numpy as np
            
            query_embedding = np.array(self._router.encoder([query])[0])
            
            route_scores = []
            index = self._router.index
            
            if hasattr(index, 'index') and index.index is not None:
                all_embeddings = np.array(index.index)
                route_names = index.routes if hasattr(index, 'routes') else []
                
                for i, emb in enumerate(all_embeddings):
                    similarity = float(np.dot(query_embedding, emb) / (np.linalg.norm(query_embedding) * np.linalg.norm(emb)))
                    if i < len(route_names):
                        route_scores.append((route_names[i], similarity))
                
                aggregated = {}
                for route_name, score in route_scores:
                    if route_name not in aggregated:
                        aggregated[route_name] = score
                    else:
                        aggregated[route_name] = max(aggregated[route_name], score)
                
                sorted_routes = sorted(aggregated.items(), key=lambda x: x[1], reverse=True)
                
                if not sorted_routes:
                    return self.route(query, top_k=1)
                
                results = []
                for idx, (route_name, score) in enumerate(sorted_routes[:max_routes]):
                    # Include if: (1) it's the top scorer, OR (2) score is above absolute threshold
                    is_top = (idx == 0)
                    above_floor = (score >= min_score_threshold)
                    
                    if (is_top or above_floor) and score >= min_confidence and route_name in GUIDELINES_CONFIG:
                        config = GUIDELINES_CONFIG[route_name]
                        results.append({
                            "guideline_key": route_name,
                            "guideline_id": config["id"],
                            "guideline_name": config["name"],
                            "confidence": round(score, 4),
                            "is_primary": is_top,
                        })
                
                if results:
                    logger.info(f"route_multi: query='{query[:60]}...', selected={[r['guideline_key'] for r in results]}, scores={[r['confidence'] for r in results]}, floor={min_score_threshold}")
                    return results
            
            return self.route(query, top_k=1)
            
        except Exception as e:
            logger.warning(f"route_multi failed, falling back to single route: {e}")
            return self.route(query, top_k=1)

    def get_guideline_by_key(self, key: str) -> Optional[dict]:
        if key in GUIDELINES_CONFIG:
            config = GUIDELINES_CONFIG[key]
            return {
                "guideline_key": key,
                "guideline_id": config["id"],
                "guideline_name": config["name"],
            }
        return None

    def get_all_guidelines(self) -> list[dict]:
        return [
            {
                "guideline_key": key,
                "guideline_id": config["id"],
                "guideline_name": config["name"],
            }
            for key, config in GUIDELINES_CONFIG.items()
        ]


semantic_router_service = SemanticRouterService()
