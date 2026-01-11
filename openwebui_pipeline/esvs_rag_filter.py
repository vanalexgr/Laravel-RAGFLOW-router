"""
title: ESVS Vascular Guidelines RAG Filter
author: Medical AI Team
date: 2026-01-11
version: 2.0
license: MIT
description: Filter pipeline that retrieves ESVS vascular surgery guidelines from Laravel API with dual-source retrieval (narrative + citations) and injects as context before LLM synthesis.
requirements: httpx
"""

from typing import Optional
import httpx
from pydantic import BaseModel, Field


class Filter:
    class Valves(BaseModel):
        RETRIEVE_API_URL: str = Field(
            default="https://your-replit-app.replit.app/api/v1/retrieve",
            description="Laravel retrieval API endpoint URL",
        )
        API_KEY: str = Field(
            default="", description="API secret key for authentication (API_SECRET_KEY)"
        )
        TOP_K: int = Field(
            default=12, description="Total chunks to retrieve (narrative + citations)"
        )
        ENABLE_RAG: bool = Field(
            default=True, description="Enable/disable guideline retrieval"
        )
        TIMEOUT_SECONDS: int = Field(
            default=30, description="Request timeout in seconds"
        )
        INJECT_SYSTEM_PROMPT: bool = Field(
            default=True,
            description="Inject recommended system prompt from API response",
        )

    def __init__(self):
        self.valves = self.Valves()
        self.name = "ESVS Vascular Guidelines RAG"
        self._client = None

    async def on_startup(self):
        print(f"[{self.name}] Pipeline initialized (v2.0 - dual retrieval)")
        print(f"[{self.name}] API URL: {self.valves.RETRIEVE_API_URL}")
        self._client = httpx.AsyncClient(
            timeout=httpx.Timeout(self.valves.TIMEOUT_SECONDS)
        )

    async def on_shutdown(self):
        print(f"[{self.name}] Pipeline shutting down")
        if self._client:
            await self._client.aclose()

    async def inlet(self, body: dict, user: Optional[dict] = None) -> dict:
        """
        Called before request goes to LLM.
        Retrieves guideline chunks (narrative + citations) and injects them as context.
        """
        if not self.valves.ENABLE_RAG:
            return body

        messages = body.get("messages", [])
        if not messages:
            return body

        user_message = ""
        for msg in reversed(messages):
            if msg.get("role") == "user":
                user_message = msg.get("content", "")
                break

        if not user_message:
            return body

        print(f"[{self.name}] Retrieving guidelines for: {user_message[:100]}...")

        try:
            if not self._client:
                self._client = httpx.AsyncClient(
                    timeout=httpx.Timeout(self.valves.TIMEOUT_SECONDS)
                )

            response = await self._client.post(
                self.valves.RETRIEVE_API_URL,
                json={"question": user_message, "top_k": self.valves.TOP_K},
                headers={
                    "Authorization": f"Bearer {self.valves.API_KEY}",
                    "Content-Type": "application/json",
                    "Accept": "application/json",
                },
            )

            if response.status_code == 200:
                data = response.json()

                if not data.get("success"):
                    print(f"[{self.name}] API returned error: {data.get('error')}")
                    return body

                narrative_chunks = data.get("narrative_chunks", [])
                citation_chunks = data.get("citation_chunks", [])
                selected_guidelines = data.get("selected_guidelines", {})
                duration_ms = data.get("duration_ms", 0)
                system_prompt = data.get("system_prompt", "")

                print(
                    f"[{self.name}] Retrieved {len(narrative_chunks)} narrative + {len(citation_chunks)} citation chunks in {duration_ms}ms"
                )
                print(f"[{self.name}] Guidelines: {list(selected_guidelines.keys())}")

                if not narrative_chunks and not citation_chunks:
                    return body

                context = self._format_dual_context(
                    narrative_chunks, citation_chunks, selected_guidelines
                )

                if self.valves.INJECT_SYSTEM_PROMPT and system_prompt:
                    has_system = any(m.get("role") == "system" for m in messages)
                    if not has_system:
                        messages.insert(0, {"role": "system", "content": system_prompt})
                    body["messages"] = messages

                rag_prompt = f"""## ESVS Vascular Surgery Guidelines Evidence

{context}

---

## User Question
{user_message}

---

**Instructions**: 
1. Synthesize your clinical answer using the NARRATIVE CONTEXT above
2. Cite ONLY from the CITATION EVIDENCE using exact recommendation text
3. Format: Rec [ID] (Class [X], Level [Y]) with verbatim quote
4. If evidence doesn't cover the question, state this clearly"""

                for i, msg in enumerate(body["messages"]):
                    if msg.get("role") == "user" and msg.get("content") == user_message:
                        body["messages"][i]["content"] = rag_prompt
                        break

            else:
                print(
                    f"[{self.name}] API error: {response.status_code} - {response.text[:200]}"
                )

        except httpx.TimeoutException:
            print(f"[{self.name}] Request timeout after {self.valves.TIMEOUT_SECONDS}s")
        except httpx.ConnectError as e:
            print(f"[{self.name}] Connection error: {e}")
        except Exception as e:
            print(f"[{self.name}] Unexpected error: {e}")

        return body

    def _format_dual_context(
        self, narrative_chunks: list, citation_chunks: list, guidelines: dict
    ) -> str:
        """Format dual-source chunks into structured context."""
        guideline_names = [g.get("name", k) for k, g in guidelines.items()]
        
        sections = []
        
        # Narrative section (for synthesis)
        if narrative_chunks:
            sections.append("### NARRATIVE CONTEXT (for clinical synthesis)")
            sections.append(f"**Sources**: {', '.join(guideline_names)}\n")
            
            for i, chunk in enumerate(narrative_chunks, 1):
                content = chunk.get("content", "")
                source = chunk.get("source_guideline", "")
                similarity = chunk.get("similarity", 0)
                
                sections.append(f"**[{i}] {source}** (relevance: {similarity}%)")
                sections.append(f"{content}\n")
        
        # Citation section (for verbatim quotes)
        if citation_chunks:
            sections.append("\n### CITATION EVIDENCE (for verbatim recommendations)")
            sections.append("Use these EXACT texts when citing recommendations:\n")
            
            for i, chunk in enumerate(citation_chunks, 1):
                rec_id = chunk.get("recommendation_id", "")
                cls = chunk.get("class", "")
                level = chunk.get("level", "")
                guideline = chunk.get("guideline", "")
                text = chunk.get("text", "")
                
                meta_parts = []
                if rec_id:
                    meta_parts.append(f"**{rec_id}**")
                if cls and level:
                    meta_parts.append(f"({cls}, {level})")
                if guideline:
                    meta_parts.append(f"— {guideline}")
                
                meta = " ".join(meta_parts) if meta_parts else f"Citation {i}"
                sections.append(f"{meta}")
                sections.append(f"> \"{text}\"\n")
        
        return "\n".join(sections)

    async def outlet(self, body: dict, user: Optional[dict] = None) -> dict:
        """Called after LLM response. Pass through without modification."""
        return body
