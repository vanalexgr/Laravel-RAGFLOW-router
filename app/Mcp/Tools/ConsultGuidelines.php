<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tool;
use App\Services\RetrievalService;

class ConsultGuidelines extends Tool
{
    /**
     * Create a new tool instance.
     */
    public function __construct(
        protected RetrievalService $retrievalService
    ) {
    }

    /**
     * The tool's name.
     */
    public function name(): string
    {
        return 'consult_vascular_guidelines';
    }

    /**
     * The tool's description.
     */
    public function description(): string
    {
        return 'Consults ESVS clinical guidelines for vascular surgery questions. Use this tool for any medical or clinical query to retrieve evidence-based recommendations.';
    }

    /**
     * The tool's input schema.
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'question' => [
                    'type' => 'string',
                    'description' => 'The clinical question to answer.',
                ],
                'history' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Optional list of previous conversation messages for context fusion.',
                ]
            ],
            'required' => ['question'],
        ];
    }

    /**
     * Execute the tool.
     */
    public function handle(string $question, array $history = []): array
    {
        // 1. Retrieve raw data
        $result = $this->retrievalService->retrieve($question, $history);

        // 2. Get guideline names and top evidence only
        $guidelineNames = implode(', ', array_column($result['selected_guidelines'], 'name'));
        $evidence = $this->selectTopEvidence($result['citation_chunks'], 5);

        // 3. Build concise output (evidence only - for clean citation popups)
        $output = $this->buildFormattedOutput($question, $guidelineNames, [], $evidence);

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => $output,
                ],
            ],
        ];
    }

    /**
     * Format context chunks for LLM consumption (cleaned, summarized).
     */
    protected function formatContextChunks(array $narrativeChunks): array
    {
        $formatted = [];

        foreach ($narrativeChunks as $idx => $chunk) {
            $content = $chunk['content'] ?? '';
            $cleanContent = $this->cleanNarrativeContent($content);

            if (empty(trim($cleanContent))) {
                continue;
            }

            $formatted[] = [
                'id' => 'ctx_' . ($idx + 1),
                'source' => $chunk['source_guideline'] ?? 'ESVS',
                'relevance' => $chunk['similarity'] ?? 0,
                'text' => $cleanContent,
            ];
        }

        return $formatted;
    }

    /**
     * Select top N evidence items from citation chunks.
     * Evidence = specific recommendations for verbatim quoting.
     */
    protected function selectTopEvidence(array $citationChunks, int $limit = 5): array
    {
        $evidence = [];
        $seenRecIds = [];

        // Sort by similarity score (highest first)
        usort(
            $citationChunks,
            fn($a, $b) =>
            ($b['similarity'] ?? 0) <=> ($a['similarity'] ?? 0)
        );

        foreach ($citationChunks as $idx => $chunk) {
            $recId = $chunk['recommendation_id'] ?? '';

            // Deduplicate by recommendation ID
            if ($recId && in_array($recId, $seenRecIds)) {
                continue;
            }
            if ($recId) {
                $seenRecIds[] = $recId;
            }

            $evidence[] = [
                'cite_id' => 'E' . (count($evidence) + 1),
                'rec_id' => $recId,
                'guideline' => $chunk['guideline'] ?? '',
                'class' => $chunk['class'] ?? '',
                'level' => $chunk['level'] ?? '',
                'quote' => $chunk['text'] ?? '',
                'score' => $chunk['similarity'] ?? 0,
            ];

            if (count($evidence) >= $limit) {
                break;
            }
        }

        return $evidence;
    }

    /**
     * Build the formatted output for the LLM.
     */
    protected function buildFormattedOutput(
        string $question,
        string $guidelineNames,
        array $contextChunks,
        array $evidence
    ): string {
        $output = "# 📋 ESVS Guidelines Consultation\n\n";
        $output .= "**Query:** {$question}\n\n";
        $output .= "**Guidelines:** {$guidelineNames}\n\n";
        $output .= "---\n\n";

        // EVIDENCE ONLY - these become the citation popup content
        if (empty($evidence)) {
            $output .= "**No specific ESVS recommendations found for this query.**\n\n";
            $output .= "Please provide a general answer based on clinical knowledge.\n";
        } else {
            $output .= "## 📑 ESVS Recommendations\n\n";

            foreach ($evidence as $e) {
                $output .= "### {$e['cite_id']}: {$e['rec_id']}\n";
                $output .= "**{$e['guideline']}** | {$e['class']} | {$e['level']}\n\n";
                $output .= "> {$e['quote']}\n\n";
            }

            $output .= "---\n\n";
            $output .= "**Instructions:** Synthesize an answer using these recommendations. ";
            $output .= "Cite as (E1), (E2) etc. in your response.\n";
        }

        return $output;
    }

    /**
     * Clean narrative content by removing noise.
     */
    protected function cleanNarrativeContent(string $content): string
    {
        // Remove entity blocks
        $content = preg_replace('/----\s*Entities\s*----.*?(?=\n[A-Z]|\n\n\n|$)/s', '', $content);
        $content = preg_replace('/^,Entity,Score,Description\n.*?(?=\n[A-Z]|\n\n|$)/ms', '', $content);
        $content = preg_replace('/^\d+,[A-Z][A-Z\s]+,\d+\.\d+,.*$/m', '', $content);

        // Remove visual descriptions
        $content = preg_replace('/- Visual Type:.*?(?=\n\n|\n-\s+[A-Z]|$)/s', '', $content);
        $content = preg_replace('/- (Title|Axes|Data Points|Trends|Captions|Legends)[^:]*:.*?(?=\n-\s+[A-Z]|\n\n|$)/s', '', $content);

        // Extract useful text from HTML tables
        if (preg_match_all('/<tr><td[^>]*>\s*(\d+\..*?)<\/td><\/tr>/i', $content, $matches)) {
            $recommendations = array_filter($matches[1], fn($item) => strlen(trim($item)) > 20);
            if (!empty($recommendations)) {
                $cleanRecs = "";
                foreach (array_slice($recommendations, 0, 4) as $rec) {
                    $cleanRecs .= "• " . trim(strip_tags($rec)) . "\n";
                }
                $content = preg_replace('/<table>.*?<\/table>/s', $cleanRecs, $content);
            }
        }

        // Clean HTML
        $content = strip_tags($content);

        // Remove reference citations
        $content = preg_replace('/^\d+\s+[A-Z][a-z]+\s+[A-Z]{2,}.*$/m', '', $content);

        // Clean whitespace
        $content = preg_replace('/\n{3,}/', "\n\n", $content);

        // Truncate (generous limit to preserve context)
        if (strlen($content) > 2000) {
            $content = substr($content, 0, 2000) . '...';
        }

        return trim($content);
    }
}
