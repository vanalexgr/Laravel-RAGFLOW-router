<?php

namespace App\Services\Routing;

use Illuminate\Support\Facades\Log;

class GuardrailResult
{
    public function __construct(
        public array $selectedRoutes,
        public array $rulesEvaluated,
        public array $rulesTriggered,
        public array $decisions
    ) {
    }
}

class GuardrailDecider
{
    protected array $config;
    protected array $priorityOrder;
    protected float $scoreGapThreshold;

    public function __construct()
    {
        $this->config = config('router_abbreviations.guardrails', []);
        $this->priorityOrder = config('router_abbreviations.priority_order', []);
        $this->scoreGapThreshold = config('router_abbreviations.score_gap_threshold', 0.08);
    }

    /**
     * Apply guardrails to routing candidates with enhanced action support.
     *
     * @param string $query Original user query
     * @param array $candidates Route result with 'keys' and 'scores'
     * @return GuardrailResult
     */
    public function apply(string $query, array $candidates): GuardrailResult
    {
        $enabled = config('router_abbreviations.guardrails_enabled', true);

        if (!$enabled) {
            return new GuardrailResult(
                selectedRoutes: $candidates['keys'] ?? [],
                rulesEvaluated: [],
                rulesTriggered: [],
                decisions: []
            );
        }

        $keys = $candidates['keys'] ?? [];
        $scores = $candidates['scores'] ?? [];

        // 1. Evaluate all rules by priority
        $evaluations = $this->evaluateAllRules($query, $keys, $scores);

        $decisions = [];

        // 2. Apply EXCLUDE rules first (vascular_trauma)
        [$keys, $excludeDecisions] = $this->applyExclusions($keys, $evaluations, $query);
        $decisions = array_merge($decisions, $excludeDecisions);

        // 3. Apply PIN rules (must be #1)
        [$keys, $pinDecisions] = $this->applyPins($keys, $evaluations);
        $decisions = array_merge($decisions, $pinDecisions);

        // 4. Apply collision rules (add companions from triggered rules)
        [$keys, $collisionDecisions] = $this->applyCollisions($keys, $evaluations, $query);
        $decisions = array_merge($decisions, $collisionDecisions);

        // 5. Apply COMPANION rules (add but not #1)
        [$keys, $companionDecisions] = $this->applyCompanions($keys, $evaluations);
        $decisions = array_merge($decisions, $companionDecisions);

        // 6. Apply score gap analysis (keep close candidates)
        [$keys, $gapDecisions] = $this->applyScoreGap($keys, $scores);
        $decisions = array_merge($decisions, $gapDecisions);

        // 7. Limit to reasonable number
        $keys = array_slice($keys, 0, 3);

        $rulesEvaluated = array_keys($evaluations);
        $rulesTriggered = array_map(fn($e) => $e['rule_name'], $evaluations);

        // Log guardrail decisions
        if (!empty($rulesTriggered)) {
            Log::channel('retrieval')->info('[GUARDRAILS] Rules applied', [
                'triggered' => $rulesTriggered,
                'decisions_count' => count($decisions),
                'final_keys' => $keys,
            ]);
        }

        return new GuardrailResult(
            selectedRoutes: $keys,
            rulesEvaluated: $rulesEvaluated,
            rulesTriggered: $rulesTriggered,
            decisions: $decisions
        );
    }

    /**
     * Evaluate all guardrail rules and return triggered ones by priority.
     */
    protected function evaluateAllRules(string $query, array $keys, array $scores): array
    {
        $evaluations = [];

        foreach ($this->priorityOrder as $priority => $ruleName) {
            if (!isset($this->config[$ruleName]) || !($this->config[$ruleName]['enabled'] ?? true)) {
                continue;
            }

            $rule = $this->config[$ruleName];
            $matchResult = $this->matchKeywords($query, $rule);

            if ($matchResult['triggered']) {
                $evaluations[$ruleName] = [
                    'rule_name' => $ruleName,
                    'priority' => $priority,
                    'action' => $rule['action'] ?? 'pin',
                    'target_guideline' => $rule['target_guideline'] ?? $ruleName,
                    'keywords_matched' => $matchResult['keywords'],
                    'match_count' => count($matchResult['keywords']),
                    'collision_rules' => $rule['collision_rules'] ?? [],
                    'exclude_keywords' => $rule['exclude_keywords'] ?? [],
                ];
            }
        }

        return $evaluations;
    }

    /**
     * Match keywords in query against rule patterns.
     */
    protected function matchKeywords(string $query, array $rule): array
    {
        $matchConfig = $rule['match_config'] ?? [];
        $minMatches = $matchConfig['min_keyword_matches'] ?? 2;
        $caseInsensitive = $matchConfig['case_insensitive'] ?? true;
        $wordBoundary = $matchConfig['word_boundary'] ?? true;

        $matched = [];
        $queryLower = $caseInsensitive ? strtolower($query) : $query;

        // Collect all keyword categories
        $allKeywords = array_merge(
            $rule['pin_keywords'] ?? [],
            $rule['companion_keywords'] ?? []
        );

        foreach ($allKeywords as $category => $terms) {
            if (!is_array($terms))
                continue;

            foreach ($terms as $term) {
                $searchTerm = $caseInsensitive ? strtolower($term) : $term;
                $searchQuery = $caseInsensitive ? $queryLower : $query;

                if ($wordBoundary) {
                    $pattern = '/\b' . preg_quote($searchTerm, '/') . '\b/i';
                    if (preg_match($pattern, $searchQuery)) {
                        $matched[] = $term;
                    }
                } else {
                    if (str_contains($searchQuery, $searchTerm)) {
                        $matched[] = $term;
                    }
                }
            }
        }

        $matched = array_unique($matched);
        return [
            'triggered' => count($matched) >= $minMatches,
            'keywords' => $matched
        ];
    }

    /**
     * Apply EXCLUDE rules (e.g., vascular_trauma excluded by default).
     */
    protected function applyExclusions(array $keys, array $evaluations, string $query): array
    {
        $decisions = [];

        foreach ($evaluations as $ruleName => $eval) {
            if ($eval['action'] === 'exclude_by_default') {
                $target = $eval['target_guideline'];

                // Check if explicitly triggered (has PIN keywords)
                if ($eval['match_count'] > 0) {
                    // Triggered - do NOT exclude, actually PIN it
                    $decisions[] = [
                        'rule' => $ruleName,
                        'action' => 'include_on_trigger',
                        'reason' => "Trauma mechanism detected, INCLUDING {$target}",
                        'keywords_matched' => $eval['keywords_matched'],
                    ];
                } else {
                    // Not triggered - EXCLUDE it
                    $keys = array_diff($keys, [$target]);
                    $decisions[] = [
                        'rule' => $ruleName,
                        'action' => 'exclude',
                        'reason' => "No trauma mechanism, EXCLUDING {$target}",
                    ];
                }
            }
        }

        return [$keys, $decisions];
    }

    /**
     * Apply PIN rules (must be #1 if triggered).
     */
    protected function applyPins(array $keys, array $evaluations): array
    {
        $decisions = [];
        $pins = array_filter($evaluations, fn($e) => in_array($e['action'], ['pin', 'exclude_by_default']));

        if (empty($pins)) {
            return [$keys, $decisions];
        }

        // Get highest priority PIN
        uasort($pins, fn($a, $b) => $a['priority'] <=> $b['priority']);
        $topPin = reset($pins);

        $target = $topPin['target_guideline'];

        // Check if already in candidates
        $wasInCandidates = in_array($target, $keys);

        // Move to #1 or add at #1
        $keys = array_diff($keys, [$target]);
        array_unshift($keys, $target);

        $decisions[] = [
            'rule' => $topPin['rule_name'],
            'action' => 'pin',
            'reason' => $wasInCandidates
                ? "Moved {$target} to #1"
                : "Added {$target} at #1",
            'keywords_matched' => $topPin['keywords_matched'],
            'match_count' => $topPin['match_count'],
        ];

        return [$keys, $decisions];
    }

    /**
     * Apply collision rules (add companion guidelines).
     */
    protected function applyCollisions(array $keys, array $evaluations, string $query): array
    {
        $decisions = [];

        foreach ($evaluations as $ruleName => $eval) {
            $collisionRules = $eval['collision_rules'] ?? [];

            foreach ($collisionRules as $collision) {
                $detectTerms = $collision['detect'] ?? [];
                $addGuideline = $collision['add'] ?? null;

                if (!$addGuideline)
                    continue;

                // Check if all detect terms are present in query
                $allPresent = true;
                foreach ($detectTerms as $term) {
                    if (!str_contains(strtolower($query), strtolower($term))) {
                        $allPresent = false;
                        break;
                    }
                }

                if ($allPresent && !in_array($addGuideline, $keys)) {
                    // Add companion guideline
                    $keys[] = $addGuideline;

                    $decisions[] = [
                        'rule' => $ruleName,
                        'action' => 'collision_add',
                        'reason' => "Collision detected, added {$addGuideline}",
                        'detect_terms' => $detectTerms,
                    ];
                }
            }
        }

        return [$keys, $decisions];
    }

    /**
     * Apply COMPANION rules (add but never #1).
     */
    protected function applyCompanions(array $keys, array $evaluations): array
    {
        $decisions = [];
        $companions = array_filter($evaluations, fn($e) => $e['action'] === 'companion');

        foreach ($companions as $ruleName => $comp) {
            $target = $comp['target_guideline'];

            if (!in_array($target, $keys)) {
                // Add at end (never #1)
                $keys[] = $target;

                $decisions[] = [
                    'rule' => $ruleName,
                    'action' => 'companion',
                    'reason' => "Added {$target} as companion",
                    'keywords_matched' => $comp['keywords_matched'],
                ];
            }
        }

        return [$keys, $decisions];
    }

    /**
     * Keep top-2 if score gap is small.
     */
    protected function applyScoreGap(array $keys, array $scores): array
    {
        $decisions = [];

        if (count($keys) < 2 || empty($scores)) {
            return [$keys, $decisions];
        }

        $top1 = $keys[0] ?? null;
        $top2 = $keys[1] ?? null;

        if (!$top1 || !$top2) {
            return [$keys, $decisions];
        }

        $score1 = $scores[$top1] ?? 0;
        $score2 = $scores[$top2] ?? 0;
        $gap = abs($score1 - $score2);

        if ($gap < $this->scoreGapThreshold) {
            $decisions[] = [
                'rule' => 'score_gap',
                'action' => 'keep_close_candidate',
                'reason' => "Score gap {$gap} < {$this->scoreGapThreshold}, keeping top-2",
                'scores' => [$top1 => $score1, $top2 => $score2],
            ];
        }

        return [$keys, $decisions];
    }
}
