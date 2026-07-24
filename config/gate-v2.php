<?php

return [
    'enabled' => env('GATE_V2_ENABLED', false),
    'provider' => env('GATE_V2_PROVIDER', 'openai'),
    'model' => env('GATE_V2_MODEL', 'gpt-5-mini'),
    'deadline_seconds' => (int) env('GATE_V2_DEADLINE_SECONDS', 90),
    'stage_timeout_seconds' => (int) env('GATE_V2_STAGE_TIMEOUT_SECONDS', 30),
    'revision_reserve_seconds' => (int) env('GATE_V2_REVISION_RESERVE_SECONDS', 35),
    'max_iterations' => (int) env('GATE_V2_MAX_ITERATIONS', 3),
    'deep_path_mode' => env('GATE_V2_DEEP_PATH_MODE', 'parallel'),
    'concurrency_driver' => env('GATE_V2_CONCURRENCY_DRIVER', 'process'),
    'retrieval' => [
        'max_attempts' => (int) env('GATE_V2_RETRIEVAL_MAX_ATTEMPTS', 2),
        'attempt_top_k' => [24, 48],
    ],
    'bounce_budgets' => [
        'orient_route' => 1,
        'ground' => 1,
        'probe' => 2,
    ],
    'synthesis_owner' => env('SYNTHESIS_OWNER', 'adapter'),
    'synthesis_model' => env('SYNTHESIS_MODEL', 'cloud'),

    'audited_snippets' => [
        // TODO(human): Enable only after every candidate has clinician sign-off and an audit record.
        'enabled' => env('GATE_V2_AUDITED_SNIPPETS_ENABLED', false),
        'path' => base_path('eval/audited_snippets.md'),
    ],
];
