<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Abbreviation Expansion
    |--------------------------------------------------------------------------
    |
    | Enable/disable abbreviation expansion before semantic routing.
    |
    */
    'enabled' => env('ABBREVIATION_EXPANSION_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Guardrails
    |--------------------------------------------------------------------------
    |
    | Post-routing rules to correct/enhance semantic router results.
    |
    */
    'guardrails_enabled' => env('ROUTER_GUARDRAILS_ENABLED', true),
    'guardrails_debug' => env('ROUTER_GUARDRAILS_DEBUG', true),

    /*
    |--------------------------------------------------------------------------
    | Expansion Settings
    |--------------------------------------------------------------------------
    */
    'max_acronyms' => env('ABBREVIATION_MAX_ACRONYMS', 8),
    'expansion_format' => env('ABBREVIATION_EXPANSION_FORMAT', 'append'), // append|inline|dual

    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    */
    'cache_ttl' => env('ABBREVIATION_CACHE_TTL', 3600),
    'preload_on_boot' => env('ABBREVIATION_PRELOAD_ON_BOOT', false),

    /*
    |--------------------------------------------------------------------------
    | Acronym Detection Patterns
    |--------------------------------------------------------------------------
    |
    | Regex patterns for detecting medical acronyms in queries.
    |
    */
    'detection_patterns' => [
        '\b[A-Z][A-Z0-9\/\-\.]{1,12}\b',        // Standard: TEVAR, FDG-PET/CT
        '\b[A-Z][a-z]{1,2}[A-Z]+[a-z]*\b',      // Mixed: AEsf, AEnF, EuREC
        '\b\d{1,2}[A-Z][\-\/]?[A-Z\-]+\b',     // Number prefix: 18F-FDG
        '\b[A-Z]+\d+\b',                        // Letter+number: CD34
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Paths
    |--------------------------------------------------------------------------
    */
    'paths' => [
        'raw' => storage_path('app/guidelines/abbr/raw'),
        'normalized' => storage_path('app/guidelines/abbr/normalized'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Guardrail Rules
    |--------------------------------------------------------------------------
    */
    'guardrails' => [
        'infection_trigger' => [
            'enabled' => true,
            'priority' => 1,
            'target_guideline' => 'vascular_graft_infections',
            'action' => 'prefer',  // 'prefer', 'force_add', 'require'
            'min_score_threshold' => 0.3,

            'keywords' => [
                'infection_markers' => [
                    'fever',
                    'febrile',
                    'elevated CRP',
                    'CRP',
                    'raised CRP',
                    'WBC',
                    'white blood cell',
                    'leukocytosis',
                    'inflammatory markers',
                    'sepsis',
                    'septic',
                    'bacteremia',
                    'positive blood culture',
                ],
                'imaging_markers' => [
                    'perigraft fluid',
                    'peri-graft fluid',
                    'perigraft air',
                    'perigraft gas',
                    'abscess',
                    'collection',
                    'fluid collection',
                    'inflammation',
                ],
                'fistula_terms' => [
                    'AESF',
                    'AEsf',
                    'AEnF',
                    'APF',
                    'ABF',
                    'AUF',
                    'aorto-enteric',
                    'aorto-esophageal',
                    'aorto-oesophageal',
                    'aortobronchial',
                    'aortopulmonary',
                    'arterio-ureteral',
                    'fistula',
                ],
                'procedure_terms' => [
                    'explantation',
                    'graft removal',
                    'drainage',
                    'irrigation',
                    'debridement',
                    'mediastinitis',
                    'sternal wound',
                ],
                'pathogen_terms' => [
                    'MRSA',
                    'MSSA',
                    'staph',
                    'staphylococcus',
                    'strep',
                    'streptococcus',
                    'pseudomonas',
                    'candida',
                    'fungal',
                    'MDR',
                    'multidrug resistant',
                ],
                'infection_general' => [
                    'graft infection',
                    'endograft infection',
                    'VGEI',
                    'VGI',
                    'EGI',
                    'prosthetic infection',
                    'infected graft',
                ],
            ],

            'match_config' => [
                'min_keyword_matches' => 2,
                'case_insensitive' => true,
                'word_boundary' => true,
            ],
        ],
    ],

    'fallback_strategy' => 'keep_originals', // 'keep_originals', 'empty', 'force_vgei'
];
