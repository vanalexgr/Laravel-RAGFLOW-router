<?php

namespace App\Tools;

use App\Facades\RAGFlow;
use Illuminate\Support\Facades\Log;
use Vizra\VizraADK\Contracts\ToolInterface;
use Vizra\VizraADK\Memory\AgentMemory;
use Vizra\VizraADK\System\AgentContext;

class CiteRecommendationsTool implements ToolInterface
{
    public function definition(): array
    {
        return [
            'name' => 'cite_recommendations',
            'description' => 'FINAL STEP: After synthesizing your answer, use this tool to retrieve exact recommendation citations from the structured recommendations dataset. Returns verbatim recommendation text, number, guideline name, class, and level of evidence. Use this to provide a "Hard Evidence" section.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'search_terms' => [
                        'type' => 'string',
                        'description' => 'Key clinical terms from your synthesized answer to find matching recommendations (e.g., "carotid endarterectomy symptomatic 14 days", "AAA 5.5cm repair threshold")',
                    ],
                ],
                'required' => ['search_terms'],
            ],
        ];
    }

    public function execute(array $arguments, AgentContext $context, AgentMemory $memory): string
    {
        $searchTerms = $arguments['search_terms'] ?? '';

        if (empty($searchTerms)) {
            return json_encode(['error' => 'Search terms are required to cite recommendations.']);
        }

        $recommendationsDatasetId = config('guidelines.recommendations_dataset', '4fff3622eb1b11f09021f2381272676b');
        $retrievalConfig = config('ragflow.retrieval', []);

        Log::info("CiteRecommendationsTool: Querying recommendations dataset", [
            'search_terms' => $searchTerms,
            'dataset_id' => $recommendationsDatasetId,
        ]);

        try {
            $retrievalParams = [
                'question' => $searchTerms,
                'top_k' => 1024,
                'size' => 5,
                'page' => 1,
                'similarity_threshold' => $retrievalConfig['similarity_threshold'] ?? 0.2,
                'keyword' => true,
                'vector_similarity_weight' => 0.3,
                'highlight' => true,
            ];

            if (!empty($retrievalConfig['rerank_id'])) {
                $retrievalParams['rerank_id'] = $retrievalConfig['rerank_id'];
            }

            Log::channel('ragflow')->info("CiteRecommendationsTool: Retrieval payload", [
                'dataset_ids' => [$recommendationsDatasetId],
                'params' => $retrievalParams,
            ]);

            $response = RAGFlow::datasets()->retrieve([$recommendationsDatasetId], $retrievalParams);

            Log::info("CiteRecommendationsTool: RAGFlow returned " . count($response['data']['chunks'] ?? []) . " chunks");

            if (!empty($response['data']['chunks'])) {
                return $this->formatCitations($response['data']['chunks']);
            }

            return "NO MATCHING RECOMMENDATIONS FOUND\n\nThe recommendations dataset did not return matches for: {$searchTerms}\n\nNote: The synthesized answer should still be based on the guideline content retrieved in the previous step.";

        } catch (\Exception $e) {
            Log::error('CiteRecommendationsTool failed: ' . $e->getMessage());
            return "CITATION RETRIEVAL ERROR\n\nUnable to retrieve exact citations: " . $e->getMessage() . "\n\nProceed with answer based on guideline content.";
        }
    }

    protected function formatCitations(array $chunks): string
    {
        $output = "HARD EVIDENCE - EXACT RECOMMENDATION CITATIONS\n";
        $output .= str_repeat("=", 60) . "\n\n";

        foreach ($chunks as $index => $chunk) {
            $num = $index + 1;
            $content = $chunk['content'] ?? $chunk['content_with_weight'] ?? '';

            if (empty($content)) {
                continue;
            }

            $citation = $this->extractCitation($chunk, $content);

            $output .= "CITATION {$num}:\n";
            $output .= str_repeat("-", 40) . "\n";
            $output .= "Guideline: {$citation['guideline']}\n";
            $output .= "Recommendation: {$citation['recommendation_id']}\n";
            $output .= "Class: {$citation['class']}\n";
            $output .= "Level: {$citation['level']}\n";
            if ($citation['territory'] !== 'Unknown') {
                $output .= "Territory: {$citation['territory']}\n";
            }
            $output .= "Similarity: {$citation['similarity']}%\n\n";
            $output .= "EXACT TEXT:\n\"{$citation['text']}\"\n\n";
        }

        $output .= str_repeat("=", 60) . "\n";
        $output .= "Use these exact citations in your Evidence section. Do NOT paraphrase recommendation numbers, class, or level.\n";

        return $output;
    }

    protected function extractCitation(array $chunk, string $content): array
    {
        $citation = [
            'guideline' => 'Unknown Guideline',
            'recommendation_id' => 'Unknown',
            'class' => 'Unknown',
            'level' => 'Unknown',
            'territory' => 'Unknown',
            'text' => $content,
            'similarity' => isset($chunk['similarity']) ? round($chunk['similarity'] * 100, 1) : 0,
        ];

        if (preg_match('/GUIDELINE[_\s]*(ID|NAME)?:\s*(.+?)(?=\n|RECOMMENDATION|CLASS|LEVEL|$)/i', $content, $m)) {
            $citation['guideline'] = trim($m[2]);
        }
        if (preg_match('/RECOMMENDATION[_\s]*(ID|NUMBER)?:\s*(Rec\.?\s*\d+[a-z]?)/i', $content, $m)) {
            $citation['recommendation_id'] = trim($m[2]);
        }
        if (preg_match('/CLASS:\s*(Class\s*[IVX]+[a-b]?|[IVX]+[a-b]?)/i', $content, $m)) {
            $citation['class'] = trim($m[1]);
        }
        if (preg_match('/LEVEL:\s*(Level\s*[A-C]|[A-C])/i', $content, $m)) {
            $citation['level'] = trim($m[1]);
        }
        if (preg_match('/TERRITORY:\s*(\S+)/i', $content, $m)) {
            $citation['territory'] = trim($m[1]);
        }

        if (preg_match('/(?:RECOMMENDATION[_\s]*TEXT|TEXT):\s*(.+?)(?=TRIPLES:|CLASS:|LEVEL:|GUIDELINE:|$)/is', $content, $m)) {
            $citation['text'] = trim($m[1]);
        } elseif (preg_match('/^(?!.*(?:GUIDELINE|RECOMMENDATION|CLASS|LEVEL|TERRITORY):)(.{50,})/m', $content, $m)) {
            $citation['text'] = trim($m[1]);
        }

        $docName = $chunk['document_keyword'] ?? $chunk['doc_name'] ?? '';
        if ($citation['guideline'] === 'Unknown Guideline' && !empty($docName)) {
            $citation['guideline'] = $docName;
        }

        return $citation;
    }
}
