<?php

return [
    'api_key' => env('RAGFLOW_API_KEY'),
    'api_endpoint' => env('RAGFLOW_ENDPOINT', 'http://localhost/api/v1'),
    'request_timeout' => env('RAGFLOW_REQUEST_TIMEOUT', 30),

    'retrieval' => [
        'top_k' => (int) env('RAGFLOW_TOP_K', 10),
        'similarity_threshold' => (float) env('RAGFLOW_SIMILARITY_THRESHOLD', 0.2),
        'keyword_mode' => filter_var(env('RAGFLOW_KEYWORD_MODE', true), FILTER_VALIDATE_BOOLEAN),
        'vector_similarity_weight' => (float) env('RAGFLOW_VECTOR_WEIGHT', 0.3),
    ],

    'datasets' => [
        'esvs_guidelines' => '4fff3622eb1b11f09021f2381272676b',
    ],
];
