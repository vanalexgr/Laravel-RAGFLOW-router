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
    ) {}

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
            return '{"error":"guideline_key and query are both required","snippets":[]}';
        }

        return json_encode(
            $this->retrieve($guidelineKey, $query),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) ?: '{"error":"Unable to encode retrieval result","snippets":[]}';
    }

    /**
     * Deterministic orchestration entry point used by GateWorkflowService.
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $guidelineKey, string $query, bool $fullPipeline = false): array
    {
        $previous = [
            'lean' => config('ragflow.lean.enabled'),
            'planner' => config('ragflow.planner.merged_enabled'),
            'planner_shadow' => config('ragflow.planner.shadow'),
            'interpreter' => config('clinical_interpreter.enabled'),
            'graph' => config('graphrag.enabled'),
        ];
        config()->set('ragflow.planner.merged_enabled', false);
        config()->set('ragflow.planner.shadow', false);
        config()->set('clinical_interpreter.enabled', false);
        config()->set('graphrag.enabled', false);
        if ($fullPipeline) {
            config()->set('ragflow.lean.enabled', false);
        }

        try {
            $result = $this->retrieval->retrieve($query, [], [$guidelineKey]);
        } finally {
            config()->set('ragflow.lean.enabled', $previous['lean']);
            config()->set('ragflow.planner.merged_enabled', $previous['planner']);
            config()->set('ragflow.planner.shadow', $previous['planner_shadow']);
            config()->set('clinical_interpreter.enabled', $previous['interpreter']);
            config()->set('graphrag.enabled', $previous['graph']);
        }

        $snippets = [];
        $similarities = [];
        foreach (['llm_citation_chunks', 'llm_narrative_chunks'] as $bucket) {
            foreach ((array) ($result[$bucket] ?? []) as $chunk) {
                $text = is_array($chunk) ? (string) ($chunk['content'] ?? $chunk['text'] ?? '') : (string) $chunk;
                $text = trim($text);
                if ($text !== '') {
                    $snippets[] = [
                        'text' => $text,
                        'similarity' => is_array($chunk) ? ($chunk['similarity'] ?? null) : null,
                        'source' => is_array($chunk)
                            ? ($chunk['guideline'] ?? $chunk['source_guideline'] ?? $guidelineKey)
                            : $guidelineKey,
                    ];
                    if (is_array($chunk) && is_numeric($chunk['similarity'] ?? null)) {
                        $similarities[] = (float) $chunk['similarity'];
                    }
                }
                if (count($snippets) >= self::MAX_SNIPPETS) {
                    break 2;
                }
            }
        }

        return [
            'guideline_key' => $guidelineKey,
            'query' => $query,
            'retrieval_query' => (string) ($result['retrieval_query'] ?? $query),
            'full_pipeline' => $fullPipeline,
            'snippets' => $snippets,
            'diagnostics' => [
                'snippet_count' => count($snippets),
                'max_similarity' => $similarities === [] ? null : max($similarities),
                'duration_ms' => (int) ($result['duration_ms'] ?? 0),
            ],
        ];
    }
}
