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

        // 2. Format for LLM Consumption
        // We present a structured text block so the calling LLM (Client) can synthesize it.

        $output = "RETRIEVED GUIDELINES for: \"{$result['question']}\"\n";
        $output .= "Guidelines Used: " . implode(', ', array_column($result['selected_guidelines'], 'name')) . "\n\n";

        $output .= "=== NARRATIVE EVIDENCE (Use for context) ===\n";
        foreach ($result['narrative_chunks'] as $chunk) {
            $output .= "- " . ($chunk['content'] ?? '') . "\n";
        }
        $output .= "\n";

        $output .= "=== CITATIONS (Use for verbatim quoting) ===\n";
        foreach ($result['citation_chunks'] as $chunk) {
            $meta = [];
            if (!empty($chunk['recommendation_id']))
                $meta[] = $chunk['recommendation_id'];
            if (!empty($chunk['class']))
                $meta[] = $chunk['class'];
            if (!empty($chunk['level']))
                $meta[] = $chunk['level'];
            $metaStr = !empty($meta) ? " [" . implode(', ', $meta) . "]" : "";

            $output .= "> \"{$chunk['text']}\"{$metaStr}\n";
        }

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => $output,
                ],
            ],
        ];
    }
}
