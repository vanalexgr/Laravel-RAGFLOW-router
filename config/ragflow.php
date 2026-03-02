<?php

return [
    'api_key' => env('RAGFLOW_API_KEY'),
    'api_endpoint' => env('RAGFLOW_ENDPOINT', 'http://localhost/api/v1'),
    'request_timeout' => env('RAGFLOW_REQUEST_TIMEOUT', 30),

    'use_bridge' => filter_var(env('RAGFLOW_USE_BRIDGE', false), FILTER_VALIDATE_BOOLEAN),
    'bridge_url' => env('RAGFLOW_BRIDGE_URL', 'http://localhost:8000'),
    'bridge_secret' => env('RAGFLOW_BRIDGE_SECRET'),

    'retrieval' => [
        'top_k' => (int) env('RAGFLOW_TOP_K', 40),  // Keep candidate pool tight for bridge rerank
        'size' => (int) env('RAGFLOW_SIZE', 10),
        'page' => (int) env('RAGFLOW_PAGE', 1),
        // Max chunks returned per branch (narrative/citation) after any reranking.
        'narrative_max' => (int) env('RAGFLOW_NARRATIVE_MAX', 10),
        'citation_max' => (int) env('RAGFLOW_CITATION_MAX', 4),
        'similarity_threshold' => (float) env('RAGFLOW_SIMILARITY_THRESHOLD', 0.2),
        'keyword_mode' => filter_var(env('RAGFLOW_KEYWORD_MODE', true), FILTER_VALIDATE_BOOLEAN),
        'vector_similarity_weight' => (float) env('RAGFLOW_VECTOR_WEIGHT', 0.5),
        // Must match an authorized rerank model name in RAGFlow tenant settings.
        'rerank_id' => env('RAGFLOW_RERANK_ID', 'Cohere-rerank-v4.0-pro___OpenAI-API'),
        'use_kg' => filter_var(env('RAGFLOW_USE_KG', false), FILTER_VALIDATE_BOOLEAN), // Server KG is broken
        'citation_top_k' => (int) env('RAGFLOW_CITATION_TOP_K', 10),
        // Highlighting bloats chunk payloads and can degrade downstream prompt quality.
        'highlight' => filter_var(env('RAGFLOW_HIGHLIGHT', false), FILTER_VALIDATE_BOOLEAN),
        // Narrative excerpts are trimmed around query matches to keep prompts compact.
        'narrative_excerpt_max_chars' => (int) env('RAGFLOW_NARRATIVE_EXCERPT_MAX_CHARS', 1000),
        // Lightweight phrase boosts for edge-case recall (kept short to avoid keyword stuffing).
        'query_boosts' => [
            'enabled' => filter_var(env('RAGFLOW_QUERY_BOOSTS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
            'non_a_non_b_enabled' => filter_var(env('RAGFLOW_NON_A_NON_B_BOOST_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
            'blue_toe_enabled' => filter_var(env('RAGFLOW_BLUE_TOE_BOOST_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        ],
        // Focused recall for hard-to-retrieve edge cases (e.g., non-A non-B dissection).
        'focused_recall' => [
            'enabled' => filter_var(env('RAGFLOW_FOCUSED_RECALL_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
            'non_a_non_b_enabled' => filter_var(env('RAGFLOW_NON_A_NON_B_RECALL_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
            'similarity_threshold' => (float) env('RAGFLOW_NON_A_NON_B_SIMILARITY_THRESHOLD', 0.18),
            'top_k' => (int) env('RAGFLOW_NON_A_NON_B_TOP_K', 120),
            'narrative_max' => (int) env('RAGFLOW_NON_A_NON_B_NARRATIVE_MAX', 40),
            'citation_max' => (int) env('RAGFLOW_NON_A_NON_B_CITATION_MAX', 30),
            // Optional overrides for hybrid retrieval during focused recall.
            'keyword_mode' => filter_var(env('RAGFLOW_NON_A_NON_B_KEYWORD_MODE', false), FILTER_VALIDATE_BOOLEAN),
            'vector_similarity_weight' => (float) env('RAGFLOW_NON_A_NON_B_VECTOR_WEIGHT', 0.5),
        ],
        // Optional high-recall pass to match RAGFlow UI settings (hybrid, larger top-k).
        'quality_pass' => [
            'enabled' => filter_var(env('RAGFLOW_QUALITY_PASS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'min_narrative' => (int) env('RAGFLOW_QUALITY_PASS_MIN_NARRATIVE', 0),
            'min_citation' => (int) env('RAGFLOW_QUALITY_PASS_MIN_CITATION', 0),
            'similarity_threshold' => (float) env('RAGFLOW_QUALITY_PASS_SIMILARITY_THRESHOLD', 0.2),
            'top_k' => (int) env('RAGFLOW_QUALITY_PASS_TOP_K', 1024),
            'keyword_mode' => filter_var(env('RAGFLOW_QUALITY_PASS_KEYWORD_MODE', true), FILTER_VALIDATE_BOOLEAN),
            'vector_similarity_weight' => (float) env('RAGFLOW_QUALITY_PASS_VECTOR_WEIGHT', 0.2),
            'narrative_max' => (int) env('RAGFLOW_QUALITY_PASS_NARRATIVE_MAX', 80),
            'citation_max' => (int) env('RAGFLOW_QUALITY_PASS_CITATION_MAX', 80),
            // Optional: trigger a high-recall pass when GraphRAG concept gaps remain.
            'trigger_on_concept_gap' => filter_var(env('RAGFLOW_QUALITY_PASS_ON_CONCEPT_GAP', false), FILTER_VALIDATE_BOOLEAN),
            'gap_similarity_threshold' => (float) env('RAGFLOW_QUALITY_PASS_GAP_SIMILARITY_THRESHOLD', 0.2),
            'gap_top_k' => (int) env('RAGFLOW_QUALITY_PASS_GAP_TOP_K', 256),
            'gap_keyword_mode' => filter_var(env('RAGFLOW_QUALITY_PASS_GAP_KEYWORD_MODE', false), FILTER_VALIDATE_BOOLEAN),
            'gap_vector_similarity_weight' => (float) env('RAGFLOW_QUALITY_PASS_GAP_VECTOR_WEIGHT', 0.5),
        ],
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
