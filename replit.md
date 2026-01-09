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
- 2026-01-09: Updated ConsultGuidelineTool to query RAGFlow datasets directly via `/api/v1/retrieval` endpoint
- 2026-01-09: Fixed Guzzle base_uri trailing slash issue for proper relative path resolution
- 2026-01-09: Implemented RAGFlow PHP client for Laravel 12 (custom implementation)
- 2026-01-09: Created ConsultGuidelineTool to query ESVS vascular surgery guidelines
- 2026-01-09: Fixed Azure OpenAI connection by explicitly setting provider in VascularExpertAgent
- Implemented custom AzureOpenAIProvider with handlers for text, structured, and streaming responses
- Registered Azure provider extension via AzureOpenAIServiceProvider
