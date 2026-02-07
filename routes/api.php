<?php

use Illuminate\Support\Facades\Route;
use App\Http\Middleware\ValidateApiKey;

// Tool API endpoint used by OpenWebUI tool integration
Route::prefix('v1')->middleware(ValidateApiKey::class)->group(function () {
    Route::post('/vascular-consult', [App\Http\Controllers\ToolController::class, 'consult']);

    // CORS preflight
    Route::options('/vascular-consult', function () {
        return response('', 200)
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'POST, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
    });
});
