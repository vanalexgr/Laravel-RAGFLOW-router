# Laravel Application with Vizra ADK

## Overview
This project is a Laravel 12 PHP web application integrating the Vizra ADK framework for AI agent development. Its primary purpose is to provide medical guideline consultations by leveraging Azure OpenAI and RAGFlow. The application aims to deliver precise clinical answers supported by evidence from medical guidelines, focusing on vascular surgery. It features a sophisticated two-stage retrieval architecture for robust information synthesis and citation, offering both fast retrieval API and a comprehensive agent-driven pathway for compliance-critical use cases. The business vision is to provide a reliable AI assistant for medical professionals, enhancing access to and interpretation of complex guidelines.

## User Preferences
- I prefer clear and well-structured code.
- I expect detailed explanations for complex architectural decisions.
- I want iterative development with clear communication before significant changes are made.
- Do not make changes to the `vendor/` folder.
- Do not make changes to the `.env` file directly; provide instructions for environment variable setup instead.

## System Architecture
The application is built on Laravel 12 and uses the Vizra ADK for AI agent orchestration.

**UI/UX Decisions:**
- The application provides endpoints for integration with external UIs like OpenWebUI.
- Structured output is prioritized for external consumption, allowing UI frameworks to handle rendering.

**Technical Implementations:**
- **Azure OpenAI Integration:** Utilizes Azure OpenAI for large language model capabilities, specifically the `gpt-5-chat` deployment. A custom `AzureOpenAIProvider` ensures seamless integration with Vizra ADK.
- **RAGFlow Integration:** A custom Laravel 12 compatible client (`App\Services\RAGFlow\`) facilitates interaction with the RAGFlow API for document retrieval and knowledge graph functionality. It supports direct dataset retrieval and chat sessions.
- **Python Bridge:** An optional FastAPI bridge service (`ragflow_service/`) enhances RAGFlow capabilities by enabling advanced features like reranking and knowledge graph support.
- **Two-Stage Retrieval Architecture:** The core of the AI agent's workflow.
    - **Stage 1 (Answer Synthesis):** Involves `select_guidelines` to identify relevant medical guidelines using semantic routing (default, ~10ms) or LLM-based routing (~2-3s) with fallback to rule-based matching, followed by `consult_guideline` to query selected datasets with knowledge graph enabled for contextual retrieval.
    - **Stage 2 (Evidence Citation):** Employs `cite_recommendations` to query a recommendations-only dataset for verbatim citations, ensuring compliance and accuracy.
- **Semantic Router:** Ultra-fast guideline routing using local FastEmbed embeddings (6-41ms vs ~2-3s for LLM). Configured via `RAGFLOW_ROUTING_METHOD` env var. The router uses 14 routes with medical terminology utterances derived from guideline key_concepts.
- **Guideline Registry:** A centralized `config/guidelines.php` registers 14 guideline datasets, including their IDs, key concepts for routing, and categorical groupings.
- **Dual Architecture:**
    - **Fast Retrieval API (POST /api/v1/retrieve):** Provides quick, structured chunk retrieval (narrative and citation chunks) without LLM processing, ideal for UIs like OpenWebUI for client-side synthesis.
    - **Full Agent (POST /api/v1/chat/completions):** Leverages the `VascularExpertAgent` with multi-step prompt engineering for comprehensive, compliance-critical responses including clinical synthesis and detailed evidence.
- **OpenWebUI Filter Pipeline:** An asynchronous Python pipeline (`openwebui_pipeline/esvs_rag_filter.py`) for integrating with OpenWebUI, handling dual-chunk injection and structured context.
- **Token Management:** Includes strategies for context truncation and dynamic history budgeting to manage LLM token limits, especially with large queries.
- **API Endpoints:** Includes an OpenAI-compatible endpoint (`/api/v1/chat/completions`) for general chat interactions and a dedicated fast retrieval endpoint (`/api/v1/retrieve`).
- **Logging:** Comprehensive logging channels for retrieval events (`retrieval-YYYY-MM-DD.log`), RAGFlow interactions (`ragflow-YYYY-MM-DD.log`), and HTTP requests (`http-YYYY-MM-DD.log`) are implemented for observability and debugging, with privacy safeguards for sensitive data.

## External Dependencies
- **Azure OpenAI:** Utilized for LLM capabilities (text generation, summarization, understanding).
- **RAGFlow:** Used for document storage, retrieval-augmented generation (RAG), knowledge graph integration, and reranking.
- **Composer:** PHP dependency manager.
- **PHPUnit:** For testing PHP code.
- **SQLite:** Default database for development.
- **Guzzle:** HTTP client used for API interactions.
- **Uvicorn:** ASGI web server for the optional Python RAGFlow bridge.
- **FastAPI:** Python web framework used for the optional RAGFlow bridge.
- **httpx:** Asynchronous HTTP client used in the OpenWebUI filter pipeline.

## Recent Changes
- 2026-01-19: Implemented Semantic Router for ultra-fast guideline routing:
  - Created `ragflow_service/semantic_router_service.py` using FastEmbed local embeddings (no API calls)
  - Added `/route` endpoint to RAGFlow Bridge FastAPI service
  - Routing speed: **6-41ms** vs ~2-3s for LLM routing (~100x faster)
  - 14 routes with 9-12 utterances each based on key_concepts from config/guidelines.php
  - Configuration via `RAGFLOW_ROUTING_METHOD` env var: 'semantic' (default), 'llm', or 'semantic_with_llm_fallback'
  - Config added to `config/ragflow.php`
  - Updated `GuidelineRouterService::selectAndExpand()` to use semantic routing when configured
  - LLM query expansion still available alongside semantic routing for improved retrieval
  - Falls back to LLM routing if semantic returns empty (when using 'semantic_with_llm_fallback' mode)
- 2026-01-18: Updated datasets with better-formatted versions:
  - Acute Limb Ischaemia: new dataset ID 7dcce66ef3eb11f0b82c5ef3771a102d
  - Vascular Access: new dataset ID bbe0b3a0f39611f08b265ef3771a102d
  - Abdominal Aortic Aneurysm: new dataset ID 1e8b73dcf49911f09b845ef3771a102d
  - New datasets use different embedding model than original datasets
  - Verified cross-model compatibility: combined queries (old + new embeddings) work correctly
  - Reranking continues to work with mixed embedding sources
- 2026-01-17: Enabled Cohere reranking for improved retrieval quality:
  - Configured Cohere-rerank-v4.0-pro model in config/ragflow.php
  - Reranking applies to both narrative and citation chunk retrieval via retrieve_dual endpoint
  - Adds ~2-3s latency but significantly improves result relevance
  - RAGFlow returns reranked scores in the similarity field (no separate rerank_score)
  - Model ID format: `Cohere-rerank-v4.0-pro___OpenAI-API@OpenAI-API-Compatible`
- 2026-01-13: Configured Reserved VM deployment for 24/7 operation:
  - Created robust production startup script (scripts/production_start.sh)
  - Runs both Laravel Server (port 5000) and RAGFlow Bridge (port 8000) together
  - Includes health checks, process monitoring, and graceful shutdown
  - Click "Publish" → "Reserved VM" to deploy with no cold starts
- 2026-01-12: Document-aware guideline routing:
  - Created DocumentContextAnalyzerService to extract clinical entities from patient documents
  - Routing now uses BOTH patient document content AND the question for guideline selection
  - Matches conditions (gangrene, rest pain, DVT) and procedures against key_concepts in guidelines
  - LLM routing is merged with document analysis for optimal guideline selection
  - Falls back to document analysis when LLM is unavailable or fails
  - Example: Patient with gangrene + tissue loss + generic question → correctly routes to CLTI guideline
- 2026-01-12: Fixed guideline knowledge injection when routing fails:
  - Added keyword-based fallback scoring using key_concepts from config/guidelines.php
  - When LLM and rule-based routing both fail, top 4 matching guidelines are selected by keyword overlap
  - Prevents 404 errors for vague queries, ensures ESVS content is always available
  - Added detailed logging for dataset selection and retrieval
  - Response time maintained at ~8s instead of timeout (>30s when querying all 14 datasets)
- 2026-01-12: Filter v2.6 with OpenWebUI v0.3+ attachment support:
  - Fixed file attachment processing for newer OpenWebUI versions
  - Supports file path, URL, base64, and nested file objects
  - Sanitized logging to prevent PHI leakage (only counts/keys logged)
  - SSRF protection: only localhost URLs allowed for file fetching
  - Cold-start resilience: 60s timeout on first attempt, 45s thereafter
  - KeepAlive workflow pings Laravel and RAGFlow every 3 minutes
- 2026-01-12: Added document attachment processing for OpenWebUI:
  - Filter pipeline (v2.2) extracts text from PDF, DOCX, TXT attachments
  - Patient context is de-identified before retrieval
  - Scrubbed patient context returned to LLM for clinical synthesis
- 2026-01-12: Added European date and identifier formats:
  - European dates: DD/MM/YYYY, DD.MM.YYYY, DD-MM-YYYY
  - European MRN patterns: Greek (ΗΝ, ΑΜ, ΑΜΚΑ), German (Pat-Nr), French (NIP, NSS), Spanish (NHC), UK (NHS), Italian (CF), and 15+ more
- 2026-01-12: Implemented PHI de-identification for HIPAA compliance:
  - PHIScrubberService with Safe Harbor pattern matching (names, dates, SSN, MRN, phone, email, addresses, cities, ZIP, counties)
  - Automatic scrubbing before Azure OpenAI and RAGFlow calls
  - Ages 90+ converted to "90+" per HIPAA rules
  - PHI audit logging (redaction counts only, no PHI stored)
  - HIPAA compliance documentation in docs/HIPAA_COMPLIANCE.md
- 2026-01-11: Added pipeline resilience (v2.1):
  - Retry logic with exponential backoff (2 attempts) for retrieval API calls
  - User-visible warning when retrieval fails or returns empty (prevents silent hallucination)
  - Warm-up ping on pipeline startup to wake Laravel/RAGFlow services
  - Correlation ID (X-Correlation-ID) for cross-service request tracing
  - `/api/v1/health/retrieval` endpoint for quick status checks
  - `/health` endpoint on RAGFlow Bridge for monitoring
- 2026-01-11: Parallelized LLM routing + query expansion using Http::pool() - both calls run concurrently, reducing LLM overhead from ~3.8s to ~2.7s
- 2026-01-11: Added LLM-based query expansion for better retrieval - expands medical terminology (e.g., "blunt carotid trauma" → "blunt cerebrovascular injury BCVI") before RAGFlow retrieval