<?php

return [
    'enabled' => filter_var(env('TAXONOMY_EXPANSION_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    // Path to the ESVS taxonomy CSV (terms + tags).
    'path' => env('TAXONOMY_EXPANSION_PATH', base_path('resources/taxonomy/esvs_master_tags_fixed.csv')),
    // Limit the number of tags expanded per query.
    'max_tags' => (int) env('TAXONOMY_EXPANSION_MAX_TAGS', 3),
    // Total terms added across all matched tags.
    'max_terms' => (int) env('TAXONOMY_EXPANSION_MAX_TERMS', 8),
    // Max terms added per matched tag.
    'max_terms_per_tag' => (int) env('TAXONOMY_EXPANSION_MAX_TERMS_PER_TAG', 4),
    // Ignore very short or single-token terms to reduce noise.
    'min_term_len' => (int) env('TAXONOMY_EXPANSION_MIN_TERM_LEN', 5),
    'min_words' => (int) env('TAXONOMY_EXPANSION_MIN_WORDS', 2),
    // Skip tags that are mostly boilerplate or metadata.
    'excluded_tags' => [
        'meta.misc',
        'meta.guideline.recommendation',
        'meta.guideline.document',
    ],
    // Common stop terms to avoid expanding with generic words.
    'stop_terms' => [
        'guideline',
        'recommendation',
        'recommendations',
        'class',
        'level',
        'table',
        'figure',
        'section',
        'review',
        'study',
        'studies',
        'trial',
        'trials',
    ],
];
