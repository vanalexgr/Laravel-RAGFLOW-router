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
    Route::post('/context/scope', [OpenAICompatibleController::class, 'setScope']);
    Route::get('/context/scope', [OpenAICompatibleController::class, 'getScope']);
});
