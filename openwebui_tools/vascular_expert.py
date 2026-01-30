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
        guidelines: list[str] = None,
        __user__: dict = {}, 
        __messages__: list = []
    ) -> str:
        """
        Consult ESVS Vascular Guidelines. **STRONGLY RECOMMENDED: Select 1-3 specific guidelines for best results.**
        
        :param question: The clinical question to answer
        :param guidelines: **REQUIRED**. List of 1-3 guideline(s) to query. Select based on:
        
        **GUIDELINE SELECTION RULES:**
        1. **Anatomy First**: Match guidelines to the anatomical territory (aorta, limb, cerebral, venous)
        2. **Acuity Second**: Distinguish acute vs chronic presentations
        3. **Pathology Third**: Consider the specific condition (aneurysm, occlusion, dissection, etc.)
        4. **Multi-select**: If uncertain or question spans domains, select 2-3 relevant guidelines
        5. **Reconsider Every Time**: Even for follow-ups, re-evaluate which guideline(s) fit best
        
        **Available Guidelines:**
        
        🫀 **Aortic & Central**
        - "aortic_arch" → Arch aneurysm/dissection, Zone 0-2, FET, hybrid arch repair
        - "descending_thoracic_aorta" → Type B dissection, TEVAR, thoracic aneurysm, spinal cord ischemia
        - "abdominal_aortic_aneurysm" → AAA, EVAR, rupture, endoleaks, iliac aneurysms
        - "mesenteric_renal" → Mesenteric ischemia (CMI/AMI), renal artery stenosis, visceral aneurysms
        
        🦵 **Limb (Arterial)**
        - "asymptomatic_pad" → Claudication, asymptomatic PAD, exercise therapy, screening
        - "clti" → Rest pain, tissue loss, gangrene, limb salvage, amputation
        - "acute_limb_ischaemia" → ALI, sudden limb pain, embolism, acute thrombosis
        
        🧠 **Cerebrovascular**
        - "carotid_vertrebral" → Stroke, TIA, carotid stenosis, CEA, CAS
        
        🔵 **Venous**
        - "venous_thrombosis" → DVT, PE, VTE, anticoagulation duration
        - "chronic_venous_disease" → Varicose veins, venous ulcers, CEAP, ablation
        
        💊 **Medications**
        - "antithrombotic_therapy" → Aspirin, DOACs, DAPT, bleeding risk, perioperative management
        
        🚨 **Specialty**
        - "vascular_trauma" → Penetrating/blunt injury, REBOA, damage control, hard/soft signs
        - "vascular_graft_infections" → Graft/endograft infection, fever post-procedure, fistulas
        - "vascular_access" → Dialysis access, AVF, steal syndrome, thrombosis
        
        **EXAMPLES:**
        - Q: "What are AAA guidelines?" → `["abdominal_aortic_aneurysm"]`
        - Q: "Post-EVAR fever and elevated CRP?" → `["vascular_graft_infections", "abdominal_aortic_aneurysm"]`
        - Q: "Claudication treatment?" → `["asymptomatic_pad"]`
        - Q: "When to revascularize?" (follow-up to claudication) → `["asymptomatic_pad"]` or `["asymptomatic_pad", "clti"]` if progression suspected
        - Q: "Sudden cold pulseless leg?" → `["acute_limb_ischaemia"]`
        - Q: "DVT anticoagulation duration?" → `["venous_thrombosis", "antithrombotic_therapy"]`
        
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
