<?php

return [
    'api_key' => env('RAGFLOW_API_KEY'),
    'api_endpoint' => env('RAGFLOW_ENDPOINT', 'http://localhost/api/v1'),
    'request_timeout' => env('RAGFLOW_REQUEST_TIMEOUT', 30),

    'use_bridge' => filter_var(env('RAGFLOW_USE_BRIDGE', false), FILTER_VALIDATE_BOOLEAN),
    'bridge_url' => env('RAGFLOW_BRIDGE_URL', 'http://localhost:8000'),
    'bridge_secret' => env('RAGFLOW_BRIDGE_SECRET'),

    'retrieval' => [
        'top_k' => (int) env('RAGFLOW_TOP_K', 20),
        'top_n' => (int) env('RAGFLOW_TOP_N', 6),
        'similarity_threshold' => (float) env('RAGFLOW_SIMILARITY_THRESHOLD', 0.2),
        'keyword_mode' => filter_var(env('RAGFLOW_KEYWORD_MODE', true), FILTER_VALIDATE_BOOLEAN),
        'vector_similarity_weight' => (float) env('RAGFLOW_VECTOR_WEIGHT', 0.3),
        'rerank_model' => env('RAGFLOW_RERANK_MODEL', 'Cohere-rerank-v3-5-rdrns'),
        'use_knowledge_graph' => filter_var(env('RAGFLOW_USE_KNOWLEDGE_GRAPH', true), FILTER_VALIDATE_BOOLEAN),
        'use_toc' => filter_var(env('RAGFLOW_USE_TOC', true), FILTER_VALIDATE_BOOLEAN),
    ],

    'datasets' => [
        'esvs_guidelines' => '4fff3622eb1b11f09021f2381272676b',
    ],
];
