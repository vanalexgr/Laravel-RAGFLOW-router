<?php

namespace App\Tools;

use Illuminate\Support\Facades\Log;
use Vizra\VizraADK\Contracts\ToolInterface;
use Vizra\VizraADK\Memory\AgentMemory;
use Vizra\VizraADK\System\AgentContext;

class SelectGuidelinesTool implements ToolInterface
{
    /**
     * ADDITIVE hard rules: these force-include guidelines but DO NOT exclude others.
     * Each rule adds a high boost to the forced guideline(s).
     */
    protected const FORCE_INCLUDE_RULES = [
        'carotid' => [
            'triggers' => ['carotid', 'cea', 'cas ', 'tcar', 'carotid endarterectomy', 'carotid stenting', 'carotid stenosis'],
            'force_keys' => ['carotid_vertebral'],
            'boost' => 50,
        ],
        'aaa' => [
            'triggers' => ['abdominal aortic aneurysm', 'aaa', 'evar', 'endoleak', 'aortic aneurysm screening'],
            'force_keys' => ['abdominal_aortic_aneurysm'],
            'boost' => 50,
        ],
        'trauma' => [
            'triggers' => ['trauma', 'reboa', 'mangled extremity', 'vascular injury', 'hemorrhage control', 'penetrating', 'blunt vascular'],
            'force_keys' => ['vascular_trauma'],
            'boost' => 50,
        ],
        'pad' => [
            'triggers' => ['peripheral arterial disease', 'pad', 'asymptomatic pad', 'abi screening', 'ankle brachial index', 'claudication', 'lead', 'lower extremity arterial', 'intermittent claudication', 'walking distance'],
            'force_keys' => ['asymptomatic_pad'],
            'boost' => 50,
        ],
        'clti' => [
            'triggers' => ['critical limb', 'clti', 'cli', 'rest pain', 'tissue loss', 'gangrene', 'limb salvage', 'wiwi', 'angiosome'],
            'force_keys' => ['clti'],
            'boost' => 50,
        ],
        'dvt' => [
            'triggers' => ['dvt', 'deep vein thrombosis', 'pulmonary embolism', 'pe ', 'ivc filter', 'post-thrombotic'],
            'force_keys' => ['venous_thrombosis'],
            'boost' => 50,
        ],
        'thoracic' => [
            'triggers' => ['type b dissection', 'tbad', 'tevar', 'thoracic aorta', 'descending aorta', 'intramural hematoma'],
            'force_keys' => ['descending_thoracic_aorta'],
            'boost' => 50,
        ],
    ];

    protected const MIN_SCORE_THRESHOLD = 5;
    protected const MIN_SCORE_THRESHOLD_MULTI_INTENT = 25; // Higher threshold when ≥2 forced keys present
    protected const MAX_GUIDELINES = 3;

    public function definition(): array
    {
        return [
            'name' => 'select_guidelines',
            'description' => 'FIRST STEP: Analyze the clinical question and select 1-3 most relevant ESVS guideline datasets. Returns guideline KEYS to use with consult_guideline tool. Handles multi-intent queries (e.g., "AAA AND PAD screening").',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'question' => [
                        'type' => 'string',
                        'description' => 'The complete clinical question to analyze for guideline selection',
                    ],
                ],
                'required' => ['question'],
            ],
        ];
    }

    public function execute(array $arguments, AgentContext $context, AgentMemory $memory): string
    {
        $question = $arguments['question'] ?? '';

        if (empty($question)) {
            return json_encode(['error' => 'A question is required to select guidelines.']);
        }

        $questionLower = strtolower($question);
        $categories = config('guidelines.categories', []);
        $registry = $this->buildGuidelineRegistry($categories);

        // Step 1: Detect forced guidelines (additive - can be multiple)
        $forcedKeys = $this->detectForcedGuidelines($questionLower);
        
        // Step 2: Score ALL guidelines normally
        $candidates = [];
        foreach ($categories as $categoryKey => $category) {
            foreach ($category['guidelines'] as $guidelineKey => $guideline) {
                $score = $this->calculateRelevanceScore($questionLower, $guideline['key_concepts']);
                $matchedConcepts = $this->getMatchedConcepts($questionLower, $guideline['key_concepts']);
                
                $candidates[$guidelineKey] = [
                    'key' => $guidelineKey,
                    'id' => $guideline['id'],
                    'name' => $guideline['name'],
                    'category' => $category['name'],
                    'base_score' => $score,
                    'score' => $score,
                    'matched_concepts' => $matchedConcepts,
                    'is_forced' => false,
                ];
            }
        }

        // Step 3: Apply force-include boosts (ADDITIVE, not exclusive)
        foreach ($forcedKeys as $forcedKey => $boost) {
            if (isset($candidates[$forcedKey])) {
                $candidates[$forcedKey]['score'] += $boost;
                $candidates[$forcedKey]['is_forced'] = true;
            }
        }

        // Step 4: Sort by score descending
        uasort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);

        // Step 5: Select top N, but ensure all forced keys are included
        $selected = [];
        $forcedIncluded = [];
        
        // First, add forced keys (they have highest priority)
        foreach ($candidates as $key => $candidate) {
            if ($candidate['is_forced'] && $candidate['score'] >= self::MIN_SCORE_THRESHOLD) {
                $selected[$key] = $candidate;
                $forcedIncluded[$key] = true;
            }
        }
        
        // Then add top scorers up to limit
        // When ≥2 forced keys are present, use higher threshold for non-forced extras
        $extraThreshold = count($forcedKeys) >= 2 
            ? self::MIN_SCORE_THRESHOLD_MULTI_INTENT 
            : self::MIN_SCORE_THRESHOLD;
        
        foreach ($candidates as $key => $candidate) {
            if (count($selected) >= self::MAX_GUIDELINES) {
                break;
            }
            if (!isset($selected[$key]) && $candidate['score'] >= $extraThreshold) {
                $selected[$key] = $candidate;
            }
        }

        // Build observability data
        $top5Candidates = array_slice($candidates, 0, 5, true);
        $candidateScoresTop5 = array_map(fn($c) => [
            'key' => $c['key'],
            'score' => $c['score'],
            'base_score' => $c['base_score'],
            'forced' => $c['is_forced'],
            'matched_concepts' => array_slice($c['matched_concepts'], 0, 5), // Cap at 5 for log size
        ], $top5Candidates);

        // Log comprehensive debug info
        Log::info("SelectGuidelinesTool: Analysis complete", [
            'question' => $question,
            'detected_intents' => count($forcedKeys) > 1 ? 'MULTI-INTENT' : 'SINGLE-INTENT',
            'forced_keys' => array_keys($forcedKeys),
            'candidate_scores_top5' => $candidateScoresTop5,
            'selected_count' => count($selected),
            'selected_keys' => array_keys($selected),
            'registry_keys' => array_keys($registry),
        ]);

        if (empty($selected)) {
            return json_encode([
                'selected_guidelines' => [],
                'guideline_keys' => [],
                'message' => 'No specific guideline match found. Use general vascular surgery knowledge or ask for clarification.',
                'available_categories' => array_map(fn($c) => $c['name'], $categories),
                'debug' => [
                    'forced_keys' => array_keys($forcedKeys),
                    'top5_scores' => $candidateScoresTop5,
                ],
            ]);
        }

        $guidelineKeys = array_keys($selected);

        // Format output
        $isMultiIntent = count($forcedKeys) > 1;
        $output = "SELECTED GUIDELINES FOR RETRIEVAL";
        if ($isMultiIntent) {
            $output .= " [MULTI-INTENT QUERY DETECTED]";
        }
        $output .= "\n" . str_repeat("=", 60) . "\n\n";

        $num = 1;
        foreach ($selected as $key => $match) {
            $forcedTag = $match['is_forced'] ? ' [FORCED by hard rule]' : '';
            $output .= "{$num}. {$match['name']} ({$match['category']}){$forcedTag}\n";
            $output .= "   Guideline Key: {$match['key']}\n";
            $output .= "   Score: {$match['score']} (base: {$match['base_score']})\n";
            if (!empty($match['matched_concepts'])) {
                $output .= "   Matched Concepts: " . implode(', ', $match['matched_concepts']) . "\n";
            }
            $output .= "\n";
            $num++;
        }

        // Debug section
        $output .= "DEBUG INFO:\n";
        $output .= "  Forced Keys: " . (empty($forcedKeys) ? 'none' : implode(', ', array_keys($forcedKeys))) . "\n";
        $output .= "  Top 5 Candidates:\n";
        foreach ($candidateScoresTop5 as $c) {
            $fTag = $c['forced'] ? ' [F]' : '';
            $output .= "    - {$c['key']}: {$c['score']} (base: {$c['base_score']}){$fTag}\n";
        }

        $output .= "\nGUIDELINE_KEYS: " . json_encode($guidelineKeys) . "\n";
        $output .= "\nNEXT STEP: Use these guideline_keys with consult_guideline tool to retrieve relevant content.";

        return $output;
    }

    /**
     * Detect which guidelines should be force-included based on trigger words.
     * Returns array of [guideline_key => boost_value]
     */
    protected function detectForcedGuidelines(string $question): array
    {
        $forced = [];

        foreach (self::FORCE_INCLUDE_RULES as $ruleName => $rule) {
            foreach ($rule['triggers'] as $trigger) {
                // Use word boundary for short triggers to avoid false matches
                if (strlen($trigger) <= 4) {
                    // Short trigger - use word boundary regex
                    if (preg_match('/\b' . preg_quote($trigger, '/') . '\b/i', $question)) {
                        foreach ($rule['force_keys'] as $key) {
                            $forced[$key] = $rule['boost'];
                        }
                        break; // Found match for this rule, move to next rule
                    }
                } else {
                    // Longer trigger - simple contains
                    if (str_contains($question, $trigger)) {
                        foreach ($rule['force_keys'] as $key) {
                            $forced[$key] = $rule['boost'];
                        }
                        break;
                    }
                }
            }
        }

        return $forced;
    }

    protected function buildGuidelineRegistry(array $categories): array
    {
        $registry = [];
        foreach ($categories as $category) {
            foreach ($category['guidelines'] as $key => $guideline) {
                $registry[$key] = $guideline['id'];
            }
        }
        return $registry;
    }

    protected function calculateRelevanceScore(string $question, array $concepts): int
    {
        $score = 0;
        foreach ($concepts as $concept) {
            $conceptLower = strtolower($concept);
            
            // Exact phrase match
            if (str_contains($question, $conceptLower)) {
                $score += 10;
                continue;
            }
            
            // Word-by-word partial match
            $words = explode(' ', $conceptLower);
            $wordMatches = 0;
            foreach ($words as $word) {
                if (strlen($word) > 2 && str_contains($question, $word)) {
                    $wordMatches++;
                }
            }
            
            if ($wordMatches > 0 && $wordMatches >= count($words) * 0.5) {
                $score += 5;
            } elseif ($wordMatches > 0) {
                $score += 2;
            }
        }
        return $score;
    }

    protected function getMatchedConcepts(string $question, array $concepts): array
    {
        $matched = [];
        foreach ($concepts as $concept) {
            $conceptLower = strtolower($concept);
            if (str_contains($question, $conceptLower)) {
                $matched[] = $concept;
                continue;
            }
            $words = explode(' ', $conceptLower);
            $wordMatches = 0;
            foreach ($words as $word) {
                if (strlen($word) > 2 && str_contains($question, $word)) {
                    $wordMatches++;
                }
            }
            if ($wordMatches > 0 && $wordMatches >= count($words) * 0.5) {
                $matched[] = $concept;
            }
        }
        return array_unique($matched);
    }
}
