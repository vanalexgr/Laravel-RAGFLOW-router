# Laravel Application with Vizra ADK

## Overview
This is a Laravel 12 PHP web application with the Vizra ADK framework for AI agent development. It connects to Azure OpenAI for medical guideline consultations.

## Project Structure
- `app/` - Application core code (Controllers, Models, Services)
  - `app/Agents/` - AI agents (VascularExpertAgent)
  - `app/Tools/` - Agent tools (ConsultGuidelineTool)
  - `app/Providers/LLM/` - Custom LLM providers (AzureOpenAIProvider)
- `bootstrap/` - Framework bootstrap files
- `config/` - Configuration files
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
- 2026-01-09: Fixed Azure OpenAI connection by explicitly setting provider in VascularExpertAgent
- Implemented custom AzureOpenAIProvider with handlers for text, structured, and streaming responses
- Registered Azure provider extension via AzureOpenAIServiceProvider
