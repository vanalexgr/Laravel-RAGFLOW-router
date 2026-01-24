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

    public function __construct()
    {
        $this->config = config('router_abbreviations.guardrails', []);
    }

    /**
     * Apply guardrails to routing candidates.
     *
     * @param string $query Original user query
     * @param array $candidates Route result with 'keys' and 'scores'
     * @return GuardrailResult
     */
    public function apply(string $query, array $candidates): GuardrailResult
    {
        $enabled = config('router_abbreviations.guard rails_enabled', true);

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

        $rulesEvaluated = [];
        $rulesTriggered = [];
        $decisions = [];

        // Evaluate infection trigger rule
        if ($this->isRuleEnabled('infection_trigger')) {
            $rulesEvaluated[] = 'infection_trigger';

            $infectionResult = $this->evaluateInfectionTrigger($query, $keys, $scores);

            if ($infectionResult['triggered']) {
                $rulesTriggered[] = 'infection_trigger';
                $decisions[] = $infectionResult['decision'];
                $keys = $infectionResult['modified_keys'];
            }
        }

        // Log guardrail decisions
        if (!empty($rulesTriggered)) {
            Log::channel('retrieval')->info('[GUARDRAILS] Rules triggered', [
                'triggered' => $rulesTriggered,
                'decisions' => $decisions,
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
     * Evaluate infection trigger guardrail.
     */
    protected function evaluateInfectionTrigger(string $query, array $keys, array $scores): array
    {
        $rule = $this->config['infection_trigger'];
        $matchedKeywords = $this->detectInfectionKeywords($query, $rule);

        if (empty($matchedKeywords)) {
            return ['triggered' => false];
        }

        $targetGuideline = $rule['target_guideline'];
        $action = $rule['action'];
        $minScore = $rule['min_score_threshold'] ?? 0.3;

        // Check if VGEI is already in candidates
        $vgeiInCandidates = in_array($targetGuideline, $keys);
        $vgeiScore = $scores[$targetGuideline] ?? 0;

        // Decision logic
        if ($vgeiInCandidates && $action === 'prefer') {
            // Move VGEI to top
            $keys = array_diff($keys, [$targetGuideline]);
            array_unshift($keys, $targetGuideline);

            return [
                'triggered' => true,
                'modified_keys' => $keys,
                'decision' => [
                    'rule' => 'infection_trigger',
                    'action' => 'prefer',
                    'reason' => 'Infection markers detected, moved VGEI to top',
                    'keywords_matched' => $matchedKeywords,
                    'match_count' => count($matchedKeywords),
                ],
            ];
        }

        if (!$vgeiInCandidates && $action === 'force_add' && $vgeiScore >= $minScore) {
            // Add VGEI to candidates
            array_unshift($keys, $targetGuideline);

            return [
                'triggered' => true,
                'modified_keys' => $keys,
                'decision' => [
                    'rule' => 'infection_trigger',
                    'action' => 'force_add',
                    'reason' => "Infection markers detected, added VGEI (score: {$vgeiScore})",
                    'keywords_matched' => $matchedKeywords,
                    'match_count' => count($matchedKeywords),
                ],
            ];
        }

        // Infection keywords found but action not taken
        return [
            'triggered' => true,
            'modified_keys' => $keys,
            'decision' => [
                'rule' => 'infection_trigger',
                'action' => 'no_action',
                'reason' => 'Infection markers detected but conditions not met for action',
                'keywords_matched' => $matchedKeywords,
                'match_count' => count($matchedKeywords),
                'vgei_in_candidates' => $vgeiInCandidates,
                'vgei_score' => $vgeiScore,
            ],
        ];
    }

    /**
     * Detect infection keywords in query.
     */
    protected function detectInfectionKeywords(string $query, array $rule): array
    {
        $keywords = $rule['keywords'] ?? [];
        $matchConfig = $rule['match_config'] ?? [];
        $minMatches = $matchConfig['min_keyword_matches'] ?? 2;
        $caseInsensitive = $matchConfig['case_insensitive'] ?? true;
        $wordBoundary = $matchConfig['word_boundary'] ?? true;

        $matched = [];
        $queryLower = strtolower($query);

        foreach ($keywords as $category => $terms) {
            foreach ($terms as $term) {
                $searchTerm = $caseInsensitive ? strtolower($term) : $term;
                $searchQuery = $caseInsensitive ? $queryLower : $query;

                if ($wordBoundary) {
                    // Match whole words only
                    $pattern = '/\b' . preg_quote($searchTerm, '/') . '\b/i';
                    if (preg_match($pattern, $searchQuery)) {
                        $matched[] = $term;
                    }
                } else {
                    // Simple substring match
                    if (str_contains($searchQuery, $searchTerm)) {
                        $matched[] = $term;
                    }
                }
            }
        }

        // Only return matches if minimum threshold met
        return count($matched) >= $minMatches ? array_unique($matched) : [];
    }

    /**
     * Check if a guardrail rule is enabled.
     */
    protected function isRuleEnabled(string $ruleName): bool
    {
        return $this->config[$ruleName]['enabled'] ?? false;
    }
}
