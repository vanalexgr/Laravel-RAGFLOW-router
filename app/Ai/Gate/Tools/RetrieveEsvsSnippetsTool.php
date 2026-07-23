<?php

namespace App\Ai\Gate\Tools;

use App\Services\RetrievalService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Grounding seam for the gate: pulls real ESVS recommendation snippets for ONE
 * guideline so a PathwayAgent enumerates decision pathways from retrieved text
 * rather than from model memory.
 *
 * It delegates to the existing production retrieval pipeline
 * (App\Services\RetrievalService) exactly like the Vizra
 * RetrieveClinicalEvidenceTool does — this keeps a single source of truth for
 * how RAGFlow is queried. We scope retrieval to the single guideline key the
 * PathwayAgent is responsible for so the parallel fan-out stays clean.
 */
final class RetrieveEsvsSnippetsTool implements Tool
{
    /** Cap snippets returned to the model so the pathway prompt stays bounded. */
    private const MAX_SNIPPETS = 10;

    public function __construct(
        private readonly RetrievalService $retrieval,
    ) {
    }

    public function description(): Stringable|string
    {
        return 'Retrieve the most relevant ESVS guideline recommendation snippets for a single '
            .'guideline key, to ground clinical decision pathways in the actual guideline text.';
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'guideline_key' => $schema->string()
                ->description('The single ESVS guideline key to retrieve from (e.g. "carotid").')
                ->required(),
            'query' => $schema->string()
                ->description('A focused retrieval query describing the decision at issue.')
                ->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $guidelineKey = trim((string) ($request['guideline_key'] ?? ''));
        $query = trim((string) ($request['query'] ?? ''));

        if ($guidelineKey === '' || $query === '') {
            return 'NO_SNIPPETS: guideline_key and query are both required.';
        }

        $result = $this->retrieval->retrieve($query, [], [$guidelineKey]);

        $snippets = [];
        foreach (['citation_chunks', 'narrative_chunks'] as $bucket) {
            foreach ((array) ($result[$bucket] ?? []) as $chunk) {
                $text = is_array($chunk) ? (string) ($chunk['content'] ?? $chunk['text'] ?? '') : (string) $chunk;
                $text = trim($text);
                if ($text !== '') {
                    $snippets[] = $text;
                }
                if (count($snippets) >= self::MAX_SNIPPETS) {
                    break 2;
                }
            }
        }

        if ($snippets === []) {
            return "NO_SNIPPETS: retrieval returned nothing for guideline '{$guidelineKey}'.";
        }

        return "ESVS snippets for '{$guidelineKey}':\n- ".implode("\n- ", $snippets);
    }
}
