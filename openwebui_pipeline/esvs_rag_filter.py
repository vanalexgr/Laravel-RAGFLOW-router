"""
title: ESVS Vascular Guidelines RAG Filter
author: Medical AI Team
date: 2026-01-11
version: 2.1
license: MIT
description: Filter pipeline that retrieves ESVS vascular surgery guidelines from Laravel API with dual-source retrieval (narrative + citations) and injects as context before LLM synthesis. Includes retry logic, warm-up, and failure visibility.
requirements: httpx
"""

from typing import Optional, Tuple
import asyncio
import random
import uuid
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
        MAX_RETRIES: int = Field(
            default=2, description="Maximum number of retry attempts for failed requests"
        )
        RETRY_BASE_DELAY: float = Field(
            default=1.0, description="Base delay in seconds for exponential backoff"
        )
        WARMUP_ON_STARTUP: bool = Field(
            default=True, description="Ping API on startup to warm up services"
        )
        SHOW_FAILURE_WARNING: bool = Field(
            default=True, description="Show warning to user when retrieval fails"
        )

    def __init__(self):
        self.valves = self.Valves()
        self.name = "ESVS Vascular Guidelines RAG"
        self._client = None
        self._warmup_complete = False

    async def on_startup(self):
        print(f"[{self.name}] Pipeline initialized (v2.1 - resilient retrieval)")
        print(f"[{self.name}] API URL: {self.valves.RETRIEVE_API_URL}")
        self._client = httpx.AsyncClient(
            timeout=httpx.Timeout(self.valves.TIMEOUT_SECONDS)
        )
        
        if self.valves.WARMUP_ON_STARTUP:
            asyncio.create_task(self._warmup_services())

    async def _warmup_services(self):
        """Ping the API on startup to warm up Laravel and RAGFlow services."""
        try:
            print(f"[{self.name}] Warming up services...")
            health_url = self.valves.RETRIEVE_API_URL.replace("/retrieve", "/health/retrieval")
            response = await self._client.get(
                health_url,
                headers={"Authorization": f"Bearer {self.valves.API_KEY}"},
            )
            if response.status_code == 200:
                data = response.json()
                print(f"[{self.name}] Warmup complete: {data.get('status', 'ok')}")
                self._warmup_complete = True
            else:
                print(f"[{self.name}] Warmup health check returned {response.status_code}, trying retrieve...")
                await self._client.post(
                    self.valves.RETRIEVE_API_URL,
                    json={"question": "warmup", "top_k": 1},
                    headers={
                        "Authorization": f"Bearer {self.valves.API_KEY}",
                        "Content-Type": "application/json",
                    },
                )
                print(f"[{self.name}] Warmup fallback complete")
                self._warmup_complete = True
        except Exception as e:
            print(f"[{self.name}] Warmup failed (non-critical): {e}")

    async def on_shutdown(self):
        print(f"[{self.name}] Pipeline shutting down")
        if self._client:
            await self._client.aclose()

    async def _retrieve_with_retry(self, question: str, correlation_id: str) -> Tuple[Optional[dict], str]:
        """
        Attempt retrieval with exponential backoff retry.
        Returns (data, error_message) tuple.
        """
        last_error = ""
        
        for attempt in range(self.valves.MAX_RETRIES):
            try:
                if not self._client:
                    self._client = httpx.AsyncClient(
                        timeout=httpx.Timeout(self.valves.TIMEOUT_SECONDS)
                    )

                response = await self._client.post(
                    self.valves.RETRIEVE_API_URL,
                    json={"question": question, "top_k": self.valves.TOP_K},
                    headers={
                        "Authorization": f"Bearer {self.valves.API_KEY}",
                        "Content-Type": "application/json",
                        "Accept": "application/json",
                        "X-Correlation-ID": correlation_id,
                    },
                )

                if response.status_code == 200:
                    data = response.json()
                    if data.get("success"):
                        return (data, "")
                    else:
                        last_error = f"API error: {data.get('error', 'unknown')}"
                else:
                    last_error = f"HTTP {response.status_code}"
                    
            except httpx.TimeoutException:
                last_error = f"Timeout after {self.valves.TIMEOUT_SECONDS}s"
            except httpx.ConnectError as e:
                last_error = f"Connection error: {e}"
            except Exception as e:
                last_error = f"Unexpected error: {e}"
            
            if attempt < self.valves.MAX_RETRIES - 1:
                delay = self.valves.RETRY_BASE_DELAY * (2 ** attempt) + random.uniform(0, 0.5)
                print(f"[{self.name}] Attempt {attempt + 1} failed ({last_error}), retrying in {delay:.1f}s...")
                await asyncio.sleep(delay)
        
        return (None, last_error)

    def _inject_failure_warning(self, body: dict, user_message: str, error: str, correlation_id: str) -> dict:
        """Inject a warning message when retrieval fails so user knows context is missing."""
        if not self.valves.SHOW_FAILURE_WARNING:
            return body
            
        warning = f"""⚠️ **Guideline Retrieval Failed**

The medical guidelines database could not be reached. This response is generated WITHOUT evidence from ESVS vascular surgery guidelines.

**Error:** {error}
**Correlation ID:** {correlation_id}

Please retry your question, or verify the RAG service is available.

---

**Original Question:** {user_message}"""

        for i, msg in enumerate(body["messages"]):
            if msg.get("role") == "user" and msg.get("content") == user_message:
                body["messages"][i]["content"] = warning
                break
        
        return body

    async def inlet(self, body: dict, user: Optional[dict] = None) -> dict:
        """
        Called before request goes to LLM.
        Retrieves guideline chunks (narrative + citations) and injects them as context.
        Includes retry logic and failure visibility.
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

        correlation_id = str(uuid.uuid4())[:8]
        print(f"[{self.name}] [{correlation_id}] Retrieving guidelines for: {user_message[:100]}...")

        data, error = await self._retrieve_with_retry(user_message, correlation_id)
        
        if data is None:
            print(f"[{self.name}] [{correlation_id}] FAILED after {self.valves.MAX_RETRIES} attempts: {error}")
            return self._inject_failure_warning(body, user_message, error, correlation_id)

        narrative_chunks = data.get("narrative_chunks", [])
        citation_chunks = data.get("citation_chunks", [])
        selected_guidelines = data.get("selected_guidelines", {})
        duration_ms = data.get("duration_ms", 0)
        system_prompt = data.get("system_prompt", "")

        print(
            f"[{self.name}] [{correlation_id}] Retrieved {len(narrative_chunks)} narrative + {len(citation_chunks)} citation chunks in {duration_ms}ms"
        )
        print(f"[{self.name}] [{correlation_id}] Guidelines: {list(selected_guidelines.keys())}")

        if not narrative_chunks and not citation_chunks:
            print(f"[{self.name}] [{correlation_id}] WARNING: No chunks retrieved - empty context")
            return self._inject_failure_warning(body, user_message, "No relevant guidelines found", correlation_id)

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
