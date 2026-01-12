"""
title: ESVS Vascular Guidelines RAG Filter
author: Medical AI Team
date: 2026-01-12
version: 2.2
license: MIT
description: Filter pipeline that retrieves ESVS vascular surgery guidelines from Laravel API with dual-source retrieval (narrative + citations), document attachment processing, and PHI de-identification. Includes retry logic, warm-up, and failure visibility.
requirements: httpx, pdfminer.six, python-docx
"""

from typing import Optional, Tuple, List, Dict, Any
import asyncio
import random
import uuid
import io
import base64
import httpx
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
        PROCESS_ATTACHMENTS: bool = Field(
            default=True, description="Extract and process attached documents (PDF, DOCX, TXT)"
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

    async def on_startup(self):
        print(f"[{self.name}] Pipeline initialized (v2.4 - capture OpenWebUI pre-processed docs)")
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
            for encoding in ['utf-8', 'latin-1', 'cp1252']:
                try:
                    return content.decode(encoding)
                except UnicodeDecodeError:
                    continue
            return content.decode('utf-8', errors='replace')
        except Exception as e:
            return f"[Text extraction error: {str(e)[:50]}]"

    def _get_file_content(self, file_info: dict) -> Optional[bytes]:
        """Get file content from OpenWebUI file info structure."""
        if "data" in file_info and file_info["data"]:
            data = file_info["data"]
            if "," in data:
                data = data.split(",", 1)[1]
            try:
                return base64.b64decode(data)
            except Exception:
                return None
        if "content" in file_info:
            content = file_info["content"]
            if isinstance(content, bytes):
                return content
            elif isinstance(content, str):
                return content.encode('utf-8')
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

        # Method 1: Check for OpenWebUI's pre-processed document context
        # OpenWebUI may inject document content into messages as context
        for msg in body.get("messages", []):
            # Check for document context in system messages
            if msg.get("role") == "system":
                content = msg.get("content", "")
                # Look for OpenWebUI's document injection patterns
                if "### Document:" in content or "File:" in content or "Attached:" in content:
                    extracted_texts.append(content)
                    metadata.append({"name": "system_context", "status": "extracted", "chars": len(content)})
            
            # Check for context field in messages (OpenWebUI injects here sometimes)
            if "context" in msg:
                ctx = msg.get("context", "")
                if isinstance(ctx, str) and len(ctx) > 50:
                    extracted_texts.append(ctx)
                    metadata.append({"name": "msg_context", "status": "extracted", "chars": len(ctx)})
            
            # Check for files array with pre-extracted content
            if msg.get("role") == "user" and "files" in msg:
                for f in msg.get("files", []):
                    if isinstance(f, dict):
                        # OpenWebUI may include extracted_content or text field
                        for key in ["extracted_content", "text", "content", "summary"]:
                            if key in f and isinstance(f[key], str) and len(f[key]) > 20:
                                extracted_texts.append(f"--- {f.get('name', 'Document')} ---\n{f[key]}")
                                metadata.append({"name": f.get('name', 'file'), "status": "pre_extracted", "chars": len(f[key])})
                                break

        # Method 2: Check top-level context field
        if "context" in body:
            ctx = body.get("context", "")
            if isinstance(ctx, str) and len(ctx) > 50:
                extracted_texts.append(ctx)
                metadata.append({"name": "body_context", "status": "extracted", "chars": len(ctx)})

        # Method 3: Try raw file extraction (original method)
        files = body.get("files", [])
        if not files:
            for msg in body.get("messages", []):
                if msg.get("role") == "user" and "files" in msg:
                    files.extend(msg.get("files", []))

        if not files and not extracted_texts:
            return ("", [])

        max_size = self.valves.MAX_ATTACHMENT_SIZE_KB * 1024

        for file_info in files:
            if isinstance(file_info, str):
                continue

            filename = file_info.get("name", file_info.get("filename", "unknown"))
            file_type = file_info.get("type", file_info.get("mime_type", ""))
            
            content = self._get_file_content(file_info)
            if not content:
                metadata.append({"name": filename, "status": "no_content"})
                continue

            if len(content) > max_size:
                metadata.append({"name": filename, "status": "too_large", "size_kb": len(content) // 1024})
                continue

            ext = filename.lower().split(".")[-1] if "." in filename else ""
            
            if ext == "pdf" or "pdf" in file_type.lower():
                text = self._extract_text_from_pdf(content)
            elif ext in ["docx"] or "wordprocessingml" in file_type.lower():
                text = self._extract_text_from_docx(content)
            elif ext in ["txt", "text", "rtf", "md"] or "text/" in file_type.lower():
                text = self._extract_text_from_txt(content)
            else:
                metadata.append({"name": filename, "status": "unsupported_type", "type": file_type})
                continue

            if text and not text.startswith("["):
                extracted_texts.append(f"--- Document: {filename} ---\n{text}")
                metadata.append({"name": filename, "status": "extracted", "chars": len(text)})
            else:
                metadata.append({"name": filename, "status": "extraction_failed", "error": text})

        combined = "\n\n".join(extracted_texts)
        if len(combined) > self.valves.MAX_EXTRACTED_CHARS:
            combined = combined[:self.valves.MAX_EXTRACTED_CHARS] + "\n[...truncated due to length...]"

        return (combined, metadata)

    async def _retrieve_with_retry(self, question: str, correlation_id: str, patient_context: str = "") -> Tuple[Optional[dict], str]:
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

                payload = {"question": question, "top_k": self.valves.TOP_K}
                if patient_context:
                    payload["patient_context"] = patient_context

                response = await self._client.post(
                    self.valves.RETRIEVE_API_URL,
                    json=payload,
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
        Extracts text from attachments, retrieves guideline chunks, and injects context.
        Includes retry logic, attachment processing, and failure visibility.
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
        
        # Debug: Log body structure to understand where attachments are
        body_keys = list(body.keys())
        files_top = body.get("files", [])
        print(f"[{self.name}] [{correlation_id}] Body keys: {body_keys}")
        print(f"[{self.name}] [{correlation_id}] Top-level files: {len(files_top) if files_top else 0}")
        
        # Check for files in messages
        for i, msg in enumerate(messages):
            if msg.get("role") == "user":
                msg_files = msg.get("files", [])
                msg_images = msg.get("images", [])
                msg_attachments = msg.get("attachments", [])
                if msg_files or msg_images or msg_attachments:
                    print(f"[{self.name}] [{correlation_id}] Message {i} files: {len(msg_files)}, images: {len(msg_images)}, attachments: {len(msg_attachments)}")
                    if msg_files:
                        for f in msg_files[:2]:
                            print(f"[{self.name}] [{correlation_id}]   File structure: {list(f.keys()) if isinstance(f, dict) else type(f)}")
        
        patient_context, attachment_metadata = self._extract_attachments(body)
        
        if patient_context:
            print(f"[{self.name}] [{correlation_id}] Extracted {len(patient_context)} chars from {len(attachment_metadata)} attachment(s)")
            for meta in attachment_metadata:
                print(f"[{self.name}] [{correlation_id}]   - {meta.get('name')}: {meta.get('status')}")
        
        print(f"[{self.name}] [{correlation_id}] Retrieving guidelines for: {user_message[:100]}...")

        data, error = await self._retrieve_with_retry(user_message, correlation_id, patient_context)
        
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
