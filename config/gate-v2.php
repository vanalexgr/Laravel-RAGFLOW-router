<?php

return [
    'enabled' => env('GATE_V2_ENABLED', false),
    'provider' => env('GATE_V2_PROVIDER', 'openai'),
    'model' => env('GATE_V2_MODEL', 'gpt-5-mini'),
    'deadline_seconds' => (int) env('GATE_V2_DEADLINE_SECONDS', 90),
    'stage_models' => [
        'orient' => env('GATE_V2_ORIENT_MODEL', 'gpt-4.1-mini'),
        'pathway' => env('GATE_V2_PATHWAY_MODEL', 'gpt-4.1-mini'),
        'probe' => env('GATE_V2_PROBE_MODEL', 'gpt-4.1'),
        'critic' => env('GATE_V2_CRITIC_MODEL', 'gpt-4.1'),
        'knowledge' => env('GATE_V2_KNOWLEDGE_MODEL', 'gpt-5-mini'),
    ],
    'stage_timeouts' => [
        'orient' => (int) env('GATE_V2_ORIENT_TIMEOUT_SECONDS', 30),
        'pathway' => (int) env('GATE_V2_PATHWAY_TIMEOUT_SECONDS', 30),
        'probe' => (int) env('GATE_V2_PROBE_TIMEOUT_SECONDS', 30),
        'critic' => (int) env('GATE_V2_CRITIC_TIMEOUT_SECONDS', 30),
        'knowledge' => (int) env('GATE_V2_KNOWLEDGE_TIMEOUT_SECONDS', 30),
        'default' => (int) env('GATE_V2_STAGE_TIMEOUT_SECONDS', 15),
    ],
    'revision_reserve_seconds' => (int) env('GATE_V2_REVISION_RESERVE_SECONDS', 25),
    'minimum_revision_seconds' => [
        'orient_route' => 25,
        'ground' => 20,
        'probe' => 12,
    ],
    'max_iterations' => (int) env('GATE_V2_MAX_ITERATIONS', 3),
    'deep_path_mode' => env('GATE_V2_DEEP_PATH_MODE', 'parallel'),
    'concurrency_driver' => env('GATE_V2_CONCURRENCY_DRIVER', 'process'),
    'retrieval' => [
        'max_attempts' => (int) env('GATE_V2_RETRIEVAL_MAX_ATTEMPTS', 2),
        'revision_max_attempts' => (int) env('GATE_V2_REVISION_RETRIEVAL_MAX_ATTEMPTS', 1),
        'attempt_top_k' => [12, 24],
        // A gate retrieval must never inherit the generic 30-second bridge timeout:
        // this budget is deliberately below the parent 90-second wall-clock.
        'timeout_seconds' => (int) env('GATE_V2_RETRIEVAL_TIMEOUT_SECONDS', 20),
        'connect_timeout_seconds' => (int) env('GATE_V2_RETRIEVAL_CONNECT_TIMEOUT_SECONDS', 3),
        // Below this much remaining wall-clock a further retrieval attempt cannot
        // finish and be assessed, so the branch returns what it already has.
        'minimum_attempt_seconds' => (int) env('GATE_V2_RETRIEVAL_MINIMUM_ATTEMPT_SECONDS', 8),
        // A relevant first pass with enough strong evidence is not re-run merely
        // because the assessor prefers a different wording of the same query.
        'sufficient_snippet_count' => (int) env('GATE_V2_RETRIEVAL_SUFFICIENT_SNIPPETS', 4),
        'sufficient_similarity' => (float) env('GATE_V2_RETRIEVAL_SUFFICIENT_SIMILARITY', 0.78),
    ],
    'bounce_budgets' => [
        'orient_route' => 2,
        'ground' => 1,
        'probe' => 2,
    ],
    'synthesis_owner' => env('SYNTHESIS_OWNER', 'adapter'),
    'synthesis_model' => env('SYNTHESIS_MODEL', 'cloud'),
    'synthesis' => [
        'provider' => env('SYNTHESIS_PROVIDER', 'openai'),
        'cloud_model' => env('SYNTHESIS_CLOUD_MODEL', 'gpt-5-mini'),
        'local_model' => env('SYNTHESIS_LOCAL_MODEL', ''),
        'timeout_seconds' => (int) env('SYNTHESIS_TIMEOUT_SECONDS', 60),
    ],

    'audited_snippets' => [
        // TODO(human): Enable only after every candidate has clinician sign-off and an audit record.
        'enabled' => env('GATE_V2_AUDITED_SNIPPETS_ENABLED', false),
        'path' => base_path('eval/audited_snippets.md'),
    ],
];
