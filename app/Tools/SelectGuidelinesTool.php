<?php

namespace App\Tools;

use Illuminate\Support\Facades\Log;
use Vizra\VizraADK\Contracts\ToolInterface;
use Vizra\VizraADK\Memory\AgentMemory;
use Vizra\VizraADK\System\AgentContext;

class SelectGuidelinesTool implements ToolInterface
{
    public function definition(): array
    {
        return [
            'name' => 'select_guidelines',
            'description' => 'FIRST STEP: Analyze the clinical question and select 1-3 most relevant ESVS guideline datasets. Returns dataset IDs to use with consult_guideline tool. Always call this before consulting guidelines.',
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
        $matches = [];

        foreach ($categories as $categoryKey => $category) {
            foreach ($category['guidelines'] as $guidelineKey => $guideline) {
                $score = $this->calculateRelevanceScore($questionLower, $guideline['key_concepts']);
                if ($score > 0) {
                    $matches[] = [
                        'id' => $guideline['id'],
                        'name' => $guideline['name'],
                        'category' => $category['name'],
                        'score' => $score,
                        'matched_concepts' => $this->getMatchedConcepts($questionLower, $guideline['key_concepts']),
                    ];
                }
            }
        }

        usort($matches, fn($a, $b) => $b['score'] <=> $a['score']);

        $selected = array_slice($matches, 0, 3);

        if (empty($selected)) {
            Log::warning("SelectGuidelinesTool: No matching guidelines for question", ['question' => $question]);
            return json_encode([
                'selected_guidelines' => [],
                'dataset_ids' => [],
                'message' => 'No specific guideline match found. Use general vascular surgery knowledge or ask for clarification.',
                'available_categories' => array_map(fn($c) => $c['name'], $categories),
            ]);
        }

        $datasetIds = array_column($selected, 'id');

        Log::info("SelectGuidelinesTool: Selected guidelines", [
            'question' => $question,
            'selected' => array_map(fn($s) => $s['name'], $selected),
            'dataset_ids' => $datasetIds,
        ]);

        $output = "SELECTED GUIDELINES FOR RETRIEVAL:\n";
        $output .= str_repeat("=", 50) . "\n\n";

        foreach ($selected as $i => $match) {
            $num = $i + 1;
            $output .= "{$num}. {$match['name']} ({$match['category']})\n";
            $output .= "   Dataset ID: {$match['id']}\n";
            $output .= "   Relevance Score: {$match['score']}\n";
            $output .= "   Matched Concepts: " . implode(', ', $match['matched_concepts']) . "\n\n";
        }

        $output .= "\nDATASET_IDS: " . json_encode($datasetIds) . "\n";
        $output .= "\nNEXT STEP: Use these dataset_ids with consult_guideline tool to retrieve relevant content.";

        return $output;
    }

    protected function calculateRelevanceScore(string $question, array $concepts): int
    {
        $score = 0;
        foreach ($concepts as $concept) {
            $conceptLower = strtolower($concept);
            $words = explode(' ', $conceptLower);
            
            if (str_contains($question, $conceptLower)) {
                $score += 10;
            }
            
            foreach ($words as $word) {
                if (strlen($word) > 2 && str_contains($question, $word)) {
                    $score += 3;
                }
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
            foreach ($words as $word) {
                if (strlen($word) > 2 && str_contains($question, $word)) {
                    $matched[] = $concept;
                    break;
                }
            }
        }
        return array_unique($matched);
    }
}
