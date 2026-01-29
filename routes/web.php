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


// 🤖 MCP SERVER (SSE)
\Laravel\Mcp\Facades\Mcp::web('vascular', \App\Mcp\VascularServer::class);