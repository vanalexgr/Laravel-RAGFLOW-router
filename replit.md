# Laravel Application with Vizra ADK

## Overview
This project is a Laravel 12 PHP web application integrating the Vizra ADK for AI agent development. Its core purpose is to provide medical guideline consultations, specifically in vascular surgery, by leveraging Azure OpenAI and RAGFlow. The application delivers precise clinical answers backed by evidence from medical guidelines through a sophisticated two-stage retrieval architecture. It offers both a fast retrieval API and a comprehensive agent-driven pathway for compliance-critical use cases, aiming to be a reliable AI assistant for medical professionals.

## User Preferences
- I prefer clear and well-structured code.
- I expect detailed explanations for complex architectural decisions.
- I want iterative development with clear communication before significant changes are made.
- Do not make changes to the `vendor/` folder.
- Do not make changes to the `.env` file directly; provide instructions for environment variable setup instead.

## System Architecture
The application is built on Laravel 12 and uses the Vizra ADK for AI agent orchestration.

**UI/UX Decisions:**
- Endpoints are provided for integration with external UIs like OpenWebUI.
- Structured output is prioritized for external consumption.

**Technical Implementations:**
- **Azure OpenAI Integration:** Utilizes Azure OpenAI for large language model capabilities, specifically the `gpt-5-chat` deployment, with a custom `AzureOpenAIProvider`.
- **RAGFlow Integration:** A custom Laravel client (`App\Services\RAGFlow\`) interacts with the RAGFlow API for document retrieval and knowledge graph functionality.
- **Python Bridge:** An optional FastAPI service (`ragflow_service/`) enhances RAGFlow capabilities with features like reranking and knowledge graph support.
- **Two-Stage Retrieval Architecture:**
    - **Stage 1 (Answer Synthesis):** Identifies relevant medical guidelines using semantic or LLM-based routing, followed by querying selected datasets with knowledge graph enabled.
    - **Stage 2 (Evidence Citation):** Queries a recommendations-only dataset for verbatim citations.
- **Semantic Router:** Employs local FastEmbed embeddings for ultra-fast guideline routing (~28ms for English). Supports multilingual queries via automatic translation: non-English queries are detected and translated to English using Azure OpenAI before routing (~1.2-1.5s overhead).
- **Guideline Registry:** A `config/guidelines.php` file registers 14 guideline datasets with key concepts for routing.
- **Dual Architecture:**
    - **Fast Retrieval API (POST /api/v1/retrieve):** Provides quick, structured chunk retrieval without LLM processing.
    - **Full Agent (POST /api/v1/chat/completions):** Leverages a `VascularExpertAgent` with multi-step prompt engineering for comprehensive, compliance-critical responses.
- **OpenWebUI Filter Pipeline:** An asynchronous Python pipeline (`openwebui_pipeline/esvs_rag_filter.py`) integrates with OpenWebUI for dual-chunk injection and structured context. Includes document attachment processing.
- **Token Management:** Strategies for context truncation and dynamic history budgeting manage LLM token limits.
- **API Endpoints:** Includes an OpenAI-compatible endpoint (`/api/v1/chat/completions`) and a dedicated fast retrieval endpoint (`/api/v1/retrieve`).
- **Logging:** Comprehensive logging for retrieval, RAGFlow, and HTTP interactions with privacy safeguards.
- **PHI De-identification:** Implemented via `PHIScrubberService` for HIPAA compliance before Azure OpenAI and RAGFlow calls.
- **Reranking:** Cohere reranking (`Cohere-rerank-v4.0-pro`) is enabled for improved retrieval quality.

## External Dependencies
- **Azure OpenAI:** For LLM capabilities.
- **RAGFlow:** For document storage, RAG, knowledge graph, and reranking.
- **Composer:** PHP dependency manager.
- **PHPUnit:** For testing.
- **SQLite:** Default database.
- **Guzzle:** HTTP client.
- **Uvicorn:** ASGI web server for the Python bridge.
- **FastAPI:** Python web framework for the optional RAGFlow bridge.
- **httpx:** Asynchronous HTTP client in the OpenWebUI filter pipeline.
- **langdetect:** Language detection library for multilingual query support.

## Recent Changes
- 2026-01-20: Added automatic query translation for multilingual routing
  - Non-English queries detected using langdetect and translated to English before semantic routing
  - Translation uses Azure OpenAI for accurate medical terminology preservation
  - English queries remain ultra-fast (~28ms), non-English add ~1.2-1.5s for translation
  - Response includes `detected_language`, `translated_query`, and `translation_ms`
- 2026-01-19: Exposed semantic router status via public health endpoint `/api/v1/health/retrieval`
- 2026-01-19: Added multilingual embedding model (`intfloat/multilingual-e5-large`) with model pre-loading on startup