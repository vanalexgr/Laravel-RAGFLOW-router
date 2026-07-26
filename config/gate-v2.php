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
    // Coverage verdicts cannot be adjudicated from an artifact that records only
    // `snippet_count` — Run 7's audit had to mark half its rows "not determinable".
    // Enable in eval runs to persist the ranked evidence the stages actually saw.
    // Off by default: this adds guideline text to every gate response.
    'audit' => [
        'persist_snippet_digests' => (bool) env('GATE_V2_PERSIST_SNIPPET_DIGESTS', false),
        'snippet_digest_max_per_guideline' => (int) env('GATE_V2_SNIPPET_DIGEST_MAX', 6),
        'snippet_digest_max_chars' => (int) env('GATE_V2_SNIPPET_DIGEST_CHARS', 600),
    ],
    // Model-name prefixes that accept a `reasoning.effort` provider option. A stage
    // model outside this list silently drops the effort setting, so extend this when
    // adopting a new reasoning family rather than assuming effort is being applied.
    'reasoning_model_prefixes' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('GATE_V2_REASONING_MODEL_PREFIXES', 'gpt-5,o1,o3,o4')),
    ))),
    // Reasoning effort per stage. Only reaches the provider for reasoning-capable
    // models (see GateModelOptions); with a non-reasoning stage model the value is
    // inert, which is why the Run 7 `effort=low` labels had no effect on gpt-4.1.
    // Null falls back to the agent's own REASONING_EFFORT constant.
    'stage_efforts' => [
        'orient' => env('GATE_V2_ORIENT_EFFORT'),
        'pathway' => env('GATE_V2_PATHWAY_EFFORT'),
        'probe' => env('GATE_V2_PROBE_EFFORT'),
        'critic' => env('GATE_V2_CRITIC_EFFORT'),
        'knowledge' => env('GATE_V2_KNOWLEDGE_EFFORT'),
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
        // Share of every evidence budget reserved for verbatim recommendations,
        // matching the legacy adapter's dual-retrieval mix (`evidence_caps`:
        // narrative 16 / citation 12). Applied at retrieval, retry merge, and
        // prompt compaction alike — see GateEvidenceQuota.
        'citation_share' => (float) env('GATE_V2_CITATION_SHARE', 0.4),
        // Snippets per guideline handed to Probe/Critic.
        'prompt_snippets_per_guideline' => (int) env('GATE_V2_PROMPT_SNIPPETS_PER_GUIDELINE', 6),
        // The recommendations dataset matches short declarative rows. Measured on
        // both recommendations documents: question-form queries return ZERO
        // recommendations at 292, 156 and 116 chars, while terms-only queries
        // return 2-6 at 98 and 54 chars. 100 is the largest budget proven on both
        // documents; the stricter CLTI document did better still at ~54, so this is
        // worth re-probing if recommendation supply is thin.
        'citation_query_max_chars' => (int) env('GATE_V2_CITATION_QUERY_MAX_CHARS', 100),
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
