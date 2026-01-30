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
            default="***REMOVED_KEY***",
            description="API Key for authentication",
        )

    def consult_vascular_guidelines(
        self, 
        question: str,
        guidelines: list[str] = None,
        __user__: dict = {}, 
        __messages__: list = []
    ) -> str:
        """
        Consult ESVS Vascular Guidelines.
        
        :param question: The clinical question to answer
        :param guidelines: List of 1-3 guideline keys. COPY EXACTLY from this list:
        
        AORTIC:     "aortic_arch", "descending_thoracic_aorta", "abdominal_aortic_aneurysm", "mesenteric_renal"
        LIMB:       "asymptomatic_pad", "clti", "acute_limb_ischaemia"  
        CEREBRAL:   "carotid_vertebral"
        VENOUS:     "venous_thrombosis", "chronic_venous_disease"
        MEDS:       "antithrombotic_therapy"
        SPECIALTY:  "vascular_trauma", "vascular_graft_infections", "vascular_access"
        
        EXAMPLES:
        - AAA question → ["abdominal_aortic_aneurysm"]
        - Claudication → ["asymptomatic_pad"]  
        - Graft infection → ["vascular_graft_infections"]
        - DVT → ["venous_thrombosis"]
        - Post-EVAR fever → ["vascular_graft_infections", "abdominal_aortic_aneurysm"]
        
        :return: Evidence-based recommendations
        """
        
        # Extract conversation history for context fusion
        history = []
        if __messages__:
            for msg in __messages__[-5:]:  # Last 5 messages for context
                role = msg.get("role", "")
                content = msg.get("content", "")
                if content and isinstance(content, str):
                    history.append(f"{role}: {content}")
        
        
        # Validate guidelines input
        if guidelines is None:
            guidelines = []  # Use auto-routing if not provided
        elif not isinstance(guidelines, list):
            guidelines = [guidelines]  # Convert single string to list
        
        # Limit to 3 guidelines
        guidelines = guidelines[:3] if guidelines else []
        
        url = f"{self.valves.VASCULAR_API_BASE_URL}/api/v1/vascular-consult"
        headers = {
            "Authorization": f"Bearer {self.valves.VASCULAR_API_KEY}",
            "Content-Type": "application/json",
        }
        payload = {
            "question": question,
            "history": history,  # Send conversation context
            "guidelines": guidelines  # LLM-driven guideline selection (1-3)
        }

        try:
            response = requests.post(url, json=payload, headers=headers, timeout=30)
            response.raise_for_status()
            
            data = response.json()
            return data.get("result", "No results returned")
            
        except requests.exceptions.RequestException as e:
            return f"Error calling Vascular Expert API: {str(e)}"
