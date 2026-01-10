<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\OpenAICompatibleController;
use App\Http\Middleware\ValidateApiKey;

Route::prefix('v1')->middleware(ValidateApiKey::class)->group(function () {
    Route::get('/models', [OpenAICompatibleController::class, 'listModels']);
    Route::get('/models/{model}', [OpenAICompatibleController::class, 'getModel']);
    Route::post('/chat/completions', [OpenAICompatibleController::class, 'chatCompletions']);
    Route::post('/chat/completions/stream', [OpenAICompatibleController::class, 'chatCompletionsWithProgress']);
});
