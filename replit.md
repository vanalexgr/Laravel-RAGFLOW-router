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
- ESVS Guidelines: `4fff3622eb1b11f09021f2381272676b`

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

## Recent Changes
- 2026-01-09: Added Python FastAPI bridge service (ragflow_service/) for reranking and knowledge graph support via official RAGFlow SDK patterns
- 2026-01-09: Laravel RAGFlowClient now supports bridge mode (RAGFLOW_USE_BRIDGE=true) with shared secret authentication
- 2026-01-09: Added dedicated RAGFlow logging channel - logs to storage/logs/ragflow-YYYY-MM-DD.log with full payload (dataset_ids, all params), response status, chunk count, timing
- 2026-01-09: Added use_toc parameter to RAGFlow retrieval for TOC/heading_path routing (RAGFLOW_USE_TOC env var, default true)
- 2026-01-09: Added HttpLogging middleware - logs all requests to storage/logs/http-YYYY-MM-DD.log with URL, headers (secrets redacted), request JSON, timing, and response
- 2026-01-09: Fixed tool invocation by setting tool_choice='required' on first request in AzureTextHandler - ensures agent uses consult_guideline tool instead of answering from training data
- 2026-01-09: Simplified ConsultGuidelineTool to single 'topic' parameter - Prism marks all parameters as required by default
- 2026-01-09: Added APP_KEY to .env (required for Laravel encryption)
- 2026-01-09: Changed rerank_model to rerank_id parameter with full identifier format: 'Cohere-rerank-v3-5-rdrns___OpenAI-API@OpenAI-API-Compatible'
- 2026-01-09: Added advanced RAGFlow retrieval options: top_k=20, top_n=6, rerank_id, knowledge_graph=ON
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
