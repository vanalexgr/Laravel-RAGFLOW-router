<?php

return [
    'enabled' => env('GATE_V2_ENABLED', false),
    'provider' => env('GATE_V2_PROVIDER', 'openai'),
    'model' => env('GATE_V2_MODEL', 'gpt-5-mini'),
    'synthesis_owner' => env('SYNTHESIS_OWNER', 'adapter'),
    'synthesis_model' => env('SYNTHESIS_MODEL', 'cloud'),

    'audited_snippets' => [
        // TODO(human): Enable only after every candidate has clinician sign-off and an audit record.
        'enabled' => env('GATE_V2_AUDITED_SNIPPETS_ENABLED', false),
        'path' => base_path('eval/audited_snippets.md'),
    ],
];
