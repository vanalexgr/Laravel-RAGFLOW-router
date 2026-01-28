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
    protected float $score_gap_threshold;

    public function __construct()
    {
        $this->config = config('router_abbreviations.guardrails', []);
        $this->priorityOrder = config('router_abbreviations.priority_order', []);
        $this->score_gap_threshold = config('router_abbreviations.score_gap_threshold', 0.05);
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

        // 2b. Apply Explicit Exclusions (triggered by negative keywords)
        [$keys, $explicitDecisions] = $this->applyExplicitExclusions($keys, $evaluations);
        $decisions = array_merge($decisions, $explicitDecisions);

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
        // Track triggered keys to avoid keeping semantic noise
        $triggeredKeys = [];
        foreach ($evaluations as $eval) {
            if ($eval['match_count'] > 0) {
                $triggeredKeys[] = $eval['target_guideline'];
            }
        }

        $isPinActive = count($pinDecisions) > 0;
        [$keys, $gapDecisions] = $this->applyScoreGap($keys, $scores, $triggeredKeys, $isPinActive);
        $decisions = array_merge($decisions, $gapDecisions);

        // 7. Limit to reasonable number
        $keys = array_slice($keys, 0, 3);

        $rulesEvaluated = array_keys($evaluations);
        $rulesTriggered = array_map(fn($e) => $e['rule_name'], $evaluations);

        // Log guardrail decisions
        if (!empty($decisions)) {
            $log = Log::channel('retrieval');
            $log->info('[GUARDRAILS] Decision Trace:', [
                'query' => substr($query, 0, 80),
                'initial_candidates' => $candidates['keys'],
                'final_selected' => $keys,
                'is_pin_active' => $isPinActive ? 'YES' : 'NO'
            ]);
            foreach ($decisions as $d) {
                $log->info("  - Decision: [{$d['rule']}] -> {$d['action']} ({$d['reason']})");
            }
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

            // Phase 4: Load additional keywords from external file
            $fileData = $this->loadKeywordsFromFile($ruleName);
            if (!empty($fileData)) {
                // Merge file keywords into config keywords (preserving categories/tiers)
                $rule['pin_keywords'] = array_merge(
                    $rule['pin_keywords'] ?? [],
                    $fileData['keywords'] ?? []
                );

                // Merge exclude keywords
                if (!empty($fileData['exclude'])) {
                    $rule['exclude_keywords'] = array_merge(
                        $rule['exclude_keywords'] ?? [],
                        $fileData['exclude']
                    );
                }
            }

            // Perform matching
            $matchResult = $this->matchKeywords($query, $rule);

            // Check exclusions
            $excluded = false;
            $excludeReason = '';

            if (!empty($rule['exclude_keywords'])) {
                $exclusionCheck = $this->matchKeywords($query, [
                    'match_config' => $rule['match_config'] ?? [],
                    'pin_keywords' => ['exclusions' => $rule['exclude_keywords']]
                ]);

                if ($exclusionCheck['keywords']) {
                    $excluded = true;
                    $excludeReason = "Excluded by keyword: " . implode(', ', $exclusionCheck['keywords']);
                }
            }

            // FIX: Always evaluate exclude_by_default rules (Trauma)
            $isExcludeRule = ($rule['action'] ?? '') === 'exclude_by_default';

            // LOGIC:
            // 1. If excluded -> Trigger fails. 
            // 2. If exclude_by_default -> Always added to evaluations, but 'action' depends on match.
            // 3. If regular rule and excluded -> Should likely perform 'exclude' action on target.

            if ($matchResult['triggered'] || $isExcludeRule || $excluded) {

                // Determine action overrides
                $finalAction = $rule['action'] ?? 'pin';

                if ($excluded) {
                    $finalAction = 'exclude'; // Force exclusion if exclusion keywords match
                }

                $evaluations[$ruleName] = [
                    'rule_name' => $ruleName,
                    'priority' => $priority,
                    'action' => $finalAction,
                    'target_guideline' => $rule['target_guideline'] ?? $ruleName,
                    'keywords_matched' => $matchResult['keywords'],
                    'match_count' => count($matchResult['keywords']),
                    'collision_rules' => $rule['collision_rules'] ?? [],
                    'exclude_keywords' => $rule['exclude_keywords'] ?? [],
                    'exclude_reason' => $excludeReason // Pass reason
                ];
            }
        }

        return $evaluations;
    }

    /**
     * Load keywords from JSON file in storage/keywords/
     */
    protected function loadKeywordsFromFile(string $ruleName): array
    {
        $path = storage_path("keywords/{$ruleName}.json");

        if (!file_exists($path)) {
            return [];
        }

        $content = file_get_contents($path);
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning("[GUARDRAILS] Invalid JSON for {$ruleName}: " . json_last_error_msg());
            return [];
        }

        return [
            'keywords' => $data['keywords'] ?? [], // Returns array of categories (tiers)
            'exclude' => $data['exclude_keywords'] ?? []
        ];
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
                    $keys = array_values(array_diff($keys, [$target]));  // FIX: array_values to reindex
                    $decisions[] = [
                        'rule' => $ruleName,
                        'action' => 'exclude',
                        'reason' => "No trauma mechanism, EXCLUDED {$target}",
                    ];
                }
            }
        }

        return [$keys, $decisions];
    }

    /**
     * Apply explicit exclusions (neutralized rules).
     */
    protected function applyExplicitExclusions(array $keys, array $evaluations): array
    {
        $decisions = [];

        foreach ($evaluations as $ruleName => $eval) {
            if ($eval['action'] === 'exclude') {
                $target = $eval['target_guideline'];

                if (in_array($target, $keys)) {
                    $keys = array_values(array_diff($keys, [$target]));
                    $decisions[] = [
                        'rule' => $ruleName,
                        'action' => 'explicit_exclude',
                        'reason' => $eval['exclude_reason'] ?? "Explicitly excluded by guardrail",
                    ];
                }
            }
        }

        return [$keys, $decisions];
    }

    /**
     * Apply PIN rules (must be at the top if triggered).
     * Supports multiple pins for hybrid queries.
     */
    protected function applyPins(array $keys, array $evaluations): array
    {
        $decisions = [];

        // Identify all guidelines that should be pinned
        $pins = array_filter($evaluations, function ($e) {
            return $e['action'] === 'pin' || ($e['action'] === 'exclude_by_default' && $e['match_count'] > 0);
        });

        if (empty($pins)) {
            return [$keys, $decisions];
        }

        // Sort pins by priority
        uasort($pins, fn($a, $b) => $a['priority'] <=> $b['priority']);

        $pinnedTargets = [];
        foreach ($pins as $ruleName => $eval) {
            $pinnedTargets[] = $eval['target_guideline'];
        }

        // Remove pinned targets from original keys and prepend them in priority order
        $otherKeys = array_diff($keys, $pinnedTargets);
        $keys = array_merge($pinnedTargets, $otherKeys);

        foreach ($pins as $ruleName => $eval) {
            $decisions[] = [
                'rule' => $ruleName,
                'action' => 'pin',
                'reason' => "Priority pin for {$eval['target_guideline']}",
                'keywords_matched' => $eval['keywords_matched'],
            ];
        }

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

                // FIX: Check detection terms against query AND matched keywords
                $queryLower = strtolower($query);
                $matchedKeywords = array_map('strtolower', $eval['keywords_matched'] ?? []);

                $allPresent = true;
                foreach ($detectTerms as $term) {
                    $termLower = strtolower($term);

                    // Check in query OR in matched keywords
                    $foundInQuery = str_contains($queryLower, $termLower);
                    $foundInKeywords = in_array($termLower, $matchedKeywords);

                    // Special handling for category names (e.g., 'infection_markers')
                    $categoryMatch = false;
                    if (!$foundInQuery && !$foundInKeywords) {
                        // Check if term is a category that was matched
                        foreach ($matchedKeywords as $keyword) {
                            if (str_contains($keyword, $termLower) || str_contains($termLower, $keyword)) {
                                $categoryMatch = true;
                                break;
                            }
                        }
                    }

                    if (!$foundInQuery && !$foundInKeywords && !$categoryMatch) {
                        $allPresent = false;
                        break;
                    }
                }

                if ($allPresent && !in_array($addGuideline, $keys)) {
                    // Add companion guideline at position 1 (after PIN)
                    array_splice($keys, 1, 0, [$addGuideline]);

                    $decisions[] = [
                        'rule' => $ruleName,
                        'action' => 'collision_add',
                        'reason' => "Collision detected, added {$addGuideline} at position 2",
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
            } else {
                // FIX: Log even if already present (for clarity)
                $decisions[] = [
                    'rule' => $ruleName,
                    'action' => 'companion_already_present',
                    'reason' => "{$target} already in results (companion rule matched)",
                    'keywords_matched' => $comp['keywords_matched'],
                ];
            }
        }

        return [$keys, $decisions];
    }

    /**
     * Keep top-2 if score gap is small.
     * 
     * ENHANCED: If a PIN is active, be much stricter with non-triggered candidates.
     */
    protected function applyScoreGap(array $keys, array $scores, array $triggeredKeys = [], bool $isPinActive = false): array
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

        $threshold = $this->score_gap_threshold;

        // If the 2nd key was NOT triggered by any keyword rule, but the 1st was a PIN,
        // we should tighten the threshold significantly (semantic noise protection)
        $isTop2Triggered = in_array($top2, $triggeredKeys);

        if ($isPinActive && !$isTop2Triggered) {
            $decisions[] = [
                'rule' => 'score_gap',
                'action' => 'exclude_noise',
                'reason' => "Direct keyword match (PIN) for {$top1}, dropping non-matched sibling {$top2} for absolute isolation",
            ];
            return [array_slice($keys, 0, 1), $decisions];
        }

        if ($gap < $threshold) {
            $decisions[] = [
                'rule' => 'score_gap',
                'action' => 'keep_close_candidate',
                'reason' => "Score gap {$gap} < {$threshold}, keeping top-2",
                'scores' => [$top1 => $score1, $top2 => $score2],
            ];
            // We keep both, but we should still consider dropping index 2+ if they are too far
            $keys = array_slice($keys, 0, 2);
        } else {
            // EXCEPTION: Never drop top2 if it was explicitly triggered by a keyword rule
            if ($isTop2Triggered) {
                $decisions[] = [
                    'rule' => 'score_gap',
                    'action' => 'keep_matched_candidate',
                    'reason' => "Score gap {$gap} >= {$threshold} but keeping {$top2} because it had a keyword match",
                ];
                // Keep only these two
                return [array_slice($keys, 0, 2), $decisions];
            }

            // Drop top2 and everything else
            $keys = array_slice($keys, 0, 1);
            $decisions[] = [
                'rule' => 'score_gap',
                'action' => 'exclude_noise',
                'reason' => "Score gap {$gap} >= {$threshold}, dropping non-matched candidates",
            ];
        }

        return [$keys, $decisions];
    }
}
