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

        // 2. Format for LLM Consumption with clean markdown
        $guidelineNames = implode(', ', array_column($result['selected_guidelines'], 'name'));

        $output = "# 📋 ESVS Guidelines Evidence\n\n";
        $output .= "**Query:** {$result['question']}\n\n";
        $output .= "**Guidelines:** {$guidelineNames}\n\n";
        $output .= "---\n\n";

        // Format narrative evidence - cleaned up
        $output .= "## 📚 Clinical Context\n\n";

        $evidenceNum = 1;
        foreach ($result['narrative_chunks'] as $chunk) {
            $content = $chunk['content'] ?? '';
            $source = $chunk['source_guideline'] ?? 'ESVS';
            $relevance = $chunk['similarity'] ?? 0;

            // Clean the content
            $cleanContent = $this->cleanNarrativeContent($content);

            if (empty(trim($cleanContent))) {
                continue;
            }

            $output .= "### [{$evidenceNum}] {$source} (Relevance: {$relevance}%)\n\n";
            $output .= "{$cleanContent}\n\n";
            $evidenceNum++;
        }

        // Format citations - clean structured format
        $output .= "---\n\n";
        $output .= "## 📑 Recommendations (Verbatim Citations)\n\n";

        if (empty($result['citation_chunks'])) {
            $output .= "*No specific recommendations retrieved. Use narrative evidence above for context.*\n\n";
        } else {
            foreach ($result['citation_chunks'] as $idx => $chunk) {
                $num = $idx + 1;
                $recId = $chunk['recommendation_id'] ?? '';
                $class = $chunk['class'] ?? '';
                $level = $chunk['level'] ?? '';
                $guideline = $chunk['guideline'] ?? '';
                $text = $chunk['text'] ?? '';

                // Build header
                $header = [];
                if ($recId)
                    $header[] = "**{$recId}**";
                if ($class)
                    $header[] = $class;
                if ($level)
                    $header[] = $level;
                $headerStr = implode(' | ', $header);

                if ($guideline) {
                    $output .= "### [{$num}] {$guideline}\n";
                } else {
                    $output .= "### [{$num}] Recommendation\n";
                }

                if ($headerStr) {
                    $output .= "{$headerStr}\n\n";
                }

                $output .= "> {$text}\n\n";
            }
        }

        // Instructions for LLM
        $output .= "---\n\n";
        $output .= "## ⚠️ Response Format\n\n";
        $output .= "1. **🩺 Clinical Synthesis** - 3-6 bullet points with inline citations [1], [2]\n";
        $output .= "2. **📑 Recommendations** - Quote verbatim from above\n";
        $output .= "3. **📌 Supporting Statements** - Additional guideline context\n";

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
     * Clean narrative content by removing noise and formatting issues.
     */
    protected function cleanNarrativeContent(string $content): string
    {
        // Remove entity blocks (---- Entities ---- sections)
        $content = preg_replace('/----\s*Entities\s*----.*?(?=\n[A-Z]|\n\n\n|$)/s', '', $content);

        // Remove CSV-style entity tables
        $content = preg_replace('/^,Entity,Score,Description\n.*?(?=\n[A-Z]|\n\n|$)/ms', '', $content);
        $content = preg_replace('/^\d+,[A-Z][A-Z\s]+,\d+\.\d+,.*$/m', '', $content);

        // Remove visual type descriptions (figure descriptions)
        $content = preg_replace('/- Visual Type:.*?(?=\n\n|\n-\s+[A-Z]|$)/s', '', $content);

        // Remove "Title:", "Axes / Legends", "Data Points:", "Trends / Insights:", "Captions" blocks
        $content = preg_replace('/- (Title|Axes|Data Points|Trends|Captions|Legends)[^:]*:.*?(?=\n-\s+[A-Z]|\n\n|$)/s', '', $content);

        // Clean up HTML tables - extract text content
        if (preg_match_all('/<tr><td[^>]*>([^<]+)<\/td><\/tr>/i', $content, $matches)) {
            $tableItems = array_filter($matches[1], fn($item) => strlen(trim($item)) > 10);
            if (!empty($tableItems)) {
                $cleanTable = "Key points:\n";
                foreach (array_slice($tableItems, 0, 5) as $item) {
                    $cleanTable .= "• " . trim($item) . "\n";
                }
                $content = preg_replace('/<table>.*?<\/table>/s', $cleanTable, $content);
            }
        }

        // Remove any remaining HTML tags
        $content = strip_tags($content);

        // Remove lines that are just dashes or equals
        $content = preg_replace('/^[-=]{3,}$/m', '', $content);

        // Remove reference numbers and citations like "42.el." or "350 Stone WM..."
        $content = preg_replace('/^\d+\.?\s*[A-Z][a-z]+\s+[A-Z]{1,2}.*$/m', '', $content);
        $content = preg_replace('/^\d+\s+[A-Z][a-z]+\s+[A-Z]{2,}.*?(?:;|\.)$/m', '', $content);

        // Clean excess whitespace
        $content = preg_replace('/\n{3,}/', "\n\n", $content);
        $content = preg_replace('/^\s+$/m', '', $content);

        // Truncate very long content
        if (strlen($content) > 800) {
            $content = substr($content, 0, 800) . '...';
        }

        return trim($content);
    }
}
