"""
title: ESVS Vascular Guidelines RAG Filter
author: Medical AI Team
date: 2026-01-20
version: 2.8
license: MIT
description: Filter pipeline that retrieves ESVS vascular surgery guidelines from Laravel API with dual-source retrieval (narrative + citations), document attachment processing, and PHI de-identification. Includes retry logic, warm-up, cold-start resilience, routing method visibility, real-time status updates, and OpenWebUI v0.3+ attachment support.
requirements: httpx, pdfminer.six, python-docx
"""

from typing import Optional, Tuple, List, Dict, Any
import asyncio
import random
import uuid
import io
import base64
import httpx
import time
from pydantic import BaseModel, Field

try:
    from pdfminer.high_level import extract_text as pdf_extract_text
    from pdfminer.pdfparser import PDFSyntaxError

    PDF_AVAILABLE = True
except ImportError:
    PDF_AVAILABLE = False

try:
    from docx import Document as DocxDocument

    DOCX_AVAILABLE = True
except ImportError:
    DOCX_AVAILABLE = False


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
            default=8, description="Total chunks to retrieve (narrative + citations)"
        )
        ENABLE_RAG: bool = Field(
            default=True, description="Enable/disable guideline retrieval"
        )
        TIMEOUT_SECONDS: int = Field(
            default=20,
            description="Request timeout in seconds (tightened to stay under gateway limit)",
        )
        COLD_START_TIMEOUT: int = Field(
            default=25,
            description="Extended timeout for first request (tightened to stay under gateway limit)",
        )
        INJECT_SYSTEM_PROMPT: bool = Field(
            default=True,
            description="Inject recommended system prompt from API response",
        )
        MAX_RETRIES: int = Field(
            default=2,
            description="Maximum number of retry attempts for failed requests",
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
        PROCESS_ATTACHMENTS: bool = Field(
            default=True,
            description="Extract and process attached documents (PDF, DOCX, TXT)",
        )
        MAX_ATTACHMENT_SIZE_KB: int = Field(
            default=500, description="Maximum attachment size in KB to process"
        )
        MAX_EXTRACTED_CHARS: int = Field(
            default=15000, description="Maximum characters to extract from attachments"
        )

    def __init__(self):
        self.valves = self.Valves()
        self.name = "ESVS Vascular Guidelines RAG"
        self._client = None
        self._warmup_complete = False

    async def _emit_status(self, event_emitter, description: str, done: bool = False):
        """Emit a status update to OpenWebUI UI (replaces pulsating dot)."""
        if event_emitter:
            try:
                await event_emitter(
                    {
                        "type": "status",
                        "data": {"description": description, "done": done},
                    }
                )
            except Exception as e:
                print(f"[{self.name}] Status emit error: {e}")

    async def on_startup(self):
        print(f"[{self.name}] Pipeline initialized (v2.8 - real-time status updates)")
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
            health_url = self.valves.RETRIEVE_API_URL.replace(
                "/retrieve", "/health/retrieval"
            )
            response = await self._client.get(
                health_url,
                headers={"Authorization": f"Bearer {self.valves.API_KEY}"},
            )
            if response.status_code == 200:
                data = response.json()
                print(f"[{self.name}] Warmup complete: {data.get('status', 'ok')}")
                self._warmup_complete = True
            else:
                print(
                    f"[{self.name}] Warmup health check returned {response.status_code}, trying retrieve..."
                )
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

    def _extract_text_from_pdf(self, content: bytes) -> str:
        """Extract text from PDF bytes."""
        if not PDF_AVAILABLE:
            return "[PDF extraction unavailable]"
        try:
            return pdf_extract_text(io.BytesIO(content))
        except PDFSyntaxError:
            return "[Invalid PDF format]"
        except Exception as e:
            return f"[PDF extraction error: {str(e)[:50]}]"

    def _extract_text_from_docx(self, content: bytes) -> str:
        """Extract text from DOCX bytes."""
        if not DOCX_AVAILABLE:
            return "[DOCX extraction unavailable]"
        try:
            doc = DocxDocument(io.BytesIO(content))
            paragraphs = [p.text for p in doc.paragraphs if p.text.strip()]
            return "\n".join(paragraphs)
        except Exception as e:
            return f"[DOCX extraction error: {str(e)[:50]}]"

    def _extract_text_from_txt(self, content: bytes) -> str:
        """Extract text from plain text bytes."""
        try:
            for encoding in ["utf-8", "latin-1", "cp1252"]:
                try:
                    return content.decode(encoding)
                except UnicodeDecodeError:
                    continue
            return content.decode("utf-8", errors="replace")
        except Exception as e:
            return f"[Text extraction error: {str(e)[:50]}]"

    def _get_file_content(self, file_info: dict) -> Optional[bytes]:
        """
        Get file content from OpenWebUI file info structure.
        Supports multiple formats:
        - base64 data in 'data' field (legacy)
        - raw content in 'content' field
        - file path in 'path' field (OpenWebUI v0.3+)
        - URL in 'url' field (OpenWebUI v0.3+)
        """
        keys = list(file_info.keys())
        print(f"[{self.name}] File structure: available_keys={keys}")

        if "data" in file_info and file_info["data"]:
            data = file_info["data"]
            if isinstance(data, str):
                if "," in data:
                    data = data.split(",", 1)[1]
                try:
                    print(f"[{self.name}]   -> extracted from base64 data")
                    return base64.b64decode(data)
                except Exception as e:
                    print(
                        f"[{self.name}]   -> base64 decode failed: {type(e).__name__}"
                    )

        if "content" in file_info:
            content = file_info["content"]
            if isinstance(content, bytes):
                print(
                    f"[{self.name}]   -> using raw bytes content ({len(content)} bytes)"
                )
                return content
            elif isinstance(content, str) and len(content) > 0:
                print(f"[{self.name}]   -> using string content ({len(content)} chars)")
                return content.encode("utf-8")

        if "path" in file_info and file_info["path"]:
            print(f"[{self.name}]   -> reading from local path")
            try:
                with open(file_info["path"], "rb") as f:
                    return f.read()
            except Exception as e:
                print(f"[{self.name}]   -> path read failed: {type(e).__name__}")

        if "url" in file_info and file_info["url"]:
            url = file_info["url"]
            if url.startswith(
                ("http://localhost", "http://127.0.0.1", "https://localhost")
            ):
                print(f"[{self.name}]   -> fetching from local URL")
                try:
                    import urllib.request

                    with urllib.request.urlopen(url, timeout=10) as response:
                        return response.read()
                except Exception as e:
                    print(f"[{self.name}]   -> URL fetch failed: {type(e).__name__}")
            else:
                print(f"[{self.name}]   -> skipping external URL for security")

        if "file" in file_info and isinstance(file_info["file"], dict):
            print(f"[{self.name}]   -> found nested file object, recursing")
            return self._get_file_content(file_info["file"])

        print(f"[{self.name}]   -> no content source found")
        return None

    def _extract_attachments(self, body: dict) -> Tuple[str, List[Dict[str, Any]]]:
        """
        Extract text from attached files in the OpenWebUI request body.
        Also captures OpenWebUI's pre-processed document context.
        Returns (combined_text, attachment_metadata) tuple.
        """
        if not self.valves.PROCESS_ATTACHMENTS:
            return ("", [])

        extracted_texts = []
        metadata = []

        body_keys = [k for k in body.keys() if k != "messages"]
        files_in_body = len(body.get("files") or [])
        files_in_metadata = (
            len((body.get("metadata") or {}).get("files") or [])
            if isinstance(body.get("metadata"), dict)
            else 0
        )

        files_in_messages = 0
        for msg in body.get("messages") or []:
            if msg.get("role") == "user":
                msg_files = msg.get("files") or []
                files_in_messages += len(msg_files)

        print(
            f"[{self.name}] Attachment scan: body_keys={body_keys}, files_in_body={files_in_body}, files_in_metadata={files_in_metadata}, files_in_messages={files_in_messages}"
        )

        for msg in body.get("messages") or []:
            if msg.get("role") == "system":
                content = msg.get("content") or ""
                if content and (
                    "### Document:" in content
                    or "File:" in content
                    or "Attached:" in content
                ):
                    extracted_texts.append(content)
                    metadata.append(
                        {
                            "id": "system_context",
                            "status": "extracted",
                            "chars": len(content),
                        }
                    )

            if "context" in msg:
                ctx = msg.get("context") or ""
                if isinstance(ctx, str) and len(ctx) > 50:
                    extracted_texts.append(ctx)
                    metadata.append(
                        {"id": "msg_context", "status": "extracted", "chars": len(ctx)}
                    )

            if msg.get("role") == "user" and "files" in msg:
                for idx, f in enumerate(msg.get("files") or []):
                    if isinstance(f, dict):
                        if "extracted_content" in f and f["extracted_content"]:
                            text = f["extracted_content"]
                            extracted_texts.append(text)
                            metadata.append(
                                {
                                    "id": f"msg_file_{idx}",
                                    "status": "extracted",
                                    "chars": len(text),
                                }
                            )
                        elif "text" in f and f["text"]:
                            text = f["text"]
                            extracted_texts.append(text)
                            metadata.append(
                                {
                                    "id": f"msg_file_{idx}",
                                    "status": "extracted",
                                    "chars": len(text),
                                }
                            )
                        else:
                            file_meta = self._process_file_attachment(f, f"msg_{idx}")
                            if file_meta.get("status") == "extracted":
                                extracted_texts.append(file_meta.get("text", ""))
                            metadata.append(file_meta)

        for source_name, files_list in [
            ("body", body.get("files") or []),
            ("metadata", (body.get("metadata") or {}).get("files") or []),
        ]:
            for idx, f in enumerate(files_list):
                if isinstance(f, dict):
                    file_meta = self._process_file_attachment(f, f"{source_name}_{idx}")
                    if file_meta.get("status") == "extracted":
                        extracted_texts.append(file_meta.get("text", ""))
                    metadata.append(file_meta)

        combined_text = "\n\n---\n\n".join(extracted_texts)

        if len(combined_text) > self.valves.MAX_EXTRACTED_CHARS:
            combined_text = combined_text[: self.valves.MAX_EXTRACTED_CHARS]
            print(
                f"[{self.name}] Truncated combined attachments to {self.valves.MAX_EXTRACTED_CHARS} chars"
            )

        return (combined_text, metadata)

    def _process_file_attachment(self, file_info: dict, file_id: str) -> Dict[str, Any]:
        """Process a single file attachment and extract text."""
        filename = file_info.get("name", file_info.get("filename", "unknown"))
        filetype = file_info.get("type", file_info.get("mime_type", ""))

        ext = filename.lower().split(".")[-1] if "." in filename else ""
        if not filetype:
            if ext == "pdf":
                filetype = "application/pdf"
            elif ext in ["doc", "docx"]:
                filetype = "application/vnd.openxmlformats-officedocument.wordprocessingml.document"
            elif ext == "txt":
                filetype = "text/plain"

        size_kb = file_info.get("size", 0) / 1024 if file_info.get("size") else 0
        if size_kb > self.valves.MAX_ATTACHMENT_SIZE_KB:
            return {
                "id": file_id,
                "status": "skipped",
                "reason": f"too large ({size_kb:.0f}KB)",
            }

        content = self._get_file_content(file_info)
        if not content:
            return {"id": file_id, "status": "skipped", "reason": "no content"}

        text = ""
        if "pdf" in filetype.lower() or ext == "pdf":
            text = self._extract_text_from_pdf(content)
        elif "word" in filetype.lower() or ext in ["doc", "docx"]:
            text = self._extract_text_from_docx(content)
        elif "text" in filetype.lower() or ext == "txt":
            text = self._extract_text_from_txt(content)
        else:
            return {
                "id": file_id,
                "status": "skipped",
                "reason": f"unsupported type: {filetype or ext}",
            }

        if text and not text.startswith("["):
            return {
                "id": file_id,
                "status": "extracted",
                "chars": len(text),
                "text": text,
            }
        else:
            return {"id": file_id, "status": "failed", "reason": text}

    async def _retrieve_with_retry(
        self,
        question: str,
        correlation_id: str,
        patient_context: str = "",
        history: List[str] = [],
        event_emitter=None,
    ) -> Tuple[Optional[dict], str]:
        """Retrieve guidelines with retry logic and exponential backoff (reuses self._client)."""
        last_error = ""

        payload = {"question": question, "top_k": self.valves.TOP_K}
        if patient_context:
            payload["patient_context"] = patient_context
        if history:
            payload["history"] = history

        # Ensure shared client exists (covers cases where inlet fires before on_startup)
        if self._client is None:
            self._client = httpx.AsyncClient(
                timeout=httpx.Timeout(self.valves.TIMEOUT_SECONDS)
            )

        for attempt in range(self.valves.MAX_RETRIES):
            timeout_seconds = (
                self.valves.COLD_START_TIMEOUT
                if attempt == 0 and not self._warmup_complete
                else self.valves.TIMEOUT_SECONDS
            )

            try:
                if attempt == 0 and not self._warmup_complete:
                    print(
                        f"[{self.name}] [{correlation_id}] First attempt with {timeout_seconds}s cold-start timeout"
                    )

                response = await self._client.post(
                    self.valves.RETRIEVE_API_URL,
                    json=payload,
                    headers={
                        "Authorization": f"Bearer {self.valves.API_KEY}",
                        "Content-Type": "application/json",
                        "Accept": "application/json",
                        "X-Correlation-ID": correlation_id,
                    },
                    timeout=httpx.Timeout(timeout_seconds),
                )

                if response.status_code == 200:
                    data = response.json()
                    if data.get("success"):
                        return (data, "")
                    else:
                        last_error = f"API error: {data.get('error', 'unknown')}"
                else:
                    snippet = ""
                    try:
                        snippet = (response.text or "").strip().replace("\n", " ")[:160]
                    except Exception:
                        snippet = ""
                    last_error = (
                        f"HTTP {response.status_code}"
                        + (f" | {snippet}" if snippet else "")
                    )

            except httpx.TimeoutException:
                last_error = f"Timeout after {timeout_seconds}s (attempt {attempt + 1})"
                print(
                    f"[{self.name}] [{correlation_id}] {last_error} - services may be waking up"
                )
            except httpx.ConnectError as e:
                last_error = f"Connection error: {e}"
            except httpx.HTTPError as e:
                last_error = f"HTTP client error: {e}"
            except Exception as e:
                last_error = f"Unexpected error: {e}"

            if attempt < self.valves.MAX_RETRIES - 1:
                base_delay = 3.0 if attempt == 0 else self.valves.RETRY_BASE_DELAY
                delay = base_delay * (2**attempt) + random.uniform(0, 1.0)
                print(
                    f"[{self.name}] [{correlation_id}] Attempt {attempt + 1} failed, retrying in {delay:.1f}s..."
                )
                await self._emit_status(
                    event_emitter,
                    f"Retrying... (attempt {attempt + 2}/{self.valves.MAX_RETRIES})",
                )
                await asyncio.sleep(delay)

        return (None, last_error)

    def _inject_failure_warning(
        self, body: dict, user_message: str, error: str, correlation_id: str
    ) -> dict:
        """Inject a warning message when retrieval fails so user knows context is missing."""
        if not self.valves.SHOW_FAILURE_WARNING:
            return body

        warning = f"""**Guideline Retrieval Failed**

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

    async def inlet(
        self, body: dict, __event_emitter__=None, user: Optional[dict] = None
    ) -> dict:
        """
        Called before request goes to LLM.
        Extracts text from attachments, retrieves guideline chunks, and injects context.
        """
        start_overall = time.time()
        emitter = __event_emitter__

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

        await self._emit_status(emitter, "Analyzing query...")

        # Process attachments once
        t_attach_start = time.time()
        patient_context, attachment_metadata = self._extract_attachments(body)
        t_attach_end = time.time()
        print(f"[{self.name}] [{correlation_id}] Attachment extraction took {t_attach_end - t_attach_start:.3f}s")

        if patient_context:
            extracted_count = sum(
                1 for m in attachment_metadata if m.get("status") == "extracted"
            )
            print(
                f"[{self.name}] [{correlation_id}] Extracted {len(patient_context)} chars from {extracted_count}/{len(attachment_metadata)} attachment(s)"
            )
            await self._emit_status(
                emitter,
                f"Extracted {extracted_count} document(s), de-identifying PHI...",
            )

        await self._emit_status(emitter, "Searching ESVS guidelines for evidence...")

        # Extract history for context-aware routing
        history = [m.get("content", "") for m in messages if m.get("role") == "user"]
        if history and history[-1] == user_message:
            history.pop() # Remove current message

        if history:
            print(f"[{self.name}] [{correlation_id}] Context history: {len(history)} messages")

        t_retrieval_start = time.time()
        data, error = await self._retrieve_with_retry(
            user_message, correlation_id, patient_context, history, emitter
        )
        t_retrieval_end = time.time()
        print(f"[{self.name}] [{correlation_id}] Retrieval call took {t_retrieval_end - t_retrieval_start:.3f}s")

        if data is None:
            print(
                f"[{self.name}] [{correlation_id}] FAILED after {self.valves.MAX_RETRIES} attempts: {error}"
            )
            await self._emit_status(emitter, f"Retrieval failed: {error}", done=True)
            return self._inject_failure_warning(
                body, user_message, error, correlation_id
            )

        narrative_chunks = data.get("narrative_chunks", [])
        citation_chunks = data.get("citation_chunks", [])
        selected_guidelines = data.get("selected_guidelines", {})
        duration_ms = data.get("duration_ms", 0)
        system_prompt = data.get("system_prompt", "")

        routing_method = data.get("routing_method", "unknown")
        guideline_names = [g.get("name", k) for k, g in selected_guidelines.items()]
        print(
            f"[{self.name}] [{correlation_id}] Retrieved {len(narrative_chunks)} narrative + {len(citation_chunks)} citation chunks in {duration_ms}ms"
        )
        print(
            f"[{self.name}] [{correlation_id}] Routing: {routing_method} | Guidelines: {list(selected_guidelines.keys())}"
        )

        total_chunks = len(narrative_chunks) + len(citation_chunks)
        if guideline_names:
            guideline_display = ", ".join(guideline_names[:3])
            if len(guideline_names) > 3:
                guideline_display += f" +{len(guideline_names) - 3} more"
            await self._emit_status(
                emitter,
                f"Retrieved {total_chunks} evidence chunks from {guideline_display}",
            )
        else:
            await self._emit_status(emitter, f"Retrieved {total_chunks} evidence chunks")

        if not narrative_chunks and not citation_chunks:
            print(
                f"[{self.name}] [{correlation_id}] WARNING: No chunks retrieved - empty context"
            )
            await self._emit_status(emitter, "No relevant guidelines found", done=True)
            return self._inject_failure_warning(
                body, user_message, "No relevant guidelines found", correlation_id
            )

        await self._emit_status(emitter, "Formatting clinical context...")

        context = self._format_dual_context(
            narrative_chunks, citation_chunks, selected_guidelines
        )

        if self.valves.INJECT_SYSTEM_PROMPT and system_prompt:
            has_system = any(m.get("role") == "system" for m in messages)
            if not has_system:
                messages.insert(0, {"role": "system", "content": system_prompt})
            body["messages"] = messages

        patient_context_section = ""
        scrubbed_patient_context = data.get("scrubbed_patient_context", "")
        if scrubbed_patient_context:
            patient_context_section = f"""## Patient Clinical Context (De-identified)
{scrubbed_patient_context}

---

"""

        rag_prompt = f"""## ESVS Vascular Surgery Guidelines Evidence

{context}

---

{patient_context_section}## User Question
{user_message}

---

**Instructions**: 
1. Review the patient context (if provided) to understand the clinical scenario
2. Synthesize your clinical answer using the NARRATIVE CONTEXT from ESVS guidelines
3. Cite ONLY from the CITATION EVIDENCE using exact recommendation text
4. Format: Rec [ID] (Class [X], Level [Y]) with verbatim quote
5. If guidelines don't fully cover the case, state this clearly"""

        for i, msg in enumerate(body["messages"]):
            if msg.get("role") == "user" and msg.get("content") == user_message:
                body["messages"][i]["content"] = rag_prompt
                break

        await self._emit_status(emitter, "Context ready, generating response...", done=True)
        
        total_time = time.time() - start_overall
        print(f"[{self.name}] [{correlation_id}] TOTAL filter duration: {total_time:.3f}s")

        return body

    def _format_dual_context(
        self, narrative_chunks: list, citation_chunks: list, guidelines: dict
    ) -> str:
        """Format dual-source chunks into structured context."""
        guideline_names = [g.get("name", k) for k, g in guidelines.items()]

        sections = []

        if narrative_chunks:
            sections.append("### NARRATIVE CONTEXT (for clinical synthesis)")
            sections.append(f"**Sources**: {', '.join(guideline_names)}\n")

            for i, chunk in enumerate(narrative_chunks, 1):
                content = chunk.get("content", "")
                source = chunk.get("source_guideline", "")
                similarity = chunk.get("similarity", 0)

                sections.append(f"**[{i}] {source}** (relevance: {similarity}%)")
                sections.append(f"{content}\n")

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
                    meta_parts.append(f"- {guideline}")

                meta = " ".join(meta_parts) if meta_parts else f"Citation {i}"
                sections.append(f"{meta}")
                sections.append(f'> "{text}"\n')

        return "\n".join(sections)

    async def outlet(self, body: dict, user: Optional[dict] = None) -> dict:
        """Called after LLM response. Pass through without modification."""
        return body
