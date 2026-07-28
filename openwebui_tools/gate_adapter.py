"""
title: Clinical Gate Adapter
author: open-webui
version: 0.1.0
"""

import asyncio
import uuid
from typing import Awaitable, Callable, Optional

import httpx
from pydantic import BaseModel, Field


EventEmitter = Callable[[dict], Awaitable[None]]


class Tools:
    """Thin OpenWebUI transport for the Laravel clinical gate."""

    class Valves(BaseModel):
        CLINICAL_GATE_BASE_URL: str = Field(
            default="https://your-domain.com",
            description="Base URL for the Laravel clinical gate API.",
        )
        CLINICAL_GATE_API_KEY: str = Field(
            default="your-api-key",
            description="API key sent as a Bearer token to the clinical gate.",
        )
        REQUEST_TIMEOUT_SECONDS: float = Field(
            default=120.0,
            description="Timeout in seconds for the clinical-gate request.",
        )
        POLL_INTERVAL_SECONDS: float = Field(
            default=2.0,
            description="Seconds between gate-progress polls.",
        )
        EMIT_STATUS: bool = Field(
            default=True,
            description="Emit backend progress as native OpenWebUI status events.",
        )

    def __init__(self):
        self.valves = self.Valves()

    def _headers(self) -> dict:
        return {
            "Authorization": f"Bearer {self.valves.CLINICAL_GATE_API_KEY}",
            "Content-Type": "application/json",
            "Accept": "application/json",
        }

    def _url(self, path: str) -> str:
        return f"{str(self.valves.CLINICAL_GATE_BASE_URL).rstrip('/')}{path}"

    async def _safe_emit(
        self,
        emitter: Optional[EventEmitter],
        event: dict,
    ) -> None:
        if emitter is None:
            return
        try:
            await emitter(event)
        except Exception:
            # UI event delivery must never prevent the gate answer from returning.
            return

    async def _emit_progress(
        self,
        emitter: Optional[EventEmitter],
        message: str,
        done: bool = False,
    ) -> None:
        if not self.valves.EMIT_STATUS or not message:
            return
        await self._safe_emit(
            emitter,
            {
                "type": "status",
                "data": {
                    "description": message,
                    "done": done,
                    "hidden": False,
                },
            },
        )

    async def _post_consult(
        self,
        client: httpx.AsyncClient,
        message: str,
        state: dict,
        request_id: str,
    ) -> dict:
        response = await client.post(
            self._url("/api/v1/clinical-gate"),
            json={
                # The endpoint validates `question`, not `message` — sending the
                # wrong name yields a 422 with no answer. Confirmed against
                # ToolController::clinicalGate's validation rules.
                "question": message,
                "state": state,
                "request_id": request_id,
            },
            headers=self._headers(),
        )
        response.raise_for_status()
        data = response.json()
        if not isinstance(data, dict):
            raise ValueError("The clinical gate returned an invalid response.")
        return data

    async def _poll_while_running(
        self,
        client: httpx.AsyncClient,
        request_id: str,
        consult_task: asyncio.Task,
        emitter: Optional[EventEmitter],
    ) -> None:
        seen_emissions = set()
        progress_failed = False
        poll_interval = max(0.25, float(self.valves.POLL_INTERVAL_SECONDS))

        while not consult_task.done():
            try:
                response = await client.get(
                    self._url(f"/api/v1/gate-progress/{request_id}"),
                    headers=self._headers(),
                    timeout=min(10.0, max(1.0, poll_interval)),
                )
                response.raise_for_status()
                progress = response.json()
                if not isinstance(progress, dict):
                    raise ValueError("Invalid progress response.")

                # The backend returns these under "progress" (matching the R7.9
                # contract). "emissions" is accepted as a fallback: the two halves
                # were built in parallel and disagreed on this key, which showed up
                # as a silently empty progress feed rather than an error — the demo
                # would simply have looked frozen.
                emissions = progress.get("progress") or progress.get("emissions") or []
                if not isinstance(emissions, list):
                    emissions = []
                progress_done = bool(progress.get("done", False))

                occurrences = {}
                for position, emission in enumerate(emissions):
                    if not isinstance(emission, dict):
                        continue
                    stage = str(emission.get("stage") or "")
                    message = str(emission.get("message") or "").strip()
                    occurrence_key = (stage, message)
                    occurrence = occurrences.get(occurrence_key, 0) + 1
                    occurrences[occurrence_key] = occurrence
                    fingerprint = (stage, message, occurrence)
                    if not message or fingerprint in seen_emissions:
                        continue
                    seen_emissions.add(fingerprint)
                    is_last = position == len(emissions) - 1
                    await self._emit_progress(
                        emitter,
                        message,
                        done=progress_done and is_last,
                    )

                if progress_done:
                    break
            except (httpx.HTTPError, ValueError, TypeError):
                progress_failed = True

            if progress_failed:
                await self._emit_progress(emitter, "Working…")
                break

            try:
                await asyncio.wait_for(
                    asyncio.shield(consult_task),
                    timeout=poll_interval,
                )
            except asyncio.TimeoutError:
                pass

    async def _emit_citations(
        self,
        citations: object,
        emitter: Optional[EventEmitter],
    ) -> None:
        if not isinstance(citations, list):
            return

        for position, citation in enumerate(citations, start=1):
            if not isinstance(citation, dict):
                continue
            metadata = citation.get("metadata") or {}
            if not isinstance(metadata, dict):
                metadata = {}

            # Laravel owns citation identity. The fallback only keeps malformed
            # prototype responses usable; answer_markdown markers are untouched.
            citation_id = citation.get("id")
            if citation_id is None or str(citation_id).strip() == "":
                citation_id = position

            await self._safe_emit(
                emitter,
                {
                    "type": "citation",
                    "data": {
                        "document": [str(citation.get("document") or "")],
                        "metadata": [
                            {
                                "kind": str(citation.get("kind") or ""),
                                "guideline": str(metadata.get("guideline") or ""),
                                "recommendation_id": str(
                                    metadata.get("recommendation_id") or ""
                                ),
                            }
                        ],
                        "source": {
                            "id": str(citation_id),
                            "name": str(
                                citation.get("title")
                                or f"Citation {citation_id}"
                            ),
                        },
                    },
                },
            )

    @staticmethod
    def _render_degradation(degradation: object) -> str:
        if not isinstance(degradation, list) or not degradation:
            return ""

        lines = ["## ⚠️ Degradation notice", ""]
        for item in degradation:
            if isinstance(item, dict):
                stage = str(item.get("stage") or "").strip()
                reason = str(item.get("reason") or "").strip()
                unavailable = item.get("unavailable")
                label = f"**{stage}**: " if stage else ""
                detail = reason or "A backend stage was unavailable."
                if unavailable not in (None, "", [], {}):
                    detail += f" Unavailable: {unavailable}"
                lines.append(f"- {label}{detail}")
            elif item not in (None, ""):
                lines.append(f"- {item}")
        return "\n".join(lines).strip()

    @staticmethod
    def _render_body(data: dict) -> str:
        answer_markdown = data.get("answer_markdown")
        if answer_markdown not in (None, ""):
            return str(answer_markdown)

        # Contract-shift fallback: keep the two frames visibly separate and
        # relay their content verbatim.
        sections = []
        grounded = data.get("guideline_grounded_answer")
        interpretation = data.get("interpretive_frame")
        if grounded not in (None, ""):
            sections.append(
                f"## Guideline-grounded answer\n\n{str(grounded)}"
            )
        if interpretation not in (None, ""):
            sections.append(
                f"## Explicitly flagged interpretation\n\n{str(interpretation)}"
            )
        return "\n\n".join(sections)

    @staticmethod
    def _render_questions(questions: object) -> str:
        if not isinstance(questions, list) or not questions:
            return ""
        lines = ["## Questions", ""]
        for question in questions:
            if isinstance(question, dict):
                value = (
                    question.get("question")
                    or question.get("message")
                    or question.get("text")
                )
            else:
                value = question
            if value not in (None, ""):
                lines.append(f"- {value}")
        return "\n".join(lines).strip() if len(lines) > 2 else ""

    @staticmethod
    def _render_assets(assets: object) -> str:
        if not isinstance(assets, list) or not assets:
            return ""
        lines = ["## Assets", ""]
        rendered = 0
        for asset in assets:
            if not isinstance(asset, dict):
                continue
            image_url = str(
                asset.get("thumbnail_url") or asset.get("url") or ""
            ).strip()
            full_url = str(asset.get("url") or image_url).strip()
            if not image_url:
                continue
            label = str(asset.get("label") or "Guideline asset").replace(
                "]", r"\]"
            )
            caption = str(asset.get("caption") or "").strip()
            lines.append(f"![{label}]({image_url})")
            if caption:
                lines.append(caption)
            if full_url and full_url != image_url:
                lines.append(f"[Open full-size asset]({full_url})")
            lines.append("")
            rendered += 1
        return "\n".join(lines).strip() if rendered else ""

    def _render_answer(self, data: dict) -> str:
        parts = [
            self._render_degradation(data.get("degradation")),
            self._render_body(data),
            self._render_questions(data.get("questions")),
            self._render_assets(data.get("assets")),
        ]
        rendered = "\n\n".join(part for part in parts if part).strip()
        return rendered or "The clinical gate returned no answer content."

    async def clinical_gate(
        self,
        message: str,
        state: Optional[dict] = None,
        __event_emitter__: Optional[EventEmitter] = None,
    ) -> str:
        """
        Send a message and optional gate state to the Laravel clinical gate.

        The backend owns all clinical reasoning, answer text, citation selection,
        and state transitions. This tool only transports and renders its result.

        :param message: The clinician's message.
        :param state: State returned by an earlier clinical-gate response.
        :return: The backend's structured Markdown answer.
        """
        request_id = str(uuid.uuid4())
        safe_state = state if isinstance(state, dict) else {}
        timeout = max(1.0, float(self.valves.REQUEST_TIMEOUT_SECONDS))

        try:
            async with httpx.AsyncClient(timeout=timeout) as client:
                consult_task = asyncio.create_task(
                    self._post_consult(
                        client,
                        message,
                        safe_state,
                        request_id,
                    )
                )
                if self.valves.EMIT_STATUS:
                    await self._poll_while_running(
                        client,
                        request_id,
                        consult_task,
                        __event_emitter__,
                    )
                data = await consult_task
        except httpx.TimeoutException:
            return (
                "Clinical gate request timed out before an answer was returned. "
                "Please try again."
            )
        except httpx.HTTPStatusError as exc:
            return (
                "Clinical gate request failed "
                f"(HTTP {exc.response.status_code}). Please try again."
            )
        except httpx.RequestError:
            return (
                "Clinical gate request could not reach the backend. "
                "Please try again."
            )
        except (TypeError, ValueError):
            return "Clinical gate returned an invalid response. Please try again."
        except Exception:
            return "Clinical gate request failed unexpectedly. Please try again."

        await self._emit_citations(
            data.get("citations"),
            __event_emitter__,
        )
        return self._render_answer(data)
