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
    'shadow_enabled' => env(
        'GATE_STATE_SHADOW_ENABLED',
        env('APP_ENV', 'production') === 'testing',
    ),

    'cache_prefix' => env('GATE_STATE_CACHE_PREFIX', 'gate-state:shadow:'),
    'retention_seconds' => (int) env('GATE_STATE_RETENTION_SECONDS', 86400),

    /*
    | Any different canonical value in these fields requires CorrectFact.
    | Longer/more specific aliases precede substring aliases where needed.
    */
    'mutually_exclusive_fields' => [
        'symptom_status' => [
            'asymptomatic' => ['asymptomatic', 'no attributable symptoms', 'without symptoms'],
            'symptomatic' => ['symptomatic', 'attributable symptoms'],
        ],
        'symptoms' => [
            'asymptomatic' => ['asymptomatic', 'no attributable symptoms', 'without symptoms'],
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
