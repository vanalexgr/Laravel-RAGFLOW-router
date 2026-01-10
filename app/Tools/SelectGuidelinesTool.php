<?php

namespace App\Tools;

use Illuminate\Support\Facades\Log;
use Vizra\VizraADK\Contracts\ToolInterface;
use Vizra\VizraADK\Memory\AgentMemory;
use Vizra\VizraADK\System\AgentContext;

class SelectGuidelinesTool implements ToolInterface
{
    protected const HARD_RULES = [
        'carotid_exclusive' => [
            'triggers' => ['carotid endarterectomy', 'cea', 'carotid stenosis', 'carotid stenting', 'cas', 'tcar', 'tia', 'stroke prevention', 'symptomatic stenosis', 'asymptomatic stenosis'],
            'required' => 'carotid_vertebral',
            'exclude_unless_explicit' => ['venous_thrombosis', 'chronic_venous_disease', 'descending_thoracic_aorta', 'abdominal_aortic_aneurysm'],
        ],
        'aaa_exclusive' => [
            'triggers' => ['aaa', 'abdominal aortic aneurysm', 'evar', 'open repair aortic', 'endoleak'],
            'required' => 'abdominal_aortic_aneurysm',
            'exclude_unless_explicit' => ['carotid_vertebral', 'venous_thrombosis'],
        ],
        'trauma_exclusive' => [
            'triggers' => ['trauma', 'injury', 'reboa', 'mangled extremity', 'hemorrhage control', 'penetrating', 'blunt vascular'],
            'required' => 'vascular_trauma',
            'exclude_unless_explicit' => [],
        ],
    ];

    protected const MIN_SCORE_THRESHOLD = 6;
    protected const HIGH_CONFIDENCE_THRESHOLD = 15;

    public function definition(): array
    {
        return [
            'name' => 'select_guidelines',
            'description' => 'FIRST STEP: Analyze the clinical question and select 1-2 most relevant ESVS guideline datasets. Returns guideline KEYS to use with consult_guideline tool. Be conservative - only select guidelines that are clearly relevant.',
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
        
        $hardRuleResult = $this->applyHardRules($questionLower);
        $requiredGuideline = $hardRuleResult['required'];
        $excludedGuidelines = $hardRuleResult['excluded'];

        $matches = [];
        $guidelineRegistry = $this->buildGuidelineRegistry($categories);

        foreach ($categories as $categoryKey => $category) {
            foreach ($category['guidelines'] as $guidelineKey => $guideline) {
                if (in_array($guidelineKey, $excludedGuidelines)) {
                    continue;
                }

                $score = $this->calculateRelevanceScore($questionLower, $guideline['key_concepts']);
                
                if ($guidelineKey === $requiredGuideline) {
                    $score += 50;
                }

                if ($score >= self::MIN_SCORE_THRESHOLD) {
                    $matches[] = [
                        'key' => $guidelineKey,
                        'id' => $guideline['id'],
                        'name' => $guideline['name'],
                        'category' => $category['name'],
                        'score' => $score,
                        'matched_concepts' => $this->getMatchedConcepts($questionLower, $guideline['key_concepts']),
                        'is_required' => $guidelineKey === $requiredGuideline,
                    ];
                }
            }
        }

        usort($matches, fn($a, $b) => $b['score'] <=> $a['score']);

        $maxGuidelines = 2;
        if (!empty($matches) && $matches[0]['score'] >= self::HIGH_CONFIDENCE_THRESHOLD) {
            $maxGuidelines = 1;
            if (count($matches) > 1 && $matches[1]['score'] >= self::HIGH_CONFIDENCE_THRESHOLD * 0.7) {
                $maxGuidelines = 2;
            }
        }

        $selected = array_slice($matches, 0, $maxGuidelines);

        if (empty($selected)) {
            Log::warning("SelectGuidelinesTool: No matching guidelines for question", ['question' => $question]);
            return json_encode([
                'selected_guidelines' => [],
                'guideline_keys' => [],
                'message' => 'No specific guideline match found. Use general vascular surgery knowledge or ask for clarification.',
                'available_categories' => array_map(fn($c) => $c['name'], $categories),
            ]);
        }

        $guidelineKeys = array_column($selected, 'key');

        Log::info("SelectGuidelinesTool: Selected guidelines", [
            'question' => $question,
            'selected' => array_map(fn($s) => ['key' => $s['key'], 'name' => $s['name'], 'score' => $s['score']], $selected),
            'hard_rule_applied' => $requiredGuideline ?? 'none',
            'excluded_by_hard_rule' => $excludedGuidelines,
        ]);

        $output = "SELECTED GUIDELINES FOR RETRIEVAL:\n";
        $output .= str_repeat("=", 50) . "\n\n";

        foreach ($selected as $i => $match) {
            $num = $i + 1;
            $requiredTag = $match['is_required'] ? ' [REQUIRED BY HARD RULE]' : '';
            $output .= "{$num}. {$match['name']} ({$match['category']}){$requiredTag}\n";
            $output .= "   Guideline Key: {$match['key']}\n";
            $output .= "   Relevance Score: {$match['score']}\n";
            $output .= "   Matched Concepts: " . implode(', ', $match['matched_concepts']) . "\n\n";
        }

        $output .= "\nGUIDELINE_KEYS: " . json_encode($guidelineKeys) . "\n";
        $output .= "\nNEXT STEP: Use these guideline_keys with consult_guideline tool to retrieve relevant content.";

        return $output;
    }

    protected function applyHardRules(string $question): array
    {
        foreach (self::HARD_RULES as $ruleName => $rule) {
            foreach ($rule['triggers'] as $trigger) {
                if (str_contains($question, $trigger)) {
                    return [
                        'required' => $rule['required'],
                        'excluded' => $rule['exclude_unless_explicit'],
                    ];
                }
            }
        }

        return ['required' => null, 'excluded' => []];
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
            
            if (str_contains($question, $conceptLower)) {
                $score += 10;
                continue;
            }
            
            $words = explode(' ', $conceptLower);
            $wordMatches = 0;
            foreach ($words as $word) {
                if (strlen($word) > 3 && str_contains($question, $word)) {
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
                if (strlen($word) > 3 && str_contains($question, $word)) {
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
