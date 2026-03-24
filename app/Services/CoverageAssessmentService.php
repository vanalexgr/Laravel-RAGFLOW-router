<?php

namespace App\Services;

use App\Contracts\LlmClient;
use App\ValueObjects\GapAssessment;
use Illuminate\Support\Facades\Log;

class CoverageAssessmentService
{
    // Maximum chars of chunk text sent to LLM — keeps tokens manageable
    private const CHUNK_PREVIEW_CHARS = 180;
    private const MAX_CHUNKS_FOR_ASSESSMENT = 12;

    public function __construct(private readonly LlmClient $llm) {}

    /**
     * Assess whether retrieved chunks cover the clinical facets of the query.
     *
     * Skipped (returns noGap) when:
     * - query_type is knowledge_only
     * - no chunks provided
     * - fewer than 2 facets to evaluate
     */
    public function assess(
        string $question,
        array  $llmChunks,
        array  $facets,
        string $queryType = 'complex_case',
    ): GapAssessment {
        if ($queryType === 'knowledge_only' || empty($llmChunks) || count($facets) < 2) {
            return GapAssessment::noGap();
        }

        try {
            $prompt = $this->buildPrompt($question, $llmChunks, $facets);
            $raw    = $this->llm->complete($prompt, maxTokens: 400, temperature: 0);
            $data   = $this->parseJson($raw);

            if ($data === null) {
                Log::channel('retrieval')->warning('[COVERAGE ASSESSMENT] JSON parse failed — returning no-gap', [
                    'question_preview' => substr($question, 0, 120),
                    'raw_preview'      => substr($raw, 0, 200),
                ]);
                return GapAssessment::noGap();
            }

            return GapAssessment::fromArray($data);
        } catch (\Throwable $e) {
            Log::channel('retrieval')->warning('[COVERAGE ASSESSMENT] LLM call failed — returning no-gap', [
                'question_preview' => substr($question, 0, 120),
                'error'            => $e->getMessage(),
            ]);
            return GapAssessment::noGap();
        }
    }

    private function buildPrompt(string $question, array $chunks, array $facets): string
    {
        $facetList   = implode("\n", array_map(fn($f) => "- {$f}", array_slice($facets, 0, 8)));
        $chunksSummary = $this->summariseChunks($chunks);

        return <<<PROMPT
You are evaluating ESVS vascular guideline coverage for a clinical query.

Query: {$question}

Clinical facets to evaluate:
{$facetList}

Retrieved guideline chunks:
{$chunksSummary}

For each facet above, assess whether the retrieved chunks contain direct guidance.
Return ONLY valid JSON. No preamble, no explanation, only JSON.

{
  "facet_coverage": [
    {"facet": "facet name", "coverage": "direct|partial|none", "evidence": "brief quote or null"}
  ],
  "gap_summary": "One sentence describing what the guidelines do NOT address, or null if fully covered."
}

coverage rules:
- "direct"  = chunk contains a specific recommendation or explicit guidance on this facet
- "partial" = chunk touches the topic but does not provide actionable guidance
- "none"    = no chunk addresses this facet at all
PROMPT;
    }

    private function summariseChunks(array $chunks): string
    {
        $lines = [];
        foreach (array_slice($chunks, 0, self::MAX_CHUNKS_FOR_ASSESSMENT) as $i => $chunk) {
            $text   = (string) ($chunk['text'] ?? $chunk['content'] ?? '');
            $source = (string) ($chunk['source_guideline'] ?? $chunk['guideline'] ?? '');
            $preview = substr(trim($text), 0, self::CHUNK_PREVIEW_CHARS);
            $label  = $source ? "[{$source}]" : "[chunk " . ($i + 1) . "]";
            $lines[] = "{$label} {$preview}";
        }

        return implode("\n", $lines);
    }

    private function parseJson(string $raw): ?array
    {
        $clean = preg_replace('/```json|```/', '', $raw);
        $data  = json_decode(trim($clean), true);

        return is_array($data) ? $data : null;
    }
}
