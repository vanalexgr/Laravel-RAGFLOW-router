<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// 🛠️ DEBUG ROUTE
Route::get('/debug-config', function () {
    // ... (debug output)
    return [
        'status' => 'Diagnostic Report',
        // ...
    ];
});

Route::get('/debug-test', function () {
    return 'DEBUG OK';
});


// 🤖 MCP SERVER (SSE)
// 🤖 MCP SERVER (SSE) - Manual Controller
use App\Http\Controllers\McpController;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;

// \Laravel\Mcp\Facades\Mcp::web('vascular', \App\Mcp\VascularServer::class);

Route::match(['GET', 'HEAD'], '/vascular', [McpController::class, 'stream'])
    ->withoutMiddleware([StartSession::class, VerifyCsrfToken::class]);

Route::post('/vascular', [McpController::class, 'message'])
    ->withoutMiddleware([StartSession::class, VerifyCsrfToken::class]);
// Route::post('/vascular', function () {
//     return response()->json(['status' => 'MANUAL DEBUG HIT']);
// });