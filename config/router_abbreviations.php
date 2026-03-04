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
        'vascular_trauma',           // 1 - Emergencies
        'graft_infections',          // 2 - Complications
        'acute_limb_ischaemia',      // 3 - Acute limb
        'aortic_arch',               // 4 - High-risk anatomy (Boosted)
        'venous_thrombosis',         // 5 - Acute VTE
        'clti',                      // 6 - Chronic critical limb
        'carotid_vertebral',         // 7 - Cerebrovascular
        'mesenteric_renal',          // 8 - Visceral
        'abdominal_aortic_aneurysm', // 9 - Aortic (AAA)
        'descending_thoracic',       // 10 - Aortic (thoracic)
        'asymptomatic_pad',          // 11 - Chronic PAD
        'chronic_venous_disease',    // 12 - Chronic venous
        'vascular_access',           // 13 - Dialysis access
        'antithrombotic_therapy',    // 14 - Companion (medications)
    ],

    // Keep top-2 only if score gap is smaller than this (tighter = fewer companions)
    'score_gap_threshold' => 0.05,  // 5% (was 8%)

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
                    'traumatic',  // New
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
                    'access pseudoaneurysm',
                    'pseudoaneurysm' // New (generic)
                ]

            ],

            'exclude_keywords' => [
                'claudication',
                'PAD',
                'chronic',
                'atherosclerosis',
                'atherosclerotic',
                'diabetes',
                'diabetic',
                'degenerative'
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
                    'bacteremia',
                    'mycotic', // New
                    'EAR', // New
                    'ISR',  // New
                    'mycotic aneurysm', // Fixed: require full phrase, not just 'aneurysm'
                    'infected aneurysm' // Also infected aneurysms
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

            'exclude_keywords' => [
                'subclavian',  // Prevent expansion overlap
                'carotid'      // Prevent overlap
            ],

            'match_config' => [
                'min_keyword_matches' => 1,
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
                'claudication',
                'intermittent claudication',
                'rest pain for months',
                'chronic ulcer',
                'longstanding claudication',
                'chronic venous insufficiency'
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
                    'juxtarenal', // New
                    'juxtarenal aneurysm', // New
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
                'descending thoracic',
                'graft infection',
                'fistula',
                'mycotic',
                'arch'
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

        // 6. CAROTID & VERTEBRAL DISEASE
        'carotid_vertebral' => [
            'enabled' => true,
            'target_guideline' => 'carotid_vertebral',
            'action' => 'pin',

            'pin_keywords' => [
                'symptoms' => [
                    'TIA',
                    'transient ischem',
                    'amaurosis fugax',
                    'stroke',
                    'CVA',
                    'cerebrovascular accident'
                ],
                'anatomy' => [
                    'carotid stenosis',
                    'ICA stenosis',
                    'vertebral artery',
                    'posterior circulation'
                ],
                'procedure' => [
                    'CEA',
                    'carotid endarterectomy',
                    'CAS',
                    'carotid stenting'
                ],
                'grading' => [
                    'NASCET',
                    'symptomatic stenosis',
                    'asymptomatic stenosis'
                ]
            ],

            'exclude_keywords' => [
                'claudication',
                'intermittent claudication',
                'PAD',
                'peripheral artery disease',
                'limb ischemia',
                'leg pain',
                'foot',
                'CLTI'
            ],

            'collision_rules' => [
                ['detect' => ['stroke', 'aspirin'], 'add' => 'antithrombotic_therapy']
            ],

            'match_config' => [
                'min_keyword_matches' => 1,
                'case_insensitive' => true,
                'word_boundary' => true
            ]
        ],

        // 7. CLTI
        'clti' => [
            'enabled' => true,
            'target_guideline' => 'clti',
            'action' => 'pin',

            'pin_keywords' => [
                'presentation' => [
                    'CLTI',
                    'chronic limb-threatening',
                    'rest pain',
                    'ischemic rest pain',
                    'tissue loss',
                    'ulcer',
                    'gangrene'
                ],
                'classification' => [
                    'WIfI',
                    'Rutherford 4',
                    'Rutherford 5',
                    'Rutherford 6'
                ],
                'assessment' => [
                    'toe pressure',
                    'TcPO2',
                    'limb salvage'
                ],
                'intervention' => [
                    'amputation',
                    'infrainguinal bypass'
                ]
            ],

            'exclude_keywords' => [
                'asymptomatic PAD',
                // 'claudication', // Removed to allow progression cases
                // 'intermittent claudication', // Removed
                'varicose',
                'vein',
                'reflux'
            ],

            'match_config' => [
                'min_keyword_matches' => 1,
                'case_insensitive' => true,
                'word_boundary' => true
            ]
        ],

        // 8. VENOUS THROMBOSIS (DVT/PE)
        'venous_thrombosis' => [
            'enabled' => true,
            'target_guideline' => 'venous_thrombosis',
            'action' => 'pin',

            'pin_keywords' => [
                'diagnosis' => [
                    'DVT',
                    'deep vein thrombosis',
                    'PE',
                    'pulmonary embolism',
                    'VTE',
                    'venous thromboembolism'
                ],
                'assessment' => [
                    'D-dimer',
                    'Wells score',
                    'compression ultrasound',
                    'CTPA'
                ],
                'classification' => [
                    'provoked',
                    'unprovoked',
                    'proximal DVT',
                    'distal DVT'
                ],
                'management' => [
                    'anticoagulation duration',
                    'catheter-directed thrombolysis'
                ]
            ],

            'collision_rules' => [
                ['detect' => ['DVT', 'anticoagulation'], 'add' => 'antithrombotic_therapy']
            ],

            'exclude_keywords' => [
                'dialysis',
                'catheter',
                'fistula',
                'AVF',
                'AVG',
                // 'CEAP', // Removed to allow overlap
                // 'chronic venous', // Removed to allow overlap
                'varicose',
                'rest pain',
                'ulcer',
                'stroke',
                'TIA',
                'carotid',
                'neurological'
            ],

            'match_config' => [
                'min_keyword_matches' => 1,
                'case_insensitive' => true,
                'word_boundary' => true
            ]
        ],

        // 9. CHRONIC VENOUS DISEASE
        'chronic_venous_disease' => [
            'enabled' => true,
            'target_guideline' => 'chronic_venous_disease',
            'action' => 'pin',

            'pin_keywords' => [
                'presentation' => [
                    'varicose veins',
                    'venous ulcer',
                    'chronic venous insufficiency',
                    'CVI',
                    'May-Thurner',        // New
                    'pelvic congestion'   // New
                ],
                'classification' => [
                    'CEAP',
                    'post-thrombotic syndrome',
                    'PTS'
                ],
                'assessment' => [
                    'venous reflux',
                    'GSV reflux',
                    'SSV'
                ],
                'intervention' => [
                    'compression therapy',
                    'endovenous ablation',
                    'sclerotherapy'
                ]
            ],

            'match_config' => [
                'min_keyword_matches' => 1,
                'case_insensitive' => true,
                'word_boundary' => true
            ]
        ],

        // 10. DESCENDING THORACIC AORTA
        'descending_thoracic' => [
            'enabled' => true,
            'target_guideline' => 'descending_thoracic_aorta',
            'action' => 'pin',

            'pin_keywords' => [
                'pathology' => [
                    'type B dissection',
                    'TBAD',
                    'sTBAD',
                    'uTBAD',
                    'descending thoracic aneurysm',
                    'penetrating aortic ulcer',
                    'PAU',
                    'intramural hematoma',
                    'IMH'
                ],
                'taaa' => [
                    'thoracoabdominal aneurysm',
                    'TAAA',
                    'Crawford classification',
                    'Crawford extent I',
                    'Crawford extent II',
                    'Crawford extent III',
                    'Crawford extent IV',
                    'Crawford extent V',
                    'extent I',
                    'extent II',
                    'extent III',
                    'extent IV',
                    'extent V'
                ],
                'complex_aaa' => [
                    'juxtarenal AAA',
                    'pararenal AAA',
                    'paravisceral AAA',
                    'suprarenal AAA',
                    'complex AAA',
                    'FEVAR',
                    'BEVAR',
                    'FBEVAR',
                    'fenestrated EVAR',
                    'branched EVAR'
                ],
                'procedure' => [
                    'TEVAR',
                    'thoracic endovascular',
                    'thoracic stent graft'
                ],
                'complications' => [
                    'spinal cord ischemia',
                    'paraplegia',
                    'CSF drain',
                    'left subclavian coverage'
                ],
                'zones' => [
                    'zone 3',
                    'zone 4',
                    'zone 5'
                ]
            ],

            'exclude_keywords' => [
                'ascending aorta',
                'aortic root',
                'zone 0',
                'zone 1',
                'zone 2',
                'graft infection', // New
                'fistula',        // New
                'mycotic'         // New
            ],

            'collision_rules' => [
                ['detect' => ['TEVAR', 'fever'], 'add' => 'graft_infections']
            ],

            'match_config' => [
                'min_keyword_matches' => 1,
                'case_insensitive' => true,
                'word_boundary' => true
            ]
        ],

        // 11. AORTIC ARCH
        'aortic_arch' => [
            'enabled' => true,
            'target_guideline' => 'aortic_arch',
            'action' => 'pin',

            'pin_keywords' => [
                'anatomy' => [
                    'aortic arch',
                    'arch aneurysm',
                    'arch dissection',
                    'supra-aortic',
                    'brachiocephalic'
                ],
                'zones' => [
                    'zone 0',
                    'zone 1',
                    'zone 2'
                ],
                'procedure' => [
                    'arch debranching',
                    'hybrid arch repair',
                    'total arch replacement',
                    'elephant trunk',
                    'frozen elephant trunk',
                    'FET'
                ],
                'protection' => [
                    'cerebral protection',
                    'hypothermic circulatory arrest'
                ]
            ],

            'exclude_keywords' => [
                'graft infection',
                'fistula',
                'mycotic',
                'AAA',               // New: Strict isolation
                'abdominal'         // New: Strict isolation
            ],

            'collision_rules' => [
                ['detect' => ['arch', 'fever'], 'add' => 'graft_infections']
            ],

            'match_config' => [
                'min_keyword_matches' => 1,
                'case_insensitive' => true,
                'word_boundary' => true
            ]
        ],

        // 12. MESENTERIC & RENAL VESSELS
        'mesenteric_renal' => [
            'enabled' => true,
            'target_guideline' => 'mesenteric_renal',
            'action' => 'pin',

            'pin_keywords' => [
                'mesenteric' => [
                    'acute mesenteric ischemia',
                    'AMI',
                    'chronic mesenteric ischemia',
                    'CMI',
                    'intestinal angina',
                    'SMA stenosis',
                    'celiac stenosis'
                ],
                'renal' => [
                    'renal artery stenosis',
                    'RAS',
                    'renovascular hypertension',
                    'fibromuscular dysplasia',
                    'FMD'
                ],
                'visceral' => [
                    'visceral aneurysm',
                    'splenic aneurysm',
                    'hepatic aneurysm'
                ]
            ],

            'match_config' => [
                'min_keyword_matches' => 1,
                'case_insensitive' => true,
                'word_boundary' => true
            ],

            'exclude_keywords' => [
                'AAA',
                'abdominal aortic aneurysm',
                'EVAR',
                'endoleak'
            ]
        ],

        // 13. ASYMPTOMATIC PAD & CLAUDICATION
        'asymptomatic_pad' => [
            'enabled' => true,
            'target_guideline' => 'asymptomatic_pad',
            'action' => 'pin',

            'pin_keywords' => [
                'screening' => [
                    'ABI screening',
                    'asymptomatic PAD'
                ],
                'claudication' => [
                    'intermittent claudication',
                    'claudication',
                    'walking limitation',
                    'Rutherford 1',
                    'Rutherford 2',
                    'Rutherford 3'
                ],
                'management' => [
                    'supervised exercise therapy',
                    'cilostazol',
                    'best medical therapy',
                    'entrapment' // New
                ],
                'revascularization' => [
                    'revascularization for claudication'
                ]
            ],

            'exclude_keywords' => [
                'rest pain',
                'tissue loss',
                'gangrene',
                'CLTI',
                'acute',
                'ALI',
                'stroke', // New
                'TIA'    // New
            ],

            'collision_rules' => [
                ['detect' => ['ABI', 'aspirin'], 'add' => 'antithrombotic_therapy']
            ],

            'match_config' => [
                'min_keyword_matches' => 1,
                'case_insensitive' => true,
                'word_boundary' => true
            ]
        ],

        // 14. VASCULAR ACCESS
        'vascular_access' => [
            'enabled' => true,
            'target_guideline' => 'vascular_access',
            'action' => 'pin',

            'pin_keywords' => [
                'access_types' => [
                    'AVF',
                    'arteriovenous fistula',
                    'AVG',
                    'arteriovenous graft',
                    'hemodialysis access',
                    'dialysis access'
                ],
                'complications' => [
                    'fistula maturation',
                    'steal syndrome',
                    'access thrombosis',
                    'access stenosis'
                ],
                'catheter' => [
                    'tunneled catheter',
                    'dialysis catheter',
                    'permcath'
                ],
                'procedures' => [
                    'fistulogram',
                    'fistula angioplasty',
                    'DRIL'
                ]
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
