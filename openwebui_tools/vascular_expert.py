"""
title: Vascular Expert Tools
author: open-webui
author_url: https://github.com/open-webui
funding_url: https://github.com/open-webui
version: 1.0.0
"""

import requests
from pydantic import BaseModel, Field


class Tools:
    def __init__(self):
        self.valves = self.Valves()

    class Valves(BaseModel):
        VASCULAR_API_BASE_URL: str = Field(
            default="https://lavarel.eastus2.cloudapp.azure.com",
            description="Base URL for Vascular Expert API",
        )
        VASCULAR_API_KEY: str = Field(
            default="gukUXd551qIobQVHVQLedUMmA4E8Cx4s",
            description="API Key for authentication",
        )

    def consult_vascular_guidelines(
        self, 
        question: str,
        guideline: str = "auto",
        __user__: dict = {}, 
        __messages__: list = []
    ) -> str:
        """
        Consult ESVS Vascular Guidelines. Use this for any clinical vascular surgery question.
        
        :param question: The clinical question to answer
        :param guideline: Which guideline to query. Options:
            - "auto" (default): Let the system automatically select the best guideline(s)
            - "aortic_arch": Aortic Arch (Zone 0-4, FET, arch dissection/aneurysm)
            - "descending_thoracic_aorta": Descending Thoracic Aorta (Type B Dissection, TEVAR, IMH)
            - "abdominal_aortic_aneurysm": Abdominal Aortic Aneurysm (AAA, EVAR, rupture)
            - "mesenteric_renal": Mesenteric & Renal (CMI, AMI, RAS, visceral aneurysms)
            - "carotid_vertebral": Carotid & Vertebral (Stroke, TIA, CEA, CAS)
            - "asymptomatic_pad": Asymptomatic PAD / Claudication (IC, SET, exercise therapy)
            - "clti": Chronic Limb-Threatening Ischemia (rest pain, tissue loss, gangrene)
            - "acute_limb_ischaemia": Acute Limb Ischaemia (ALI, 6 Ps, Rutherford)
            - "antithrombotic_therapy": Antithrombotic Therapy (DOACs, aspirin, clopidogrel)
            - "venous_thrombosis": Venous Thrombosis (DVT, PE, PTS)
            - "chronic_venous_disease": Chronic Venous Disease (varicose veins, CEAP, reflux)
            - "vascular_trauma": Vascular Trauma (REBOA, penetrating/blunt injury)
            - "vascular_graft_infections": Vascular Graft Infections (graft excision, MAGIC)
            - "vascular_access": Vascular Access (dialysis, AVF, steal syndrome)
        :return: Evidence-based recommendations and citations
        """
        
        # Extract conversation history for context fusion
        history = []
        if __messages__:
            for msg in __messages__[-5:]:  # Last 5 messages for context
                role = msg.get("role", "")
                content = msg.get("content", "")
                if content and isinstance(content, str):
                    history.append(f"{role}: {content}")
        
        url = f"{self.valves.VASCULAR_API_BASE_URL}/api/v1/vascular-consult"
        headers = {
            "Authorization": f"Bearer {self.valves.VASCULAR_API_KEY}",
            "Content-Type": "application/json",
        }
        payload = {
            "question": question,
            "history": history,  # Send conversation context
            "guideline": guideline  # LLM-driven guideline selection
        }

        try:
            response = requests.post(url, json=payload, headers=headers, timeout=30)
            response.raise_for_status()
            
            data = response.json()
            return data.get("result", "No results returned")
            
        except requests.exceptions.RequestException as e:
            return f"Error calling Vascular Expert API: {str(e)}"
