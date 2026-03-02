<?php

return [
    // Enable pre-retrieval clinical interpretation to enrich query terms.
    'enabled' => filter_var(env('CLINICAL_INTERPRETER_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    // Max additional retrieval terms.
    'max_terms' => (int) env('CLINICAL_INTERPRETER_MAX_TERMS', 10),

    // Max critical terms to force into LLM evidence selection.
    'max_must_terms' => (int) env('CLINICAL_INTERPRETER_MAX_MUST_TERMS', 4),

    // Timeout for the interpreter call (seconds).
    'timeout' => (int) env('CLINICAL_INTERPRETER_TIMEOUT', 6),
];
