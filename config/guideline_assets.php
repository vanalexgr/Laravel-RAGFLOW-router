<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Guideline Figure/Table Assets
    |--------------------------------------------------------------------------
    |
    | Purpose:
    | - Some guideline figures (diagnostic/treatment algorithms, flow charts,
    |   complex tables) lose meaning when parsed into text chunks.
    | - We attach the original image/table screenshot for user viewing when the
    |   retrieved narrative text references it (e.g., "see Figure 3").
    |
    | How it works:
    | - A JSON manifest maps guideline keys to assets (figures/tables).
    | - Retrieval output is scanned for "Figure/Fig/Table/Algorithm" references
    |   and keyword overlap with captions to pick relevant assets.
    |
    */

    // Storage disk where assets live (typically "public" -> storage/app/public).
    'disk' => env('GUIDELINE_ASSET_DISK', 'public'),

    // JSON manifest (see resources/guideline_assets/manifest.example.json).
    'manifest_path' => env(
        'GUIDELINE_ASSET_MANIFEST',
        base_path('resources/guideline_assets/manifest.json')
    ),

    // Max assets to attach per response (keep small so UIs don't get spammy).
    'max_assets' => (int) env('GUIDELINE_ASSET_MAX', 3),

    // If a chunk does not explicitly reference "Figure X", allow a local BM25-style
    // fallback rank over captions/keywords within the selected/evidenced guidelines.
    'enable_keyword_fallback' => (bool) env('GUIDELINE_ASSET_KEYWORD_FALLBACK', true),

    // Optional diagnostics for fallback asset ranking.
    'log_scoring' => (bool) env('GUIDELINE_ASSET_LOG_SCORING', false),

    // Tightness controls for fallback relevance. If at least one candidate has
    // a strong query match, weaker context-only assets are filtered out.
    'min_query_signal' => (float) env('GUIDELINE_ASSET_MIN_QUERY_SIGNAL', 2.0),
    'min_query_signal_ratio' => (float) env('GUIDELINE_ASSET_MIN_QUERY_SIGNAL_RATIO', 0.45),

    // Optional model-based rerank over the heuristic shortlist. This is applied
    // only after scope and lexical filtering, so explicit figure/table matches
    // still win deterministically and the reranker only chooses among plausible
    // candidates.
    'rerank' => [
        'enabled' => filter_var(env('GUIDELINE_ASSET_RERANK_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'provider' => env('GUIDELINE_ASSET_RERANK_PROVIDER', env('BRIDGE_RERANK_PROVIDER', 'cohere')),
        'endpoint' => env('GUIDELINE_ASSET_RERANK_ENDPOINT', env('BRIDGE_RERANK_ENDPOINT', 'https://api.cohere.com/v1/rerank')),
        'api_key' => env('GUIDELINE_ASSET_RERANK_API_KEY', env('BRIDGE_RERANK_API_KEY')),
        'model' => env('GUIDELINE_ASSET_RERANK_MODEL', env('BRIDGE_RERANK_MODEL', 'rerank-english-v3.0')),
        'timeout' => (int) env('GUIDELINE_ASSET_RERANK_TIMEOUT', env('BRIDGE_RERANK_TIMEOUT', 20)),
        'candidate_pool' => (int) env('GUIDELINE_ASSET_RERANK_CANDIDATE_POOL', 12),
    ],
];
