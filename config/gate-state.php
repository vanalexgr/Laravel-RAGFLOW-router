<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Phase 1 shadow state ledger
    |--------------------------------------------------------------------------
    |
    | Shadow mode records and diffs state, but GateWorkflowService never reads
    | its projection for routing, retrieval, prompts, decisions, or answers.
    |
    */
    'shadow_enabled' => env('GATE_STATE_SHADOW_ENABLED', false),

    /*
    | Any different canonical value in these fields requires CorrectFact.
    | Field aliases are normalized before projection so schema-name drift cannot
    | create a second, unguarded version of the same clinical fact.
    */
    'field_aliases' => [
        'symptoms' => 'symptom_status',
        'symptom_presentation' => 'symptom_status',
        'symptomatic_status' => 'symptom_status',
    ],

    'canonical_fields' => [
        'demographics',
        'lesion',
        'other_findings',
        'symptom_status',
        'timing',
        'fitness',
        'imaging',
        'comorbidities',
        'medications',
        'prior_interventions',
        'sex',
        'evar_suitability',
    ],

    'mutually_exclusive_fields' => [
        'symptom_status' => [
            'asymptomatic' => [
                'asymptomatic',
                'not symptomatic',
                'no attributable symptoms',
                'without symptoms',
            ],
            'symptomatic' => ['symptomatic', 'attributable symptoms'],
        ],
        'sex' => [
            'female' => ['female', 'woman'],
            'male' => ['male', 'man'],
        ],
        'evar_suitability' => [
            'unsuitable' => ['unsuitable', 'not suitable'],
            'suitable' => ['suitable'],
        ],
    ],
];
