# Laravel Application with Vizra ADK

## Overview
This is a Laravel 12 PHP web application with the Vizra ADK framework for AI agent development. It connects to Azure OpenAI for medical guideline consultations and uses RAGFlow for document retrieval.

## Project Structure
- `app/` - Application core code (Controllers, Models, Services)
  - `app/Agents/` - AI agents (VascularExpertAgent)
  - `app/Tools/` - Agent tools (ConsultGuidelineTool)
  - `app/Providers/LLM/` - Custom LLM providers (AzureOpenAIProvider)
  - `app/Services/RAGFlow/` - RAGFlow API client and resources
  - `app/Facades/` - Laravel facades (RAGFlow)
- `bootstrap/` - Framework bootstrap files
- `config/` - Configuration files (ragflow.php, prism.php, vizra-adk.php)
- `database/` - Migrations, seeders, SQLite database
- `public/` - Web server entry point and assets
- `resources/` - Views, CSS, JavaScript
- `routes/` - Route definitions
- `storage/` - Logs, cache, compiled files
- `tests/` - PHPUnit tests
- `vendor/` - Composer dependencies

## Azure OpenAI Integration
- Endpoint: https://alexiouv-5401-resource.cognitiveservices.azure.com
- Deployment: gpt-5-chat
- API Version: 2024-12-01-preview
- Custom provider implemented in `app/Providers/LLM/AzureOpenAIProvider.php`

**Important**: When using Azure OpenAI with Vizra ADK, you MUST explicitly set `protected ?string $provider = 'azure';` in your agent class. The framework auto-detects "gpt" in model names and defaults to OpenAI provider, overriding config settings.

## RAGFlow Integration
- Custom Laravel 12 compatible client in `app/Services/RAGFlow/`
- Facade: `App\Facades\RAGFlow`
- Config: `config/ragflow.php`
- Environment variables: `RAGFLOW_API_KEY`, `RAGFLOW_ENDPOINT` (must include `/api/v1` suffix)
- Direct dataset retrieval endpoint: `POST /api/v1/retrieval`

### Python Bridge (Optional - for reranking/KG support)
- Location: `ragflow_service/`
- Enables: `rerank_id` parameter, proper knowledge graph retrieval
- Port: 8000 (internal)
- Enable: Set `RAGFLOW_USE_BRIDGE=true` in .env
- Workflow: `RAGFlow Bridge`

### Dataset IDs
- ESVS Trauma 2025 (with KG): `8f58aeadec9411f0a38066bc68590b9b`
- ESVS Trauma Recs (no KG): `4fff3622eb1b11f09021f2381272676b`
- Default: queries both datasets simultaneously

### RAGFlow Usage
```php
use App\Facades\RAGFlow;

// Direct dataset retrieval (recommended for guideline queries)
$response = RAGFlow::datasets()->retrieve(
    ['4fff3622eb1b11f09021f2381272676b'], // dataset IDs
    [
        'question' => 'carotid artery guidelines',
        'top_k' => 10,
    ]
);

// Access retrieved chunks
foreach ($response['data']['chunks'] as $chunk) {
    echo $chunk['content'];
    echo $chunk['similarity']; // relevance score
}

// List datasets
$datasets = RAGFlow::datasets()->list();

// Chat sessions (alternative to direct retrieval)
$chats = RAGFlow::chat()->list();
$response = RAGFlow::chat()->sendMessage($chatId, ['message' => 'Hello']);
```

## Documentation
- See `docs/CONFIGURATION.md` for complete configuration guide

## Development Commands
- `php artisan serve --host=0.0.0.0 --port=5000` - Start development server
- `php artisan vizra:chat vascular_expert` - Chat with the vascular expert agent
- `php artisan migrate` - Run database migrations
- `php artisan make:controller ControllerName` - Create a controller
- `php artisan make:model ModelName -m` - Create a model with migration
- `php artisan tinker` - Interactive PHP shell

## Database
Currently using SQLite at `database/database.sqlite`

## Two-Stage Retrieval Architecture

The VascularExpertAgent uses a sophisticated two-stage workflow:

### Stage 1: Answer Synthesis
1. `select_guidelines` - Analyzes question, picks 1-3 relevant guideline datasets based on key concepts
2. `consult_guideline` - Queries selected datasets with KG enabled for rich contextual retrieval
3. Agent synthesizes clinical answer from retrieved content

### Stage 2: Evidence Citation
4. `cite_recommendations` - Queries the recommendations-only dataset (no KG, strict meta-tags)
5. Returns verbatim: recommendation number, guideline name, class, level, exact text
6. Agent formats response with Clinical Answer + Evidence sections

### Tools
- `SelectGuidelinesTool` - Uses `config/guidelines.php` registry to match question to datasets
- `ConsultGuidelineTool` - Accepts dynamic `dataset_ids`, queries with KG + reranking
- `CiteRecommendationsTool` - Queries only the recommendations dataset for exact citations

### Guideline Registry
All 14 guideline datasets are registered in `config/guidelines.php` with:
- Dataset IDs
- Key concepts for automatic routing
- Category groupings (Aortic, Peripheral, Venous, Specialty)

## Recent Changes
- 2026-01-10: Added streaming progress endpoint `/api/v1/chat/completions/stream` with 5 progress events: Selecting guidelines, Querying retrieval, KG expansion, Reranking, Drafting answer
- 2026-01-10: Per-guideline retrieval for multi-intent queries - separate RAGFlow calls per dataset (8 chunks each), interleaved merge, capped at 15 total
- 2026-01-10: Higher threshold (25 vs 5) for non-forced guidelines when ≥2 forced keys present - prevents carotid leakage in AAA+PAD queries
- 2026-01-10: **CRITICAL FIX** - Changed hard rules from exclusive to additive (force-include). Multi-intent queries (AAA + PAD) now correctly select both guidelines
- 2026-01-10: Added FORCE_INCLUDE_RULES for PAD, CLTI, DVT, Thoracic with comprehensive trigger synonyms
- 2026-01-10: Added observability logging - top 5 candidates with scores, matched concepts, forced keys for debugging
- 2026-01-10: Expanded PAD key_concepts in registry: added peripheral arterial disease, LEAD, ABI, intermittent claudication
- 2026-01-10: Fixed guideline routing - hard rules now boost (+50) but continue scoring all guidelines
- 2026-01-10: Switched from dataset_ids to guideline_keys routing - SelectGuidelinesTool returns keys, ConsultGuidelineTool maps via registry
- 2026-01-10: Added guideline_filter to CiteRecommendationsTool to prevent cross-guideline citation leakage
- 2026-01-10: Added registry validation - unknown dataset IDs are rejected with proper logging
- 2026-01-10: Implemented two-stage retrieval architecture with SelectGuidelinesTool, updated ConsultGuidelineTool (dynamic dataset_ids), and CiteRecommendationsTool for exact evidence citations
- 2026-01-10: Added config/guidelines.php with full registry of 14 guideline datasets organized by category with key concepts
- 2026-01-10: Updated VascularExpertAgent with multi-step workflow: select → retrieve → synthesize → cite
- 2026-01-09: Added Python FastAPI bridge service (ragflow_service/) for reranking and knowledge graph support via official RAGFlow SDK patterns
- 2026-01-09: Laravel RAGFlowClient now supports bridge mode (RAGFLOW_USE_BRIDGE=true) with shared secret authentication
- 2026-01-09: Added dedicated RAGFlow logging channel - logs to storage/logs/ragflow-YYYY-MM-DD.log with full payload (dataset_ids, all params), response status, chunk count, timing
- 2026-01-09: Added use_toc parameter to RAGFlow retrieval for TOC/heading_path routing (RAGFLOW_USE_TOC env var, default true)
- 2026-01-09: Added HttpLogging middleware - logs all requests to storage/logs/http-YYYY-MM-DD.log with URL, headers (secrets redacted), request JSON, timing, and response
- 2026-01-09: Fixed tool invocation by setting tool_choice='required' on first request in AzureTextHandler - ensures agent uses consult_guideline tool instead of answering from training data
- 2026-01-09: Simplified ConsultGuidelineTool to single 'topic' parameter - Prism marks all parameters as required by default
- 2026-01-09: Added APP_KEY to .env (required for Laravel encryption)
- 2026-01-09: Fixed RAGFlow API payload format - using dataset_ids (not kb_ids), added highlight, page, size parameters
- 2026-01-09: Added detailed retrieval logging - top 3 chunks with IDs, similarity, vector_similarity, term_similarity scores; rerank_id and use_kg echo
- 2026-01-09: Changed rerank_model to rerank_id parameter with full identifier format: 'Cohere-rerank-v3-5-rdrns___OpenAI-API@OpenAI-API-Compatible'
- 2026-01-09: Updated RAGFlow retrieval options: top_k=1024, size=10, rerank_id, use_kg=true, highlight=true
- 2026-01-09: Fixed multi-turn conversation memory by correcting property name to includeConversationHistory and passing full chat history from OpenWebUI messages
- 2026-01-09: Added API key authentication (ValidateApiKey middleware) for OpenAI-compatible endpoints
- 2026-01-09: Added OpenAI-compatible API endpoint for OpenWebUI integration (/api/v1/chat/completions)
- 2026-01-09: Added automatic metadata extraction (guideline ID, year, recommendation ID, class, level, territory, similarity scores) in ConsultGuidelineTool
- 2026-01-09: Added multi-turn memory support with includeHistory=true and contextStrategy='full' in VascularExpertAgent
- 2026-01-09: Added configurable retrieval settings (top_k, similarity_threshold, keyword_mode, vector_similarity_weight) in config/ragflow.php
- 2026-01-09: ConsultGuidelineTool now supports runtime parameter overrides for retrieval settings
- 2026-01-09: Fixed RAGFLOW_ENDPOINT environment variable name (was RAGFLOW_BASE_URL) to match config expectations
- 2026-01-09: Fixed Azure OpenAI tool format - tools must have 'function' wrapper and arguments as JSON strings
- 2026-01-09: Updated ConsultGuidelineTool to query RAGFlow datasets directly via `/api/v1/retrieval` endpoint
- 2026-01-09: Fixed Guzzle base_uri trailing slash issue for proper relative path resolution
- 2026-01-09: Implemented RAGFlow PHP client for Laravel 12 (custom implementation)
- 2026-01-09: Created ConsultGuidelineTool to query ESVS vascular surgery guidelines
- 2026-01-09: Fixed Azure OpenAI connection by explicitly setting provider in VascularExpertAgent
- Implemented custom AzureOpenAIProvider with handlers for text, structured, and streaming responses
- Registered Azure provider extension via AzureOpenAIServiceProvider
