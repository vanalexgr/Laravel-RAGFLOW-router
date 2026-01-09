<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// 🛠️ DEBUG ROUTE
Route::get('/debug-config', function () {
    // 1. Check if the environment variable exists
    $envUrl = env('AZURE_OPENAI_BASE_URL');

    // 2. Check what Vizra loaded from config
    $configUrl = config('vizra-adk.providers.openai.base_url');

    // 3. Check the header injection
    $headers = config('vizra-adk.providers.openai.client_options.headers');

    return [
        'status' => 'Diagnostic Report',
        'raw_env_url' => $envUrl ?? '⚠️ NULL (Replit Secret not loaded)',
        'vizra_config_url' => $configUrl ?? '⚠️ NULL (Config failed)',
        'has_api_key' => !empty(env('AZURE_OPENAI_API_KEY')) ? '✅ YES' : '❌ NO',
        'azure_header_present' => isset($headers['api-key']) ? '✅ YES' : '❌ NO',
    ];
});