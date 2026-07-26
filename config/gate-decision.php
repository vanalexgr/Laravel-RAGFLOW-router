<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Typed deferral boundary
    |--------------------------------------------------------------------------
    */
    'deferral_codes' => [
        'GUIDELINE_MANDATED',
        'UNRESOLVABLE_CONTRAINDICATION',
        'MULTISPECIALTY_CONFLICT',
    ],

    'deferral_terms' => [
        '\bMDT\b',
        '\bmultidisciplinary\b',
        '\bindividuali[sz](?:e|ed|ation)\b',
        '\blocal protocols?\b',
        '\bconsult (?:the )?local protocol\b',
        '\bclinical judg(?:e)?ment\b',
    ],

    'covered_evidence_statuses' => [
        'covered',
        'partial_principles',
        'interaction_gap',
    ],

    /*
    |--------------------------------------------------------------------------
    | Trigger-based checklists
    |--------------------------------------------------------------------------
    |
    | Each trigger is declarative. "all" is a list of alternative-term groups;
    | one term from every group must occur in the context/output. Requirements
    | use generic validator operations, so adding another trigger is data-only.
    |
    */
    'triggers' => [
        'antithrombotic' => [
            'all' => [
                [
                    '\bantithrombotic\w*\b',
                    '\banticoagul\w*\b',
                    '\bantiplatelet\w*\b',
                    '\bapixaban\b',
                    '\brivaroxaban\b',
                    '\bwarfarin\b',
                    '\baspirin\b',
                    '\bclopidogrel\b',
                ],
            ],
            'requirements' => [
                'explicit_harm_avoidance' => [
                    'type' => 'non_empty_path',
                    'path' => 'actionable_plan.what_not_to_do',
                    'message' => 'State at least one harm to avoid, or use EVIDENCE_ABSENT rather than inventing one.',
                ],
            ],
        ],

        'carotid_anticoagulant' => [
            'all' => [
                ['\bcarotid\b', '\bCEA\b', '\bendarterectom\w*\b'],
                [
                    '\bDOACs?\b',
                    '\bdirect oral anticoagul\w*\b',
                    '\bapixaban\b',
                    '\brivaroxaban\b',
                    '\bedoxaban\b',
                    '\bdabigatran\b',
                ],
            ],
            'requirements' => [
                'urgency' => [
                    'type' => 'patterns',
                    'patterns' => [
                        '\burgent\w*\b',
                        '\bwithin\s+\d+\s*(?:hours?|days?|weeks?)\b',
                        '\b<\s*\d+\s*(?:hours?|days?|weeks?)\b',
                    ],
                    'message' => 'Address carotid intervention urgency with a committed timeframe.',
                ],
                'anticoagulant_interruption' => [
                    'type' => 'patterns',
                    'patterns' => [
                        '\binterrupt\w*\b',
                        '\bwithhold\w*\b',
                        '\bhold\b',
                        '\bstop\b',
                        '\bomitted?\b',
                    ],
                    'message' => 'Address perioperative DOAC interruption.',
                ],
                'bridging' => [
                    'type' => 'patterns',
                    'patterns' => ['\bbridg(?:e|ed|ing)\b'],
                    'message' => 'State whether perioperative bridging is indicated.',
                ],
                'perioperative_antiplatelet' => [
                    'type' => 'pattern_groups',
                    'groups' => [
                        ['\bperi-?operativ\w*\b', '\bpre-?operativ\w*\b', '\baspirin\b'],
                        ['\bantiplatelet\w*\b', '\baspirin\b', '\bclopidogrel\b'],
                    ],
                    'message' => 'State the perioperative antiplatelet strategy.',
                ],
                'restart_criterion' => [
                    'type' => 'pattern_groups',
                    'groups' => [
                        ['\brestart\w*\b', '\bresume\w*\b', '\brecommence\w*\b'],
                        [
                            '\bhaemostasis\b',
                            '\bhemostasis\b',
                            '\bbleeding\b',
                            '\bpost-?operativ\w*\b',
                            '\bafter\s+\d+\s*(?:hours?|days?)\b',
                        ],
                    ],
                    'message' => 'Give an observable criterion for restarting anticoagulation.',
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rule-based high-risk antithrombotic combination
    |--------------------------------------------------------------------------
    */
    'long_term_combination' => [
        'anticoagulants' => [
            '\bapixaban\b',
            '\brivaroxaban\b',
            '\bedoxaban\b',
            '\bdabigatran\b',
            '\bwarfarin\b',
            '\b(?:oral )?anticoagul\w*\b',
        ],
        'antiplatelets' => [
            '\baspirin\b',
            '\bclopidogrel\b',
            '\bprasugrel\b',
            '\bticagrelor\b',
            '\bantiplatelet\w*\b',
        ],
        'long_term_terms' => [
            '\blong[ -]?term\b',
            '\bindefinit\w*\b',
            '\blifelong\b',
            '\bongoing\b',
            '\bcontinue\w*\b',
            '\bmaintenance\b',
        ],
        'empty_justifications' => [
            '',
            'NOT_APPLICABLE',
            'EVIDENCE_ABSENT',
            'NONE',
        ],
    ],
];
