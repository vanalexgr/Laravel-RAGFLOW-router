<?php

return [
    'api_key' => env('RAGFLOW_API_KEY'),
    'api_endpoint' => env('RAGFLOW_ENDPOINT', 'http://localhost/api/v1'),
    'request_timeout' => env('RAGFLOW_REQUEST_TIMEOUT', 30),

    'use_bridge' => filter_var(env('RAGFLOW_USE_BRIDGE', false), FILTER_VALIDATE_BOOLEAN),
    'bridge_url' => env('RAGFLOW_BRIDGE_URL', 'http://localhost:8000'),
    'bridge_secret' => env('RAGFLOW_BRIDGE_SECRET'),

    'retrieval' => [
        'top_k' => (int) env('RAGFLOW_TOP_K', 256),  // Lowered from 1024 - reranker handles filtering
        'size' => (int) env('RAGFLOW_SIZE', 10),
        'page' => (int) env('RAGFLOW_PAGE', 1),
        'similarity_threshold' => (float) env('RAGFLOW_SIMILARITY_THRESHOLD', 0.2),
        // FORCE PURE SEMANTIC SEARCH FOR DEBUGGING
        'keyword_mode' => false, // Disable keyword matching
        'vector_similarity_weight' => 0.9, // Force 90% semantic weight
        'rerank_id' => null, // Disable reranker to avoid potential ID mismatch issues
        'use_kg' => filter_var(env('RAGFLOW_USE_KG', true), FILTER_VALIDATE_BOOLEAN),
        'highlight' => filter_var(env('RAGFLOW_HIGHLIGHT', true), FILTER_VALIDATE_BOOLEAN),
    ],

    // Dataset registry is defined in config/guidelines.php
];
