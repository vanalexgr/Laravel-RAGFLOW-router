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

    // If a chunk does not explicitly reference "Figure X", allow a weak fallback
    // keyword match against captions/keywords (still scoped to selected guidelines).
    'enable_keyword_fallback' => (bool) env('GUIDELINE_ASSET_KEYWORD_FALLBACK', true),

    // Optional diagnostics for fallback asset ranking.
    'log_scoring' => (bool) env('GUIDELINE_ASSET_LOG_SCORING', false),

    // Tightness controls for fallback relevance. If at least one candidate has
    // a strong query match, weaker context-only assets are filtered out.
    'min_query_signal' => (float) env('GUIDELINE_ASSET_MIN_QUERY_SIGNAL', 2.0),
    'min_query_signal_ratio' => (float) env('GUIDELINE_ASSET_MIN_QUERY_SIGNAL_RATIO', 0.45),
];
