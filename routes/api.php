<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\OpenAICompatibleController;

Route::prefix('v1')->group(function () {
    Route::get('/models', [OpenAICompatibleController::class, 'listModels']);
    Route::get('/models/{model}', [OpenAICompatibleController::class, 'getModel']);
    Route::post('/chat/completions', [OpenAICompatibleController::class, 'chatCompletions']);
});
