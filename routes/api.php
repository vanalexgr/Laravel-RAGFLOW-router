<?php

use Illuminate\Support\Facades\Route;
use App\Http\Middleware\ValidateApiKey;

// CORS preflight — single catch-all outside auth middleware so browsers
// can complete OPTIONS handshakes without an API key.
Route::prefix('v1')->group(function () {
    Route::options('/{any}', function () {
        return response('', 200)
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'POST, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
    })->where('any', '.*');
});

// Authenticated API endpoints
Route::prefix('v1')->middleware([ValidateApiKey::class, 'throttle:60,1'])->group(function () {
    Route::post('/vascular-consult', [App\Http\Controllers\ToolController::class, 'consult']);
    Route::post('/pre-retrieval',    [App\Http\Controllers\ToolController::class, 'preRetrieve']);
    Route::post('/normalize',        [App\Http\Controllers\ToolController::class, 'normalize']);
    Route::post('/clinical-gate',    [App\Http\Controllers\ToolController::class, 'clinicalGate']);
});
