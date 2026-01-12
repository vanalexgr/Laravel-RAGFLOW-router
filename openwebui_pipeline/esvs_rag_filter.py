 [{correlation_id}] First attempt with {timeout_seconds}s cold-start timeout")

                    response = await client.post(
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
                last_error = f"Timeout after {timeout_seconds}s (attempt {attempt + 1})"
                print(f"[{self.name}] [{correlation_id}] {last_error} - services may be waking up")
            except httpx.ConnectError as e:
                last_error = f"Connection error: {e}"
            except Exception as e:
                last_error = f"Unexpected error: {e}"
            
            if attempt < self.valves.MAX_RETRIES - 1:
                # Longer delay after cold-start timeout to allow services to fully wake
                base_delay = 3.0 if attempt == 0 else self.valves.RETRY_BASE_DELAY
                delay = base_delay * (2 ** attempt) + random.uniform(0, 1.0)
                print(f"[{self.name}] [{correlation_id}] Attempt {attempt + 1} failed, retrying in {delay:.1f}s...")
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
        files_top = body.get("files") or []
        print(f"[{self.name}] [{correlation_id}] Body keys: {body_keys}")
        print(f"[{self.name}] [{correlation_id}] Top-level files: {len(files_top)}")
        
        # Check for files in messages
        for i, msg in enumerate(messages):
            if msg.get("role") == "user":
                msg_files = msg.get("files") or []
                msg_images = msg.get("images") or []
                msg_attachments = msg.get("attachments") or []
                if msg_files or msg_images or msg_attachments:
                    print(f"[{self.name}] [{correlation_id}] Message {i} files: {len(msg_files)}, images: {len(msg_images)}, attachments: {len(msg_attachments)}")
                    if msg_files:
                        for f in msg_files[:2]:
                            print(f"[{self.name}] [{correlation_id}]   File structure: {list(f.keys()) if isinstance(f, dict) else type(f)}")
        
        patient_context, attachment_metadata = self._extract_attachments(body)
        
        if patient_context:
            extracted_count = sum(1 for m in attachment_metadata if m.get("status") == "extracted")
            print(f"[{self.name}] [{correlation_id}] Extracted {len(patient_context)} chars from {extracted_count}/{len(attachment_metadata)} attachment(s)")
        
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
