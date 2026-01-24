<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Abbreviation Expansion
    |--------------------------------------------------------------------------
    */
    'enabled' => env('ABBREVIATION_EXPANSION_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Guardrails
    |--------------------------------------------------------------------------
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
    */
    'detection_patterns' => [
        '\\b[A-Z][A-Z0-9\\/\\-\\.]{1,12}\\b',        // Standard: TEVAR, FDG-PET/CT
        '\\b[A-Z][a-z]{1,2}[A-Z]+[a-z]*\\b',      // Mixed: AEsf, AEnF, EuREC
        '\\b\\d{1,2}[A-Z][\\-\\/]?[A-Z\\-]+\\b',     // Number prefix: 18F-FDG
        '\\b[A-Z]+\\d+\\b',                        // Letter+number: CD34
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
    | Action Types & Priority
    |--------------------------------------------------------------------------
    */
    'action_types' => [
        'pin',              // Must be #1 if triggered
        'force_include',    // Add to candidates
        'exclude',          // Remove from candidates  
        'companion'         // Add but never #1
    ],

    // Global priority order for all 14 guidelines
    'priority_order' => [
        'vascular_trauma',          // 1 - Highest (but opt-in only)
        'graft_infections',         // 2
        'acute_limb_ischaemia',     // 3
        'antithrombotic_therapy',   // 4 (companion)
        'abdominal_aortic_aneurysm', // 5
        // Remaining 9 to be added later
    ],

    // Keep top-2 if score gap is smaller than this
    'score_gap_threshold' => 0.08,  // 8%

    /*
    |--------------------------------------------------------------------------
    | Guardrail Rules (5 Critical)
    |--------------------------------------------------------------------------
    */
    'guardrails' => [

        // 1. VASCULAR TRAUMA (STRICT OPT-IN)
        'vascular_trauma' => [
            'enabled' => true,
            'target_guideline' => 'vascular_trauma',
            'action' => 'exclude_by_default',  // Only include if explicit trigger

            'pin_keywords' => [
                'trauma_mechanism' => [
                    'trauma',
                    'injury',
                    'blunt',
                    'penetrating',
                    'stab',
                    'gunshot',
                    'GSW',
                    'MVC',
                    'MVA',
                    'fall from height',
                    'assault',
                    'blast',
                    'shrapnel'
                ],
                'damage_control' => [
                    'shunt',
                    'tourniquet',
                    'packing',
                    'damage control'
                ],
                'iatrogenic' => [
                    'catheter perforation',
                    'wire injury',
                    'access pseudoaneurysm'
                ]
            ],

            'collision_rules' => [
                ['detect' => ['trauma_mechanism', 'acute limb'], 'add' => 'acute_limb_ischaemia']
            ],

            'match_config' => [
                'min_keyword_matches' => 1,
                'case_insensitive' => true,
                'word_boundary' => true
            ]
        ],

        // 2. GRAFT INFECTIONS (Enhanced)
        'graft_infections' => [
            'enabled' => true,
            'target_guideline' => 'vascular_graft_infections',
            'action' => 'pin',

            'pin_keywords' => [
                'infection_markers' => [
                    'graft infection',
                    'endograft infection',
                    'VGEI',
                    'fever',
                    'febrile',
                    'CRP',
                    'elevated CRP',
                    'raised CRP',
                    'WBC',
                    'leukocytosis',
                    'sepsis',
                    'bacteremia'
                ],
                'imaging' => [
                    'peri-graft fluid',
                    'perigraft air',
                    'perigraft gas',
                    'abscess',
                    'fluid collection',
                    'mediastinitis'
                ],
                'fistula' => [
                    'AEsf',
                    'AEnF',
                    'ABF',
                    'APF',
                    'AUF',
                    'aorto-enteric',
                    'aorto-esophageal',
                    'aorto-oesophageal',
                    'aortobronchial',
                    'fistula after TEVAR',
                    'fistula after EVAR'
                ],
                'intervention' => [
                    'explantation',
                    'graft removal',
                    'drainage',
                    'irrigation',
                    'lifelong antibiotics'
                ]
            ],

            'collision_rules' => [
                ['detect' => ['TEVAR', 'fever'], 'add' => 'descending_thoracic_aorta'],
                ['detect' => ['EVAR', 'fever'], 'add' => 'abdominal_aortic_aneurysm']
            ],

            'match_config' => [
                'min_keyword_matches' => 2,
                'case_insensitive' => true,
                'word_boundary' => true
            ]
        ],

        // 3. ACUTE LIMB ISCHAEMIA
        'acute_limb_ischaemia' => [
            'enabled' => true,
            'target_guideline' => 'acute_limb_ischaemia',
            'action' => 'pin',

            'pin_keywords' => [
                'acute_presentation' => [
                    'acute limb ischemia',
                    'acute limb ischaemia',
                    'ALI',
                    'sudden limb pain',
                    'cold limb',
                    'pulseless limb',
                    'pallor',
                    'paralysis',
                    'paresthesia'
                ],
                'classification' => [
                    'Rutherford I',
                    'Rutherford IIa',
                    'Rutherford IIb',
                    'Rutherford III',
                    'threatened limb',
                    'viable limb',
                    'irreversible ischemia'
                ],
                'etiology' => [
                    'embolus',
                    'embolism',
                    'acute thrombosis',
                    'acute graft occlusion',
                    'bypass occlusion'
                ],
                'intervention' => [
                    'heparin now',
                    'urgent revascularization',
                    'compartment syndrome',
                    'fasciotomy'
                ]
            ],

            'exclude_keywords' => [
                'CLTI',
                'rest pain for months',
                'chronic ulcer',
                'longstanding claudication'
            ],

            'match_config' => [
                'min_keyword_matches' => 2,
                'case_insensitive' => true,
                'word_boundary' => true
            ]
        ],

        // 4. ANTITHROMBOTIC THERAPY (COMPANION)
        'antithrombotic_therapy' => [
            'enabled' => true,
            'target_guideline' => 'antithrombotic_therapy',
            'action' => 'companion',  // Never #1 unless meds-only

            'companion_keywords' => [
                'antiplatelet' => [
                    'aspirin',
                    'clopidogrel',
                    'DAPT',
                    'dual antiplatelet',
                    'ticagrelor',
                    'prasugrel'
                ],
                'anticoagulant' => [
                    'warfarin',
                    'DOAC',
                    'rivaroxaban',
                    'apixaban',
                    'edoxaban',
                    'dabigatran',
                    'heparin',
                    'LMWH'
                ],
                'bleeding' => [
                    'bleeding',
                    'bruising',
                    'GI bleed',
                    'hemorrhage',
                    'PPI',
                    'proton pump inhibitor'
                ],
                'risk_scores' => [
                    'HAS-BLED',
                    'CHADS',
                    'INR',
                    'perioperative antithrombotic'
                ]
            ],

            'pin_keywords' => [
                'what antithrombotic',
                'which antiplatelet',
                'anticoagulation regimen',
                'medication policy'
            ],

            'match_config' => [
                'min_keyword_matches' => 1,
                'case_insensitive' => true,
                'word_boundary' => true
            ]
        ],

        // 5. ABDOMINAL AORTIC ANEURYSM (Territory Protection)
        'abdominal_aortic_aneurysm' => [
            'enabled' => true,
            'target_guideline' => 'abdominal_aortic_aneurysm',
            'action' => 'pin',

            'pin_keywords' => [
                'anatomy' => [
                    'AAA',
                    'abdominal aortic aneurysm',
                    'infrarenal aneurysm',
                    'iliac aneurysm',
                    'aortoiliac aneurysm'
                ],
                'intervention' => [
                    'EVAR',
                    'endovascular aneurysm repair',
                    'open AAA repair',
                    'iliac branch device',
                    'hypogastric preservation'
                ],
                'surveillance' => [
                    'sac expansion',
                    'sac shrinkage',
                    'endoleak type I',
                    'endoleak type II',
                    'endoleak type III',
                    'type II embolization'
                ],
                'rupture' => [
                    'rAAA',
                    'ruptured AAA',
                    'rupture of AAA',
                    'hemodynamic instability'
                ]
            ],

            'exclude_keywords' => [
                'type A dissection',
                'type B dissection',
                'aortic arch',
                'TEVAR',
                'descending thoracic'
            ],

            'collision_rules' => [
                ['detect' => ['EVAR', 'fever'], 'add' => 'graft_infections']
            ],

            'match_config' => [
                'min_keyword_matches' => 1,
                'case_insensitive' => true,
                'word_boundary' => true
            ]
        ],
    ],

    'fallback_strategy' => 'keep_originals',
];
