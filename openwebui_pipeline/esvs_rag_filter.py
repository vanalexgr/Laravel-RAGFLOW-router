"""
title: ESVS Vascular Guidelines RAG Filter
author: Medical AI Team
date: 2026-01-11
version: 1.1
license: MIT
description: Filter pipeline that retrieves ESVS vascular surgery guidelines from Laravel API and injects as context before LLM synthesis. Enables fast (<5s) retrieval with native OpenWebUI streaming.
requirements: httpx
"""

from typing import Optional
import httpx
from pydantic import BaseModel, Field


# RENAMED FROM Pipeline TO Filter TO FIX "No Function class" ERROR
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
            default=12, description="Number of chunks to retrieve (1-50)"
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
        # Note: on_startup may not trigger in all Function contexts,
        # so we also check for client existence in inlet
        print(f"[{self.name}] Pipeline initialized")
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
        Retrieves guideline chunks and injects them as context.
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
            # Ensure client exists (in case on_startup didn't fire)
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

                chunks = data.get("chunks", [])
                selected_guidelines = data.get("selected_guidelines", {})
                duration_ms = data.get("duration_ms", 0)
                system_prompt = data.get("system_prompt", "")

                print(
                    f"[{self.name}] Retrieved {len(chunks)} chunks in {duration_ms}ms"
                )
                print(f"[{self.name}] Guidelines: {list(selected_guidelines.keys())}")

                if not chunks:
                    return body

                context = self._format_context(chunks, selected_guidelines)

                if self.valves.INJECT_SYSTEM_PROMPT and system_prompt:
                    has_system = any(m.get("role") == "system" for m in messages)
                    if not has_system:
                        # Insert new system prompt at the start
                        messages.insert(0, {"role": "system", "content": system_prompt})
                    else:
                        # Optional: Append to existing system prompt instead?
                        # For now, we leave existing system prompts alone if present
                        pass

                    body["messages"] = messages

                rag_prompt = f"""## ESVS Vascular Surgery Guidelines Evidence

{context}

---

## User Question
{user_message}

---

**Instructions**: Answer using ONLY the guideline evidence above. Cite specific recommendations (e.g., "Rec 12, Class I, Level A"). If the evidence doesn't cover the question, state this clearly."""

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

    def _format_context(self, chunks: list, guidelines: dict) -> str:
        """Format retrieved chunks into readable context."""
        guideline_names = [g.get("name", k) for k, g in guidelines.items()]
        header = f"**Sources**: {', '.join(guideline_names)}\n\n"

        formatted_chunks = []
        for i, chunk in enumerate(chunks, 1):
            rec_id = chunk.get("recommendation_id", "Unknown")
            cls = chunk.get("class", "")
            level = chunk.get("level", "")
            content = chunk.get("content", "")
            source = chunk.get("source_guideline", "")

            meta = f"**{rec_id}**"
            if cls or level:
                meta += f" ({cls}, {level})"
            if source:
                meta += f" - {source}"

            formatted_chunks.append(f"{i}. {meta}\n   {content}\n")

        return header + "\n".join(formatted_chunks)

    async def outlet(self, body: dict, user: Optional[dict] = None) -> dict:
        """Called after LLM response. Pass through without modification."""
        return body
