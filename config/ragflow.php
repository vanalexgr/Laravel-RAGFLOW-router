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
        'keyword_mode' => filter_var(env('RAGFLOW_KEYWORD_MODE', true), FILTER_VALIDATE_BOOLEAN),
        'vector_similarity_weight' => (float) env('RAGFLOW_VECTOR_WEIGHT', 0.3),
        // Must match an authorized rerank model name in RAGFlow tenant settings.
        'rerank_id' => env('RAGFLOW_RERANK_ID', 'Cohere-rerank-v4.0-pro___OpenAI-API'),
        'use_kg' => filter_var(env('RAGFLOW_USE_KG', false), FILTER_VALIDATE_BOOLEAN), // Server KG is broken
        'citation_top_k' => (int) env('RAGFLOW_CITATION_TOP_K', 10),
        // Highlighting bloats chunk payloads and can degrade downstream prompt quality.
        'highlight' => filter_var(env('RAGFLOW_HIGHLIGHT', false), FILTER_VALIDATE_BOOLEAN),
    ],

    // Optional bridge-side reranking (Laravel) to avoid RAGFlow rerank latency.
    // When enabled, the bridge will rerank locally and *not* send rerank_id to RAGFlow.
    'bridge_rerank' => [
        'enabled' => filter_var(env('BRIDGE_RERANK_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'provider' => env('BRIDGE_RERANK_PROVIDER', 'cohere'),
        'endpoint' => env('BRIDGE_RERANK_ENDPOINT', 'https://api.cohere.com/v1/rerank'),
        'api_key' => env('BRIDGE_RERANK_API_KEY'),
        'model' => env('BRIDGE_RERANK_MODEL', 'rerank-english-v3.0'),
        'top_n' => (int) env('BRIDGE_RERANK_TOP_N', 20),
        'timeout' => (int) env('BRIDGE_RERANK_TIMEOUT', 20),
    ],

    // Dataset registry is defined in config/guidelines.php
];
