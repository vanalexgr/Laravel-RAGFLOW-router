<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Intent term table
    |--------------------------------------------------------------------------
    | Per-intent keyword lists used by ChunkSelectionService to boost chunks
    | whose text matches the query intent.  Keys are the intent labels returned
    | by GuidelineRouterService::normalizeQuery().  Add new specialties here
    | without touching service code.
    */
    'intent_terms' => [
        'threshold'       => ['threshold', 'diameter', 'size', 'mm', 'cm', 'elective repair', 'indication for repair', 'operate', 'surgery'],
        'indication'      => ['indication', 'indicated', 'considered for', 'recommended', 'should be considered'],
        'contraindication'=> ['contraindication', 'contraindicated', 'not recommended', 'avoid'],
        'surveillance'    => ['surveillance', 'follow-up', 'follow up', 'interval', 'monitoring', 'duplex', 'ultrasound', 'cta'],
        'imaging'         => ['imaging', 'ultrasound', 'duplex', 'cta', 'ct angiography', 'mra', 'mrv', 'scan'],
        'diagnosis'       => ['diagnosis', 'diagnostic', 'work up', 'workup', 'ultrasound', 'cta', 'duplex'],
        'treatment'       => ['treatment', 'management', 'recommended', 'considered', 'therapy', 'procedure'],
        'management'      => ['management', 'recommended', 'considered', 'therapy', 'treatment'],
        'procedure'       => ['procedure', 'repair', 'intervention', 'stenting', 'endarterectomy', 'evar', 'tevar'],
        'timing'          => ['timing', 'when', 'urgent', 'delay', 'early', 'perioperative'],
        'comparison'      => ['versus', 'vs', 'compared', 'difference', 'rather than', 'preference'],
        'risk'            => ['risk', 'complication', 'bleeding', 'contraindication', 'contraindicated'],
        'prognosis'       => ['prognosis', 'outcome', 'survival', 'mortality'],
        'definition'      => ['definition', 'is defined', 'what is', 'classification'],
        'general'         => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Key-term stop words
    |--------------------------------------------------------------------------
    | Removed from key_terms before scoring to avoid generic noise matches.
    */
    'key_term_stop_words' => [
        'management', 'treatment', 'therapy', 'guideline', 'recommendation',
        'patient', 'patients', 'disease', 'surgery', 'repair', 'aorta', 'aneurysm',
    ],

    /*
    |--------------------------------------------------------------------------
    | Chunk caps — LLM-facing and UI-facing, single vs multi guideline
    |--------------------------------------------------------------------------
    | ChunkSelectionService applies these after intent ranking and diversification.
    | Upstream evidence_caps in ragflow.php remain as the RAGFlow-side ceiling.
    */
    'caps' => [
        'single' => [
            'llm_rec'  => 6,
            'llm_narr' => 4,
            'ui_rec'   => 12,
            'ui_narr'  => 8,
        ],
        'multi' => [
            'llm_rec'  => 8,
            'llm_narr' => 8,
            'ui_rec'   => 18,
            'ui_narr'  => 12,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scoring weights
    |--------------------------------------------------------------------------
    */
    'scoring' => [
        'intent_term_hit'                    => 4,
        'key_term_hit_multiword'             => 3,  // terms containing a space
        'key_term_hit_single'                => 1,  // single-word terms
        'key_term_length_bonus_per_10'       => 1,  // +1 per 10 chars of term
        'key_term_length_bonus_max'          => 3,  // cap on length bonus
        'non_a_non_b_match'                  => 12,
        'cue_match'                          => 2,
        'recommendation_type_boost'          => 2,
        'narrative_frontmatter_penalty'      => -2,
        'narrative_editors_choice_penalty'   => -1,
        'must_include_min_score'             => 1,  // min score to qualify must-include
    ],

    /*
    |--------------------------------------------------------------------------
    | Decisive cue phrases
    |--------------------------------------------------------------------------
    | Present in both query AND chunk text → small scoring boost.
    */
    'cue_phrases' => [
        'recommended', 'should be considered', 'indicated',
        'surveillance', 'imaging', 'diagnosis', 'repair',
    ],

    /*
    |--------------------------------------------------------------------------
    | Recommendation question types (triggers citation chunk boost)
    |--------------------------------------------------------------------------
    */
    'recommendation_question_types' => [
        'recommendation', 'treatment_decision', 'perioperative',
    ],

];
