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
// \Laravel\Mcp\Facades\Mcp::web('vascular', \App\Mcp\VascularServer::class);
Route::post('/vascular', function () {
    return response()->json(['status' => 'MANUAL DEBUG HIT']);
});