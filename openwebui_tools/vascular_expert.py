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
        Consult ESVS Vascular Guidelines. **STRONGLY RECOMMENDED: Select 1-3 specific guidelines for best results.**
        
        :param question: The clinical question to answer
        :param guidelines: **OPTIONAL**. List of 1-3 guideline KEY(s) to query. 
        
        ⚠️ **CRITICAL: Use EXACT key strings below. Do NOT invent names or use descriptions!**
        
        **SELECTION STRATEGY:**
        1. Match anatomical territory (aorta, limb, cerebral, venous)
        2. Consider acuity (acute vs chronic)
        3. Identify pathology (aneurysm, occlusion, dissection)
        4. Select 2-3 if uncertain or multi-domain question
        5. Reconsider for every new question (including follow-ups)
        
        **VALID GUIDELINE KEYS (copy exactly):**
        
        🫀 Aortic & Central:
        • "aortic_arch" - Arch aneurysm/dissection, Zone 0-2, FET, hybrid arch
        • "descending_thoracic_aorta" - Type B dissection, TEVAR, thoracic aneurysm
        • "abdominal_aortic_aneurysm" - AAA, EVAR, rupture, endoleaks
        • "mesenteric_renal" - Mesenteric ischemia, renal artery stenosis, visceral aneurysms
        
        🦵 Limb (Arterial):
        • "asymptomatic_pad" - Claudication, PAD screening, exercise therapy, lifestyle
        • "clti" - Rest pain, tissue loss, gangrene, limb salvage
        • "acute_limb_ischaemia" - ALI, sudden limb pain, embolism, acute thrombosis
        
        🧠 Cerebrovascular:
        • "carotid_vertebral" - Stroke, TIA, carotid stenosis, CEA, CAS
        
        🔵 Venous:
        • "venous_thrombosis" - DVT, PE, VTE, anticoagulation duration
        • "chronic_venous_disease" - Varicose veins, venous ulcers, CEAP, ablation
        
        💊 Medications:
        • "antithrombotic_therapy" - Aspirin, DOACs, DAPT, bleeding risk
        
        🚨 Specialty:
        • "vascular_trauma" - Penetrating/blunt injury, REBOA, damage control
        • "vascular_graft_infections" - Graft infection, fever post-EVAR/TEVAR
        • "vascular_access" - Dialysis access, AVF, steal syndrome
        
        **CORRECT USAGE EXAMPLES:**
        ✅ Q: "What are AAA guidelines?" 
           → guidelines=["abdominal_aortic_aneurysm"]
        
        ✅ Q: "Can lifestyle changes help manage PAD?"
           → guidelines=["asymptomatic_pad"]
        
        ✅ Q: "Post-EVAR fever and elevated CRP?"
           → guidelines=["vascular_graft_infections", "abdominal_aortic_aneurysm"]
        
        ✅ Q: "Claudication treatment options?"
           → guidelines=["asymptomatic_pad"]
        
        ✅ Q: "When to revascularize?" (follow-up to claudication)
           → guidelines=["asymptomatic_pad"]  OR  ["asymptomatic_pad", "clti"] if progression suspected
        
        ✅ Q: "Sudden cold pulseless leg?"
           → guidelines=["acute_limb_ischaemia"]
        
        ✅ Q: "DVT anticoagulation duration?"
           → guidelines=["venous_thrombosis", "antithrombotic_therapy"]
        
        ❌ WRONG: guidelines=["Chronic Limb-Threatening Ischemia (CLTI)"]
        ❌ WRONG: guidelines=["Management of PAD"]
        ❌ WRONG: guidelines=["lifestyle"]
        
        **If unsure, omit `guidelines` parameter to use auto-routing.**
        
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
