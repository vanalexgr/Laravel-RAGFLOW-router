<?php

return [
    // Master toggle. Set GAP_DETECTION_ENABLED=false to revert to single-pass retrieval.
    'enabled' => filter_var(env('GAP_DETECTION_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    // Enforce strict structured output hints in tool responses.
    'strict_template' => filter_var(env('STRICT_TEMPLATE_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    // Allow partial-match answers when evidence is relevant but not exact.
    'allow_partial_answer' => filter_var(env('ALLOW_PARTIAL_EVIDENCE_ANSWERS', true), FILTER_VALIDATE_BOOLEAN),

    // Max additional retrieval passes (0 = no second pass).
    'max_passes' => (int) env('GAP_DETECTION_MAX_PASSES', 1),

    // Per-pass caps for the focused retrieval.
    'second_pass' => [
        'narrative_max' => (int) env('GAP_DETECTION_NARRATIVE_MAX', 4),
        'citation_max' => (int) env('GAP_DETECTION_CITATION_MAX', 3),
    ],

    // Required sections per intent (used for gap detection).
    // The detector maps intent -> required fields, then checks evidence for each field.
    'intent_requirements' => [
        'threshold' => ['assessment', 'imaging', 'indication', 'treatment', 'follow_up', 'threshold'],
        'surveillance' => ['assessment', 'imaging', 'follow_up', 'threshold'],
        'imaging' => ['assessment', 'imaging', 'follow_up'],
        'treatment' => ['assessment', 'indication', 'treatment', 'follow_up'],
        'management' => ['assessment', 'indication', 'treatment', 'follow_up'],
        'timing' => ['assessment', 'indication', 'treatment', 'follow_up', 'timing'],
        'comparison' => ['assessment', 'treatment', 'indication'],
        'risk' => ['assessment', 'indication', 'treatment', 'follow_up'],
        'definition' => ['assessment', 'indication'],
        'general' => ['assessment', 'indication', 'treatment', 'follow_up'],
    ],

    // Default required fields when intent is unknown.
    'default_requirements' => ['assessment', 'indication', 'treatment', 'follow_up'],

    // Regex patterns used to detect whether a field is covered in retrieved evidence.
    'field_patterns' => [
        'assessment' => [
            '\\bdiagnos', '\\bdefinition', '\\bclassification', '\\bstage', '\\bgrade', '\\bseverity',
            '\\bcriteria', '\\btype\\b', '\\bcategory', '\\bstatus',
        ],
        'imaging' => [
            '\\bcta\\b', '\\bct\\b', '\\bmra\\b', '\\bmri\\b', '\\bdus\\b', '\\bduplex\\b',
            '\\bultrasound', '\\bangiograph', '\\bimaging', '\\bscan', '\\bfollow[- ]?up imaging',
        ],
        'indication' => [
            '\\bindicat', '\\bshould\\b', '\\brecommend', '\\bconsider', '\\bwhen to', '\\btrigger',
            '\\bintervention', '\\boperate', '\\brepair',
        ],
        'treatment' => [
            '\\btreat', '\\bmanage', '\\bintervention', '\\bemboliz', '\\bsurgery', '\\boperative',
            '\\bevar', '\\bosr', '\\bopen repair', '\\bstent', '\\bendarterectomy',
        ],
        'follow_up' => [
            '\\bfollow[- ]?up', '\\bsurveillance', '\\binterval', '\\bmonths?\\b', '\\byears?\\b',
            '\\brepeat', '\\bmonitor',
        ],
        'threshold' => [
            '\\bmm\\b', '\\bcm\\b', '\\bthreshold', '\\bdiameter', '\\bsize', '\\bsac expansion',
            '\\bgrowth', '\\bexpand', '\\b≥|>=',
        ],
        'timing' => [
            '\\bwithin', '\\bweeks?\\b', '\\bmonths?\\b', '\\bwindow\\b', '\\btiming', '\\bdelay',
        ],
    ],

    // Gap-driven query enrichments (appended to focused second-pass retrieval).
    'field_query_terms' => [
        'assessment' => ['classification', 'criteria', 'definition'],
        'imaging' => ['imaging', 'CTA', 'duplex', 'ultrasound', 'follow-up imaging'],
        'indication' => ['indication', 'recommendation', 'when to intervene'],
        'treatment' => ['management', 'intervention', 'treatment options'],
        'follow_up' => ['follow-up', 'surveillance interval'],
        'threshold' => ['threshold', 'diameter', 'mm', 'cm', 'sac expansion'],
        'timing' => ['timing', 'within', 'weeks', 'months'],
    ],

    // Debug info returned to clients (kept lean).
    'include_debug' => filter_var(env('GAP_DETECTION_DEBUG', false), FILTER_VALIDATE_BOOLEAN),
];
