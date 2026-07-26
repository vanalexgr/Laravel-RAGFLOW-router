<?php

namespace App\Ai\Gate\Tools;

use App\Ai\Gate\Retrieval\GateChunkCleaner;
use App\Ai\Gate\Retrieval\GateEvidenceQuota;
use App\Facades\RAGFlow as RAGFlowFacade;
use App\Services\RAGFlow\RAGFlowClient;
use App\Services\RetrievalService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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
    /**
     * Preserve enough per-attempt candidates for the worker to rank retries
     * globally. The worker still applies the final 10-snippet prompt bound.
     */
    private const MAX_SNIPPETS = 20;

    public function __construct(
        private readonly RetrievalService $retrieval,
        private readonly ?GateChunkCleaner $chunkCleaner = null,
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
     * Optionally memoises the retrieval so a development sweep does not pay for the
     * same reranking repeatedly. Reranking is billed per CALL (one query plus up to
     * ~100 documents), so lowering top_k saves nothing — only issuing fewer calls
     * does. An eval that runs the same case N times used to pay N times; now that
     * the citation queries are deterministic, runs 2..N hit this cache.
     *
     * Off unless a TTL is configured, so production is unaffected.
     *
     * This does NOT weaken the determinism metric: distinct-plan counting is taken
     * from the queries the pipeline decided to issue, which are computed before
     * retrieval, and any query that differs produces a different key and a miss.
     * It DOES invalidate latency measurement, and it hides transient upstream
     * failures — never leave it on for a timing or reliability run.
     *
     * @return array<string, mixed>
     */
    public function retrieve(
        string $guidelineKey,
        string $query,
        bool $fullPipeline = false,
        ?int $topK = null,
        ?int $timeoutSeconds = null,
        ?string $citationQuery = null,
        ?array $citationQueries = null,
    ): array {
        $ttl = (int) config('gate-v2.retrieval.dev_cache_ttl_seconds', 0);
        if ($ttl <= 0) {
            return $this->retrieveUncached(
                $guidelineKey, $query, $fullPipeline, $topK, $timeoutSeconds, $citationQuery, $citationQueries,
            );
        }

        // Everything that can change the RESULT is in the key. The timeout is not,
        // because it changes only how long we are willing to wait. The reranker
        // identity IS, because local and Cohere return different orderings.
        $key = 'gate-v2:retrieval:'.hash('sha256', json_encode([
            $guidelineKey, $query, $fullPipeline, $topK, $citationQuery, $citationQueries,
            config('gate-v2.retrieval.citation_multi_query'),
            config('gate-v2.retrieval.citation_multi_query_max'),
            config('ragflow.retrieval.rerank_id'),
            config('ragflow.bridge_rerank.enabled'),
            config('ragflow.retrieval.strict_requested_keys'),
            config('ragflow.retrieval.authoritative_citation_document_scope'),
        ], JSON_THROW_ON_ERROR));

        $cached = Cache::get($key);
        if (is_array($cached)) {
            $cached['diagnostics']['cache_hit'] = true;

            return $cached;
        }

        $result = $this->retrieveUncached(
            $guidelineKey, $query, $fullPipeline, $topK, $timeoutSeconds, $citationQuery, $citationQueries,
        );
        // Only a successful retrieval is worth keeping; caching an empty result
        // would silently freeze a transient upstream failure into the sweep.
        if (($result['diagnostics']['snippet_count'] ?? 0) > 0) {
            Cache::put($key, $result, $ttl);
        }
        $result['diagnostics']['cache_hit'] = false;

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function retrieveUncached(
        string $guidelineKey,
        string $query,
        bool $fullPipeline = false,
        ?int $topK = null,
        ?int $timeoutSeconds = null,
        ?string $citationQuery = null,
        ?array $citationQueries = null,
    ): array {
        $multiQueryEnabled = (bool) config('gate-v2.retrieval.citation_multi_query', true);
        // The current bridge has no batch input, so the sequential fallback must
        // stay at two even if query construction is experimentally raised to four.
        $queryCap = min(2, max(1, (int) config('gate-v2.retrieval.citation_multi_query_max', 2)));
        $citationQueries = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $multiQueryEnabled ? (array) $citationQueries : [],
        ))));
        $citationQueries = array_slice($citationQueries, 0, $queryCap);
        if ($citationQueries === []) {
            $citationQueries = [$citationQuery];
        }
        $citationQueries = array_values(array_filter(
            $citationQueries,
            static fn (?string $value): bool => $value !== null && $value !== '',
        ));
        if ($citationQueries === []) {
            $citationQueries = [null];
        }

        $previous = [
            'lean' => config('ragflow.lean.enabled'),
            'planner' => config('ragflow.planner.merged_enabled'),
            'planner_shadow' => config('ragflow.planner.shadow'),
            'interpreter' => config('clinical_interpreter.enabled'),
            'graph' => config('graphrag.enabled'),
            'top_k' => config('ragflow.retrieval.top_k'),
            'citation_top_k' => config('ragflow.retrieval.citation_top_k'),
            'lean_top_k' => config('ragflow.lean.top_k'),
            'single_case_top_k' => config('ragflow.single_case.top_k'),
            'request_timeout' => config('ragflow.request_timeout'),
            'connect_timeout' => config('ragflow.connect_timeout'),
            'strict_keys' => config('ragflow.retrieval.strict_requested_keys'),
            'authoritative_citation_document_scope' => config('ragflow.retrieval.authoritative_citation_document_scope'),
        ];
        // One branch per guideline: the post-routing guardrails would otherwise
        // expand this branch back to the full routed set, so every branch would
        // retrieve every guideline's recommendations and return the same ones.
        config()->set('ragflow.retrieval.strict_requested_keys', true);
        config()->set('ragflow.retrieval.authoritative_citation_document_scope', true);
        config()->set('ragflow.planner.merged_enabled', false);
        config()->set('ragflow.planner.shadow', false);
        config()->set('clinical_interpreter.enabled', false);
        config()->set('graphrag.enabled', false);
        if ($fullPipeline) {
            config()->set('ragflow.lean.enabled', false);
        }
        if ($topK !== null) {
            $topK = max(4, min(64, $topK));
            config()->set('ragflow.retrieval.top_k', $topK);
            config()->set('ragflow.retrieval.citation_top_k', min($topK, 16));
            config()->set('ragflow.lean.top_k', $topK);
            config()->set('ragflow.single_case.top_k', $topK);
        }
        $perQueryTimeout = $timeoutSeconds;
        $multiQueryDeadline = null;
        if ($timeoutSeconds !== null) {
            $timeoutSeconds = max(1, $timeoutSeconds);
            $isMultiQuery = count($citationQueries) > 1;
            // With two sequential queries, 75% apiece gives a strict 1.5x cap
            // versus the old single-query allowance. GatePathwayWorker first
            // reduces the base allowance when the parent deadline is tighter.
            $perQueryTimeout = $isMultiQuery
                ? max(1, (int) floor($timeoutSeconds * 0.75))
                : $timeoutSeconds;
            $multiQueryDeadline = $isMultiQuery
                ? microtime(true) + ($timeoutSeconds * 1.5)
                : null;
            config()->set('ragflow.request_timeout', $perQueryTimeout);
            config()->set('ragflow.connect_timeout', min(
                $perQueryTimeout,
                max(1, (int) config('gate-v2.retrieval.connect_timeout_seconds', 3)),
            ));
            // The RAGFlow client is a singleton. Rebuild it within this scoped
            // retrieval so its Guzzle timeout reflects the gate's remaining budget.
            $this->rebuildRagflowClient();
        }

        try {
            $result = [
                'duration_ms' => 0,
                'llm_citation_chunks' => [],
                'llm_narrative_chunks' => [],
            ];
            foreach ($citationQueries as $index => $currentCitationQuery) {
                if ($multiQueryDeadline !== null) {
                    $remaining = max(1, (int) floor($multiQueryDeadline - microtime(true)));
                    $currentTimeout = min((int) $perQueryTimeout, $remaining);
                    config()->set('ragflow.request_timeout', $currentTimeout);
                    config()->set('ragflow.connect_timeout', min(
                        $currentTimeout,
                        max(1, (int) config('gate-v2.retrieval.connect_timeout_seconds', 3)),
                    ));
                    $this->rebuildRagflowClient();
                }

                $current = $index === 0
                    ? $this->retrieval->retrieve(
                        $query,
                        [],
                        [$guidelineKey],
                        $currentCitationQuery,
                    )
                    : $this->retrieval->retrieveCitations(
                        (string) $currentCitationQuery,
                        [],
                        [$guidelineKey],
                    );
                $result['duration_ms'] += (int) ($current['duration_ms'] ?? 0);
                $result['llm_citation_chunks'] = array_merge(
                    $result['llm_citation_chunks'],
                    (array) ($current['llm_citation_chunks'] ?? []),
                );
                // Narrative retrieval is independent of the citation wording and
                // is fetched exactly once. Later passes set narrative_max=0 via
                // RetrievalService::retrieveCitations().
                if ($index === 0) {
                    $result['llm_narrative_chunks'] = (array) ($current['llm_narrative_chunks'] ?? []);
                    $result['retrieval_query'] = $current['retrieval_query'] ?? $query;
                }
            }
        } finally {
            config()->set('ragflow.lean.enabled', $previous['lean']);
            config()->set('ragflow.planner.merged_enabled', $previous['planner']);
            config()->set('ragflow.planner.shadow', $previous['planner_shadow']);
            config()->set('clinical_interpreter.enabled', $previous['interpreter']);
            config()->set('graphrag.enabled', $previous['graph']);
            config()->set('ragflow.retrieval.top_k', $previous['top_k']);
            config()->set('ragflow.retrieval.citation_top_k', $previous['citation_top_k']);
            config()->set('ragflow.lean.top_k', $previous['lean_top_k']);
            config()->set('ragflow.single_case.top_k', $previous['single_case_top_k']);
            config()->set('ragflow.request_timeout', $previous['request_timeout']);
            config()->set('ragflow.connect_timeout', $previous['connect_timeout']);
            config()->set('ragflow.retrieval.strict_requested_keys', $previous['strict_keys']);
            config()->set(
                'ragflow.retrieval.authoritative_citation_document_scope',
                $previous['authoritative_citation_document_scope'],
            );
            if ($timeoutSeconds !== null) {
                $this->rebuildRagflowClient();
            }
        }

        $byBucket = ['citation' => [], 'narrative' => []];
        $seenTextHashes = ['citation' => [], 'narrative' => []];
        $similarities = [];
        $provenanceMismatch = 0;
        $provenanceUnverifiable = 0;
        $guidelineKeyByDocumentId = $this->guidelineKeyByDocumentId();
        $citationChunks = (array) ($result['llm_citation_chunks'] ?? []);
        $citationsWithDocumentId = count(array_filter(
            $citationChunks,
            static function (mixed $chunk): bool {
                $documentId = is_array($chunk)
                    ? ($chunk['document_id'] ?? $chunk['doc_id'] ?? $chunk['DocumentID'] ?? null)
                    : null;

                return is_scalar($documentId) && trim((string) $documentId) !== '';
            },
        ));
        $unlabelledCitationCount = count($citationChunks) - $citationsWithDocumentId;
        $provenanceGuardDegraded = $citationChunks !== [] && $citationsWithDocumentId === 0;
        if ($provenanceGuardDegraded) {
            Log::warning(
                'Gate citation provenance guard degraded to pass-through: bridge does not supply document IDs.',
                [
                    'requested_guideline' => $guidelineKey,
                    'citation_count' => count($citationChunks),
                ],
            );
        } elseif ($unlabelledCitationCount > 0) {
            Log::warning(
                'Gate discarded citation chunks without document IDs during a partial provenance rollout.',
                [
                    'requested_guideline' => $guidelineKey,
                    'discarded_count' => $unlabelledCitationCount,
                ],
            );
        }
        foreach (['llm_citation_chunks', 'llm_narrative_chunks'] as $bucket) {
            $bucketName = $bucket === 'llm_citation_chunks' ? 'citation' : 'narrative';
            foreach ((array) ($result[$bucket] ?? []) as $chunk) {
                $citationGuidelineKey = null;
                $provenanceVerified = null;
                if ($bucket === 'llm_citation_chunks') {
                    $documentId = is_array($chunk)
                        ? ($chunk['document_id'] ?? $chunk['doc_id'] ?? $chunk['DocumentID'] ?? null)
                        : null;
                    $documentId = is_scalar($documentId) ? trim((string) $documentId) : '';
                    $citationGuidelineKey = $documentId === ''
                        ? null
                        : ($guidelineKeyByDocumentId[$documentId] ?? null);

                    if ($provenanceGuardDegraded) {
                        $provenanceUnverifiable++;
                    } elseif ($documentId === '') {
                        // Some IDs are present in this response, so an unlabelled
                        // chunk belongs to a partial rollout and cannot be vouched
                        // for. Only an entirely unlabelled response fails open.
                        $provenanceUnverifiable++;

                        continue;
                    } elseif ($citationGuidelineKey !== $guidelineKey) {
                        // A supplied ID that is unknown is just as suspect as one
                        // mapped to another guideline.
                        $provenanceMismatch++;
                        if ($citationGuidelineKey === null) {
                            $provenanceUnverifiable++;
                        }

                        continue;
                    }
                    $provenanceVerified = $citationGuidelineKey === $guidelineKey;
                }
                $cleaned = ($this->chunkCleaner ?? new GateChunkCleaner)->clean($chunk, 3000);
                $text = $cleaned['text'];
                if ($text !== '') {
                    // Multiple sharp queries commonly retrieve the same row. Hash
                    // the cleaned chunk body before adding a query-independent
                    // identity header, and deduplicate within its evidence bucket.
                    $textHash = hash('sha256', trim($text));
                    if (isset($seenTextHashes[$bucketName][$textHash])) {
                        continue;
                    }
                    $seenTextHashes[$bucketName][$textHash] = true;
                    // The short identity key comes from the chunk's document ID,
                    // never from the requesting branch or the verbose title.
                    if ($bucket === 'llm_citation_chunks') {
                        $metadata = $cleaned['metadata'];
                        $identity = array_filter([
                            isset($metadata['recommendation_id']) ? 'Recommendation '.$metadata['recommendation_id'] : null,
                            isset($metadata['recommendation_class']) ? 'Class '.$metadata['recommendation_class'] : null,
                            isset($metadata['evidence_level']) ? 'Level '.$metadata['evidence_level'] : null,
                            $citationGuidelineKey,
                        ]);
                        if ($identity !== []) {
                            $text = '['.implode(' | ', $identity)."]\n".$text;
                        }
                    }
                    $byBucket[$bucketName][] = [
                        'text' => $text,
                        'bucket' => $bucketName,
                        'similarity' => is_array($chunk) ? ($chunk['similarity'] ?? null) : null,
                        'source' => $cleaned['metadata']['guideline']
                            ?? (is_array($chunk) ? ($chunk['guideline'] ?? $chunk['source_guideline'] ?? null) : null)
                            ?? $guidelineKey,
                        'requested_guideline' => $guidelineKey,
                        'provenance_verified' => $provenanceVerified,
                        'metadata' => $cleaned['metadata'],
                        'raw_chars' => $cleaned['raw_chars'],
                        'clean_chars' => $cleaned['clean_chars'],
                        'signal_ratio' => $cleaned['signal_ratio'],
                    ];
                    if (is_array($chunk) && is_numeric($chunk['similarity'] ?? null)) {
                        $similarities[] = (float) $chunk['similarity'];
                    }
                }
            }
        }

        $snippets = GateEvidenceQuota::fill(
            $byBucket['citation'],
            $byBucket['narrative'],
            self::MAX_SNIPPETS,
        );

        return [
            'guideline_key' => $guidelineKey,
            'query' => $query,
            'citation_query' => $citationQuery,
            'citation_queries' => $citationQueries,
            'retrieval_query' => (string) ($result['retrieval_query'] ?? $query),
            'full_pipeline' => $fullPipeline,
            'top_k' => $topK,
            'snippets' => $snippets,
            'diagnostics' => [
                'snippet_count' => count($snippets),
                // Recorded separately because a branch can look healthy on
                // snippet_count while supplying no recommendations at all — the
                // Run 8 failure mode. An eval must be able to see this directly.
                'citation_count' => count(array_filter(
                    $snippets,
                    static fn (array $s): bool => ($s['bucket'] ?? null) === 'citation',
                )),
                'citation_available' => count($byBucket['citation']),
                'narrative_available' => count($byBucket['narrative']),
                'citation_query_count' => count($citationQueries),
                'provenance_mismatch' => $provenanceMismatch,
                'provenance_unverifiable' => $provenanceUnverifiable,
                'provenance_guard_degraded' => $provenanceGuardDegraded,
                'max_similarity' => $similarities === [] ? null : max($similarities),
                'duration_ms' => (int) ($result['duration_ms'] ?? 0),
                'signal_ratio' => $this->weightedSignalRatio($snippets),
            ],
        ];
    }

    /** @return array<string, string> document ID => short guideline key */
    private function guidelineKeyByDocumentId(): array
    {
        $map = [];
        foreach ((array) config('guidelines.categories', []) as $category) {
            foreach ((array) ($category['guidelines'] ?? []) as $key => $guideline) {
                $documentId = $guideline['recs_doc_id'] ?? null;
                if (is_string($key) && is_string($documentId) && $documentId !== '') {
                    $map[$documentId] = $key;
                }
            }
        }

        return $map;
    }


    /** @param array<int, array<string, mixed>> $snippets */
    private function weightedSignalRatio(array $snippets): float
    {
        $cleanChars = array_sum(array_map(
            static fn (array $snippet): int => (int) ($snippet['clean_chars'] ?? 0),
            $snippets,
        ));
        if ($cleanChars === 0) {
            return 0.0;
        }

        $signalChars = array_sum(array_map(
            static fn (array $snippet): float => (int) ($snippet['clean_chars'] ?? 0)
                * (float) ($snippet['signal_ratio'] ?? 0),
            $snippets,
        ));

        return round($signalChars / $cleanChars, 4);
    }

    /**
     * Force the next RAGFlow resolution to build a client from current config.
     *
     * RetrievalService reaches RAGFlow through the facade, and a facade keeps its
     * own static instance cache that Container::forgetInstance() does not touch.
     * Clearing only the container leaves the previously resolved client — and its
     * original Guzzle timeout — in place, which silently defeats the gate's
     * per-call timeout scoping on every retrieval after the first in a process.
     */
    private function rebuildRagflowClient(): void
    {
        RAGFlowFacade::clearResolvedInstance('ragflow');
        app()->forgetInstance('ragflow');
        app()->forgetInstance(RAGFlowClient::class);
    }
}
