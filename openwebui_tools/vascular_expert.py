"""
title: Vascular Expert Tools
author: open-webui
author_url: https://github.com/open-webui
funding_url: https://github.com/open-webui
version: 2.1.0
"""

import httpx
import asyncio
from pydantic import BaseModel, Field
from typing import Literal, Optional, Callable, Awaitable


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

    async def _emit_status(self, emitter, description: str, done: bool = False):
        """Emit a status update to OpenWebUI UI (replaces pulsating dot)."""
        if emitter:
            try:
                await emitter(
                    {
                        "type": "status",
                        "data": {"description": description, "done": done},
                    }
                )
            except Exception as e:
                print(f"[VascularExpert] Status emit error: {e}")

    async def consult_vascular_guidelines(
        self, 
        question: str,
        guideline_1: GuidelineKey,
        guideline_2: Optional[GuidelineKey] = None,
        guideline_3: Optional[GuidelineKey] = None,
        __user__: dict = {}, 
        __messages__: list = [],
        __event_emitter__: Callable[[dict], Awaitable[None]] = None
    ) -> str:
        """
        Consult ESVS Vascular Guidelines. Select 1-3 guidelines based on the clinical question.
        
        **IMPORTANT**: Call this tool for EVERY vascular surgery question, including follow-up 
        questions in the same conversation. Each question requires fresh evidence retrieval.
        Do NOT rely on previous tool responses for new questions.
        
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
        emitter = __event_emitter__
        
        # Collect selected guidelines
        guidelines = [guideline_1]
        if guideline_2:
            guidelines.append(guideline_2)
        if guideline_3:
            guidelines.append(guideline_3)
        
        # Build human-readable guideline display
        guideline_display = ", ".join(GUIDELINE_NAMES.get(g, g) for g in guidelines)
        await self._emit_status(emitter, f"Selecting guidelines: {guideline_display}")
        
        # Extract conversation history for context fusion
        history = []
        if __messages__:
            for msg in __messages__[-5:]:  # Last 5 messages for context
                role = msg.get("role", "")
                content = msg.get("content", "")
                if content and isinstance(content, str):
                    history.append(f"{role}: {content}")
        
        await self._emit_status(emitter, f"Consulting {len(guidelines)} ESVS guideline(s)...")
        
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
            await self._emit_status(emitter, "Searching ESVS guidelines for evidence...")
            
            async with httpx.AsyncClient(timeout=30.0) as client:
                response = await client.post(url, json=payload, headers=headers)
                response.raise_for_status()
            
            data = response.json()
            print(f"[VascularExpert] Response keys: {data.keys()}")
            
            # Extract chunks from response
            narrative_chunks = data.get("narrative_chunks", [])
            citation_chunks = data.get("citation_chunks", [])
            print(f"[VascularExpert] Chunks: narrative={len(narrative_chunks)}, citation={len(citation_chunks)}")
            
            total_chunks = len(narrative_chunks) + len(citation_chunks)
            
            # EMIT INDIVIDUAL CITATIONS for each chunk
            # This enables per-chunk citation popups in OpenWebUI
            if emitter:
                chunk_number = 1
                
                # Emit citation chunks first (recommendations)
                for chunk in citation_chunks:
                    text = chunk.get("text", chunk.get("content", ""))
                    rec_id = chunk.get("recommendation_id", "")
                    cls = chunk.get("class", "")
                    level = chunk.get("level", "")
                    guideline = chunk.get("guideline", "ESVS")
                    
                    # Build citation title (User Request: "Recommendation 25 from xxx - Class IIA, Level C")
                    if rec_id:
                        # Clean rec_id if it has prefixes like "vascular_trauma_R002" -> "R002"? 
                        # User example "25" suggests short ID. But dataset has long IDs. 
                        # Let's keep the ID as is for identifying, or maybe just distinct part?
                        # For now, use the full rec_id but formatted nicely.
                        title = f"Recommendation {rec_id} from {guideline} - Class {cls}, Level {level}"
                    else:
                        title = f"Recommendation from {guideline}"
                    
                    try:
                        await emitter({
                            "type": "citation",
                            "data": {
                                "document": [text],
                                "metadata": [{"source": title}],
                                "source": {"id": f"cite_{chunk_number}", "name": title},
                            }
                        })
                    except Exception as e:
                        print(f"Error emitting citation: {e}")
                    
                    chunk_number += 1
                
                # Emit narrative chunks (context)
                for chunk in narrative_chunks[:15]:
                    content = chunk.get("content", "")
                    doc_name = chunk.get("doc_name", "Context")
                    source_guideline = chunk.get("source_guideline", "ESVS Guidelines")
                    
                    title = f"Narrative: {doc_name} ({source_guideline})"
                    
                    try:
                        await emitter({
                            "type": "citation",
                            "data": {
                                "document": [content],
                                "metadata": [{"source": title}],
                                "source": {"id": f"cite_{chunk_number}", "name": title},
                            }
                        })
                    except Exception as e:
                        print(f"Error emitting citation: {e}")
                        
                    chunk_number += 1
            
            if total_chunks > 0:
                status_msg = f"Retrieved {total_chunks} evidence chunks from {guideline_display}"
                await self._emit_status(emitter, status_msg, done=True)
                
                # Build formatted text for the LLM
                # We format strict headers to match the System Prompt requirements
                llm_output = f"Consultation successful. Retrieved {total_chunks} evidence sources:\n\n"
                
                chunk_num = 1
                
                # SECTION 1: RECOMMENDATIONS (Must match System Prompt format)
                if citation_chunks:
                    llm_output += "=== RECOMMENDATIONS ===\n"
                    for chunk in citation_chunks:
                        text = chunk.get("text", chunk.get("content", ""))
                        rec_id = chunk.get("recommendation_id", "Rec")
                        cls = chunk.get("class", "N/A")
                        lvl = chunk.get("level", "N/A")
                        guideline = chunk.get("guideline", "ESVS")
                        
                        # INSTRUCTION TO LLM: Include [n] in the header so it is clickable in the final answer
                        header = f"[{chunk_num}] Rec {rec_id} (Class {cls}, Level {lvl}) — {guideline}"
                        llm_output += f"{header}\n> {text}\n\n"
                        chunk_num += 1

                # SECTION 2: NARRATIVE (Context)
                if narrative_chunks:
                    llm_output += "=== NARRATIVE CONTEXT ===\n"
                    for chunk in narrative_chunks[:15]: 
                        content = chunk.get("content", "")
                        source = chunk.get("source_guideline", "ESVS")
                        
                        header = f"[{chunk_num}] Narrative Evidence — {source}"
                        llm_output += f"{header}\n{content}\n\n"
                        chunk_num += 1
                
                llm_output += "=== INSTRUCTIONS (STRICT) ===\n"
                llm_output += "1. USE 'Clinical Synthesis' bullets first, then 'Recommendations used'.\n"
                llm_output += "2. EVERY factual statement MUST have an inline citation `[n]` at the end of the sentence.\n"
                llm_output += "3. Narrative Evidence (Context) is valid and CRITICAL. If you use it, CITE IT as `[n]`.\n"
                llm_output += "4. In 'Recommendations used' list, copy the header exactly (e.g. '[1] Rec 25...').\n"
                llm_output += "5. DO NOT hallucinate Recs not shown above."
                
                return llm_output
            else:
                await self._emit_status(
                    emitter, 
                    f"Consultation complete ({guideline_display})",
                    done=True
                )
                return "No specific guidelines found. Please answer based on general medical knowledge."
            
        except httpx.TimeoutException:
            await self._emit_status(emitter, "Request timed out", done=True)
            return "Error: Request timed out after 30 seconds"
        except httpx.HTTPStatusError as e:
            await self._emit_status(emitter, f"API error: {e.response.status_code}", done=True)
            return f"Error calling Vascular Expert API: HTTP {e.response.status_code}"
        except Exception as e:
            await self._emit_status(emitter, f"Error: {str(e)[:50]}", done=True)
            return f"Error calling Vascular Expert API: {str(e)}"
