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
            "Zone 0-4 anatomy of the aortic arch",
            "Frozen Elephant Trunk procedure",
            "FET technique for aortic arch",
            "Total Endovascular Arch Repair",
            "aortic arch dissection management",
            "arch aneurysm treatment",
            "hybrid arch repair approach",
            "aortic arch surgery zones",
            "elephant trunk procedure",
        ],
    },
    "descending_thoracic_aorta": {
        "id": "fd679d82dc3311f09021f2381272676b",
        "name": "Descending Thoracic Aorta",
        "utterances": [
            "Type B Dissection treatment",
            "TBAD management",
            "Intramural Hematoma IMH",
            "TEVAR for thoracic aorta",
            "Spinal Cord Ischemia prevention",
            "thoracic aneurysm repair",
            "penetrating aortic ulcer",
            "descending thoracic aortic disease",
            "complicated vs uncomplicated type B",
        ],
    },
    "abdominal_aortic_aneurysm": {
        "id": "1e8b73dcf49911f09b845ef3771a102d",
        "name": "Abdominal Aortic Aneurysm",
        "utterances": [
            "EVAR vs open repair for AAA",
            "AAA surveillance criteria",
            "5.5cm threshold for repair",
            "endoleak management",
            "abdominal aneurysm rupture",
            "AAA screening recommendations",
            "infrarenal aneurysm treatment",
            "EVAR complications",
            "open AAA repair indications",
        ],
    },
    "mesenteric_renal": {
        "id": "d94f2a06dc4111f09021f2381272676b",
        "name": "Mesenteric & Renal",
        "utterances": [
            "Chronic Mesenteric Ischemia CMI",
            "Acute Mesenteric Ischemia AMI",
            "Renal Artery Stenosis RAS",
            "visceral aneurysms management",
            "celiac artery disease",
            "SMA stenosis treatment",
            "bowel ischemia diagnosis",
            "mesenteric revascularization",
            "renovascular hypertension",
        ],
    },
    "carotid_vertebral": {
        "id": "29b2a1e84ed111f0b3bb3aabfab5e99c",
        "name": "Carotid & Vertebral",
        "utterances": [
            "Stroke prevention carotid",
            "TIA transient ischemic attack",
            "CEA carotid endarterectomy",
            "CAS carotid stenting",
            "TCAR transcarotid revascularization",
            "symptomatic carotid stenosis",
            "asymptomatic carotid stenosis",
            "carotid surgery timing",
            "vertebral artery stenosis",
        ],
    },
    "asymptomatic_pad": {
        "id": "c7c42f76507211f0b6356a892e29a549",
        "name": "Asymptomatic PAD",
        "utterances": [
            "peripheral arterial disease screening",
            "PAD risk factor management",
            "LEAD lower extremity arterial disease",
            "Supervised Exercise Therapy SET",
            "claudication conservative treatment",
            "intermittent claudication management",
            "walking distance improvement",
            "ABI ankle brachial index screening",
            "asymptomatic PAD treatment",
        ],
    },
    "clti": {
        "id": "acd1930edc3411f09021f2381272676b",
        "name": "Chronic Limb-Threatening Ischemia",
        "utterances": [
            "WIfI classification system",
            "angiosome-directed revascularization",
            "heel ulcer treatment",
            "tissue loss ischemic",
            "rest pain management",
            "gangrene limb salvage",
            "critical limb ischemia CLI",
            "CLTI revascularization",
            "limb salvage vs amputation",
        ],
    },
    "acute_limb_ischaemia": {
        "id": "7dcce66ef3eb11f0b82c5ef3771a102d",
        "name": "Acute Limb Ischaemia",
        "utterances": [
            "6 Ps of acute limb ischemia",
            "Rutherford classification for acute limb ischemia",
            "Rutherford categories ALI severity",
            "thrombolysis for acute ischemia",
            "embolectomy procedure",
            "acute limb ischemia emergency",
            "pulseless leg sudden onset",
            "pallor pain paralysis limb",
            "paresthesia poikilothermia",
            "acute arterial occlusion",
            "sudden onset limb ischemia",
            "acute leg ischemia treatment",
        ],
    },
    "antithrombotic_therapy": {
        "id": "b404c5e0585611f0b053823a24ef0d59",
        "name": "Antithrombotic Therapy",
        "utterances": [
            "DOACs direct oral anticoagulants",
            "warfarin management vascular",
            "triple therapy anticoagulation",
            "cancer-associated thrombosis treatment",
            "anticoagulation after vascular surgery",
            "aspirin therapy vascular",
            "clopidogrel dual antiplatelet",
            "antithrombotic selection",
            "bleeding risk anticoagulation",
        ],
    },
    "venous_thrombosis": {
        "id": "7104532adc4311f09021f2381272676b",
        "name": "Venous Thrombosis (DVT/PE)",
        "utterances": [
            "DVT deep vein thrombosis treatment",
            "PE pulmonary embolism management",
            "IVC filters indications",
            "post-thrombotic syndrome PTS",
            "catheter-directed thrombolysis DVT",
            "DVT anticoagulation duration",
            "iliofemoral DVT treatment",
            "proximal vs distal DVT",
            "venous thromboembolism prevention",
        ],
    },
    "chronic_venous_disease": {
        "id": "ecb621444d8f11f09f7a2e382eabde98",
        "name": "Chronic Venous Disease",
        "utterances": [
            "Varicose veins treatment",
            "CEAP classification venous",
            "endovenous ablation",
            "sclerotherapy for veins",
            "venous ulcer management",
            "venous reflux treatment",
            "great saphenous vein GSV ablation",
            "SSV small saphenous vein",
            "chronic venous insufficiency",
        ],
    },
    "vascular_trauma": {
        "id": "8f58aeadec9411f0a38066bc68590b9b",
        "name": "Vascular Trauma",
        "utterances": [
            "REBOA resuscitative balloon occlusion",
            "mangled extremity MESS score",
            "hard signs vascular injury",
            "soft signs vascular trauma",
            "penetrating vascular trauma",
            "blunt vascular injury",
            "hemorrhage control trauma",
            "traumatic arterial injury",
            "vascular injury management",
        ],
    },
    "vascular_graft_infections": {
        "id": "29981e72dc4311f09021f2381272676b",
        "name": "Vascular Graft Infections",
        "utterances": [
            "MAGIC criteria graft infection",
            "graft excision infected",
            "antibiotic protocols graft",
            "prosthetic graft infection",
            "aortic graft infection treatment",
            "infected vascular graft",
            "graft preservation vs removal",
            "biofilm graft infection",
            "in-situ replacement infected graft",
        ],
    },
    "vascular_access": {
        "id": "bbe0b3a0f39611f08b265ef3771a102d",
        "name": "Vascular Access",
        "utterances": [
            "AV fistula creation",
            "AVF arteriovenous fistula",
            "dialysis access planning",
            "hemodialysis vascular access",
            "AV graft for dialysis",
            "steal syndrome dialysis",
            "access thrombosis treatment",
            "fistula maturation",
            "central venous catheter dialysis",
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

    def route_multi(self, query: str, max_routes: int = 3, min_confidence: float = 0.35, relative_margin: float = 0.02) -> list[dict]:
        """
        Route a query to multiple guidelines using similarity scoring with smart selection.
        
        Selection logic:
        1. Always include the top matching guideline
        2. Only include additional guidelines if their score is within relative_margin (%) of the top score
        3. Uses relative threshold (e.g., 2% of top score) instead of absolute to handle varied score distributions
        4. This prevents "AI soup" from over-selection while supporting true multi-topic queries
        
        Args:
            query: The clinical question
            max_routes: Maximum number of guidelines to return (default 3)
            min_confidence: Minimum absolute similarity threshold (default 0.35)
            relative_margin: Percentage margin relative to top score (default 0.02 = 2%)
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
                
                top_score = sorted_routes[0][1]
                score_threshold = top_score * (1 - relative_margin)
                
                results = []
                for route_name, score in sorted_routes[:max_routes]:
                    if score >= min_confidence and score >= score_threshold and route_name in GUIDELINES_CONFIG:
                        config = GUIDELINES_CONFIG[route_name]
                        results.append({
                            "guideline_key": route_name,
                            "guideline_id": config["id"],
                            "guideline_name": config["name"],
                            "confidence": round(score, 4),
                        })
                
                if results:
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
