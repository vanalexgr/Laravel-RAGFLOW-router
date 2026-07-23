<?php

return [
    'scenarios_path' => env('GATE_EVAL_SCENARIOS_PATH', base_path('eval/scenarios')),
    'runs_disk' => env('GATE_EVAL_RUNS_DISK', 'local'),
    'runs_path' => env('GATE_EVAL_RUNS_PATH', 'gate-eval/runs'),

    'subject' => [
        'url' => env('GATE_EVAL_SUT_URL'),
        'model' => env('GATE_EVAL_SUT_MODEL', 'stub-sut'),
        'timeout' => (int) env('GATE_EVAL_SUT_TIMEOUT', 120),
    ],

    'judge' => [
        'url' => env('GATE_EVAL_JUDGE_URL'),
        'api_key' => env('GATE_EVAL_JUDGE_API_KEY', env('OPENAI_API_KEY')),
        'model' => env('GATE_EVAL_JUDGE_MODEL', 'gpt-5'),
        'timeout' => (int) env('GATE_EVAL_JUDGE_TIMEOUT', 120),
    ],
];
