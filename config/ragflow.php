<?php

return [
    'api_key' => env('RAGFLOW_API_KEY'),
    'api_endpoint' => env('RAGFLOW_ENDPOINT', 'http://localhost/api/v1'),
    'request_timeout' => env('RAGFLOW_REQUEST_TIMEOUT', 30),

    'use_bridge' => filter_var(env('RAGFLOW_USE_BRIDGE', false), FILTER_VALIDATE_BOOLEAN),
    'bridge_url' => env('RAGFLOW_BRIDGE_URL', 'http://localhost:8000'),
    'bridge_secret' => env('RAGFLOW_BRIDGE_SECRET'),

    // Routing method: 'semantic' (ultra-fast, ~10ms) or 'llm' (slower, ~2-3s but more nuanced)
    // Semantic routing uses local FastEmbed embeddings, LLM routing uses Azure OpenAI
    // Both can be combined via 'semantic_with_llm_fallback' which uses semantic first, falls back to LLM on failure
    'routing_method' => env('RAGFLOW_ROUTING_METHOD', 'semantic'),

    'retrieval' => [
        'top_k' => (int) env('RAGFLOW_TOP_K', 256),  // Lowered from 1024 - reranker handles filtering
        'size' => (int) env('RAGFLOW_SIZE', 10),
        'page' => (int) env('RAGFLOW_PAGE', 1),
        'similarity_threshold' => (float) env('RAGFLOW_SIMILARITY_THRESHOLD', 0.2),
        'keyword_mode' => filter_var(env('RAGFLOW_KEYWORD_MODE', true), FILTER_VALIDATE_BOOLEAN),
        'vector_similarity_weight' => (float) env('RAGFLOW_VECTOR_WEIGHT', 0.3),
        'rerank_id' => env('RAGFLOW_RERANK_ID', 'Cohere-rerank-v4.0-pro___OpenAI-API@OpenAI-API-Compatible'),
        'use_kg' => filter_var(env('RAGFLOW_USE_KG', true), FILTER_VALIDATE_BOOLEAN),
        'highlight' => filter_var(env('RAGFLOW_HIGHLIGHT', true), FILTER_VALIDATE_BOOLEAN),
    ],

    'datasets' => [
        'esvs_trauma_2025' => '8f58aeadec9411f0a38066bc68590b9b',
        'esvs_trauma_recs' => '4fff3622eb1b11f09021f2381272676b',
        'default' => ['8f58aeadec9411f0a38066bc68590b9b', '4fff3622eb1b11f09021f2381272676b'],
    ],
];
