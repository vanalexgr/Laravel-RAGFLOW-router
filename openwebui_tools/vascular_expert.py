"""
title: Vascular Expert Tools
author: open-webui
author_url: https://github.com/open-webui
funding_url: https://github.com/open-webui
version: 2.0.0
"""

import requests
from pydantic import BaseModel, Field
from typing import Literal, Optional


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


class Tools:
    def __init__(self):
        self.valves = self.Valves()

    class Valves(BaseModel):
        VASCULAR_API_BASE_URL: str = Field(
            default="https://lavarel.eastus2.cloudapp.azure.com",
            description="Base URL for Vascular Expert API",
        )
        VASCULAR_API_KEY: str = Field(
            default="***REMOVED_KEY***",
            description="API Key for authentication",
        )

    def consult_vascular_guidelines(
        self, 
        question: str,
        guideline_1: GuidelineKey,
        guideline_2: Optional[GuidelineKey] = None,
        guideline_3: Optional[GuidelineKey] = None,
        __user__: dict = {}, 
        __messages__: list = []
    ) -> str:
        """
        Consult ESVS Vascular Guidelines. Select 1-3 guidelines based on the clinical question.
        
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
        
        :param question: The clinical question to answer
        :param guideline_1: Primary guideline (required)
        :param guideline_2: Secondary guideline (optional)
        :param guideline_3: Tertiary guideline (optional)
        :return: Evidence-based recommendations and citations
        """
        
        # Collect selected guidelines
        guidelines = [guideline_1]
        if guideline_2:
            guidelines.append(guideline_2)
        if guideline_3:
            guidelines.append(guideline_3)
        
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
            "history": history,
            "guidelines": guidelines  # LLM-selected guidelines (enum-constrained)
        }

        try:
            response = requests.post(url, json=payload, headers=headers, timeout=30)
            response.raise_for_status()
            
            data = response.json()
            return data.get("result", "No results returned")
            
        except requests.exceptions.RequestException as e:
            return f"Error calling Vascular Expert API: {str(e)}"
