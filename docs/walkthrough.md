# RAGFlow Tool Integration Walkthrough

## Goal
Provide a single, clean integration path for OpenWebUI using the tool API, while keeping the MCP server available for future integrations.

## Current Architecture

User Question
    -> OpenWebUI Tool (`openwebui_tools/vascular_expert.py`)
    -> Laravel Tool API (`POST /api/v1/vascular-consult`)
    -> RetrievalService (PHI scrub -> routing -> dual retrieval)
    -> RAGFlow Bridge (`/retrieve_dual`)
    -> Tool Response (narrative + citation chunks)

## OpenWebUI Tool Behavior
- Selects 1-3 guideline keys based on the question
- Sends recent conversation history for context fusion
- Calls `POST /api/v1/vascular-consult` and streams status updates

## Dual Retrieval Logic
- Narrative query uses the expanded query for broader context.
- Citation query uses the original question to keep recommendation matching tight.
- If citations are below a minimum threshold, the bridge retries with a lower similarity threshold and larger size to avoid missing recommendations.

## MCP Integration (Optional)
- SSE stream: `GET /vascular`
- Message endpoint: `POST /vascular`
- Tool name: `consult_vascular_guidelines`

## Key Files
- Tool API controller: `app/Http/Controllers/ToolController.php`
- Retrieval pipeline: `app/Services/RetrievalService.php`
- Guideline registry: `config/guidelines.php`
- OpenWebUI tool: `openwebui_tools/vascular_expert.py`
- MCP server: `app/Mcp/VascularServer.php`
