<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\OpenAICompatibleController;
use App\Http\Middleware\ValidateApiKey;

// Public health endpoint (no authentication required for monitoring)
Route::prefix('v1')->group(function () {
    Route::get('/health/retrieval', [OpenAICompatibleController::class, 'healthRetrieval']);
});

// Protected API endpoints
Route::prefix('v1')->middleware(ValidateApiKey::class)->group(function () {
    Route::get('/models', [OpenAICompatibleController::class, 'listModels']);
    Route::get('/models/{model}', [OpenAICompatibleController::class, 'getModel']);
    Route::post('/chat/completions', [OpenAICompatibleController::class, 'chatCompletions']);
    Route::post('/chat/completions/stream', [OpenAICompatibleController::class, 'chatCompletionsWithProgress']);
    Route::post('/retrieve', [OpenAICompatibleController::class, 'retrieve']);

    // Context Management
// 🤖 OpenAPI Tool Endpoint (Outside the auth group or inside? User wants it for OpenWebUI)
// If OpenWebUI tool definition can send API Key, good. If not, maybe keep it outside or make auth optional?
// For now, let's keep it inside the v1 prefix but maybe we need to be careful about Auth. 
// "OpenAPI compatible mcp" usually implies using the standard remote tool auth or none.
// Let's close the previous group first.

    Route::post('/vascular-consult', [App\Http\Controllers\ToolController::class, 'consult']);

    // CORS preflight
    Route::options('/vascular-consult', function () {
        return response('', 200)
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'POST, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
    });
});
