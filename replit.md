# Laravel RAGFlow Router

## Overview
This Laravel 12 app provides a tool-focused integration for vascular surgery guideline retrieval. It exposes:
- A tool API endpoint for OpenWebUI.
- An MCP server for alternative client integrations.

The retrieval pipeline performs PHI scrubbing, guideline routing, and dual retrieval (narrative + citations) via RAGFlow.

## User Preferences
- I prefer clear and well-structured code.
- I expect detailed explanations for complex architectural decisions.
- I want iterative development with clear communication before significant changes are made.
- Do not make changes to the `vendor/` folder.
- Do not make changes to the `.env` file directly; provide instructions for environment variable setup instead.

## System Architecture
**Primary Integration (OpenWebUI Tool):**
- Endpoint: `POST /api/v1/vascular-consult`
- OpenWebUI tool: `openwebui_tools/vascular_expert.py`
- Auth: `API_SECRET_KEY` via `Authorization: Bearer`

**MCP Integration (Optional):**
- SSE stream: `GET /vascular`
- Message endpoint: `POST /vascular`
- Tool name: `consult_vascular_guidelines`

**Retrieval Pipeline:**
- PHI scrubbing via `PHIScrubberService`
- Guideline routing via `GuidelineRouterService` (Azure OpenAI)
- Dual retrieval via `RetrievalService` and the RAGFlow client

**RAGFlow Bridge (Optional):**
- FastAPI service in `ragflow_service/`
- Provides `/retrieve_multi` and `/retrieve_dual` for parallelized retrieval

## External Dependencies
- **Azure OpenAI**: Used for routing and query expansion.
- **RAGFlow**: Document retrieval, reranking, and knowledge graph.
- **FastAPI + httpx**: Optional local bridge for parallel retrieval.

## Recent Changes
- 2026-02-07: Removed OpenAI-compatible endpoints and OpenWebUI filter pipeline in favor of the tool-only integration. Updated docs and API spec.
