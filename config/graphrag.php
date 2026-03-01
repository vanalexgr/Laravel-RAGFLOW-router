<?php

return [
    // Master toggle for GraphRAG-style concept expansion.
    'enabled' => filter_var(env('GRAPHRAG_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    // Use LLM for concept expansion + slot extraction.
    'llm_enabled' => filter_var(env('GRAPHRAG_LLM_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    // Always extract intent/slots via normalization (even for English).
    'intent_enabled' => filter_var(env('GRAPHRAG_INTENT_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    // If true, use normalized_query as retrieval query even for English.
    'use_normalized_query' => filter_var(env('GRAPHRAG_USE_NORMALIZED_QUERY', false), FILTER_VALIDATE_BOOLEAN),

    // Candidate pool sizing.
    'max_candidate_concepts' => (int) env('GRAPHRAG_MAX_CANDIDATES', 60),

    // Output sizing.
    'max_core_concepts' => (int) env('GRAPHRAG_MAX_CORE', 8),
    'max_related_concepts' => (int) env('GRAPHRAG_MAX_RELATED', 8),
    'max_query_terms' => (int) env('GRAPHRAG_MAX_QUERY_TERMS', 12),

    // Gap detection integration for missing concepts.
    'concept_gap_check' => filter_var(env('GRAPHRAG_CONCEPT_GAP_CHECK', true), FILTER_VALIDATE_BOOLEAN),
    'concept_gap_max_terms' => (int) env('GRAPHRAG_CONCEPT_GAP_MAX', 6),

    // Debug payloads in API responses.
    'include_debug' => filter_var(env('GRAPHRAG_DEBUG', false), FILTER_VALIDATE_BOOLEAN),
];
