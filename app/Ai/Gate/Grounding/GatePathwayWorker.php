<?php

namespace App\Ai\Gate\Grounding;

use App\Ai\Gate\PathwayAgent;
use App\Ai\Gate\Retrieval\GateEvidenceQuota;
use App\Ai\Gate\Retrieval\GateRetrievalQueryBuilder;
use App\Ai\Gate\Tools\RetrieveEsvsSnippetsTool;
use RuntimeException;

final class GatePathwayWorker
{
    /**
     * Bound on the evidence carried out of a branch, unchanged from when a single
     * attempt's slice was returned directly.
     */
    private const MAX_MERGED_SNIPPETS = 10;

    public function __construct(
        private readonly RetrieveEsvsSnippetsTool $retrieval,
    ) {}

    /**
     * Execute one guideline branch. Inputs and output remain serializable so
     * Laravel's process driver can run the routed branches concurrently.
     *
     * $deadlineAt is the parent's absolute wall-clock deadline as a Unix
     * timestamp. It is passed as an absolute instant rather than a duration so
     * it survives serialization into a forked worker, where "seconds remaining"
     * measured at dispatch would already be stale. A null deadline is used for
     * the mandatory first scoring pass; each retrieval/model call still keeps
     * its own timeout.
     *
     * @param  array<string, mixed>  $patientModel
     * @return array<string, mixed>
     */
    public function run(
        string $guideline,
        string $initialQuery,
        array $patientModel,
        string $turn,
        ?array $prefetched = null,
        ?int $maxAttemptsOverride = null,
        ?int $timeoutSeconds = null,
        ?float $deadlineAt = null,
        ?string $initialCitationQuery = null,
        ?array $initialCitationQueries = null,
    ): array {
        $query = $initialQuery;
        $citationQuery = $initialCitationQuery ?? $initialQuery;
        $citationQueries = (bool) config('gate-v2.retrieval.citation_multi_query', true)
            ? array_values((array) $initialCitationQueries)
            : [$citationQuery];
        $queriesTried = [];
        $assessment = null;
        $snippetDigests = [];
        $trace = [];
        $maxAttempts = max(1, min(
            3,
            $maxAttemptsOverride ?? (int) config('gate-v2.retrieval.max_attempts', 3),
        ));
        $topKCaps = array_values((array) config('gate-v2.retrieval.attempt_top_k', [12, 24]));

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            // A retry is only worth starting if the parent deadline can still
            // cover a retrieval plus its assessment; otherwise this branch would
            // outlive the wall-clock it was dispatched under.
            if (! $this->canStartAttempt($deadlineAt)) {
                $trace[] = [
                    'stage' => 'retrieve_skipped',
                    'duration_ms' => 0,
                    'detail' => [
                        'guideline' => $guideline,
                        'attempt' => $attempt,
                        'reason' => 'parent_deadline_exhausted',
                        'remaining_seconds' => $this->remainingSeconds($deadlineAt),
                    ],
                ];
                break;
            }

            $queriesTried[] = $query;
            $topKIndex = $attempt === $maxAttempts
                ? count($topKCaps) - 1
                : min($attempt - 1, count($topKCaps) - 1);
            $topK = (int) ($topKCaps[$topKIndex] ?? 24);
            $retrievalStarted = microtime(true);
            $baseTimeout = $timeoutSeconds ?? (int) config('gate-v2.retrieval.timeout_seconds', 20);
            if (count($citationQueries) > 1 && $deadlineAt !== null) {
                // The tool may consume at most 1.5x this base across its two
                // sequential calls. Reduce the base first so that enlarged
                // allowance still fits the absolute parent deadline.
                $remaining = max(1, $this->remainingSeconds($deadlineAt) ?? 1);
                $baseTimeout = min($baseTimeout, max(1, (int) floor($remaining / 1.5)));
            }
            $retrieved = $this->retrieval->retrieve(
                $guideline,
                $query,
                $attempt === $maxAttempts,
                $topK,
                $this->clampToDeadline(
                    $baseTimeout,
                    $deadlineAt,
                ),
                $citationQuery,
                $citationQueries,
            );
            $retrievalDuration = (int) round((microtime(true) - $retrievalStarted) * 1000);
            $trace[] = [
                'stage' => 'retrieve',
                'duration_ms' => $retrievalDuration,
                'detail' => [
                    'guideline' => $guideline,
                    'attempt' => $attempt,
                    'full_pipeline' => $attempt === $maxAttempts,
                    'top_k' => $topK,
                    'snippet_count' => $retrieved['diagnostics']['snippet_count'] ?? 0,
                    // Recommendation supply is the metric that actually predicts
                    // answer quality, and the citation query is what drives it — a
                    // question-form or over-long query returns zero recommendations.
                    // Both were previously absent from the trace, leaving the eval
                    // unable to explain a zero-citation turn.
                    'citation_count' => $retrieved['diagnostics']['citation_count'] ?? 0,
                    'citation_available' => $retrieved['diagnostics']['citation_available'] ?? 0,
                    'narrative_available' => $retrieved['diagnostics']['narrative_available'] ?? 0,
                    'citation_query' => $citationQuery,
                    'citation_query_chars' => mb_strlen($citationQuery),
                    'citation_queries' => $citationQueries,
                    'citation_query_chars_each' => array_map('mb_strlen', $citationQueries),
                    'citation_top_k' => min($topK, 16),
                    'retrieval_ms' => $retrieved['diagnostics']['duration_ms'] ?? null,
                ],
            ];
            $snippetDigests = $this->mergeSnippets($snippetDigests, (array) $retrieved['snippets']);

            $assessmentStarted = microtime(true);
            $response = (new PathwayAgent($guideline))->prompt(
                json_encode([
                    'patient_model' => $patientModel,
                    'current_question' => $turn,
                    'query' => $query,
                    'citation_query' => $citationQuery,
                    'citation_queries' => $citationQueries,
                    'attempt' => $attempt,
                    'final_attempt' => $attempt === $maxAttempts,
                    'snippets' => $snippetDigests,
                    'retrieval_diagnostics' => $retrieved['diagnostics'],
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                provider: (string) config('gate-v2.provider'),
                model: (string) config('gate-v2.stage_models.pathway', config('gate-v2.model')),
                timeout: $this->clampToDeadline(
                    min(60, (int) config('gate-v2.stage_timeouts.pathway', 30)),
                    $deadlineAt,
                ),
            );
            $assessment = $response->toArray();
            if ($assessment === []) {
                throw new RuntimeException(PathwayAgent::class.' returned an empty structured response.');
            }
            $trace[] = [
                'stage' => 'pathway',
                'duration_ms' => (int) round((microtime(true) - $assessmentStarted) * 1000),
                'detail' => [
                    'guideline' => $guideline,
                    'attempt' => $attempt,
                    'relevant' => $assessment['relevant'] ?? null,
                    'coverage' => $assessment['coverage'] ?? null,
                ],
            ];

            if (($assessment['relevant'] ?? false) === true && (
                ($assessment['coverage'] ?? null) === 'covered'
                || $this->firstPassEvidenceIsSufficient($retrieved)
                || $attempt === $maxAttempts
            )) {
                break;
            }

            $betterQuery = trim((string) ($assessment['better_query'] ?? ''));
            $query = $betterQuery !== '' && ! in_array($betterQuery, $queriesTried, true)
                ? $betterQuery
                : $query.' ESVS recommendation decision threshold anatomy';
            // Shape the retry's citation query the same way the builder does. The
            // agent's `better_query` arrives in question form, which measurably
            // returns zero recommendations from both recommendations documents
            // regardless of length, and the old boilerplate prefix ("ESVS
            // recommendation class evidence level decision threshold for: ") is not
            // language any recommendation row contains.
            $citationQuery = (new GateRetrievalQueryBuilder)->shapeCitationQuery(
                $betterQuery !== '' ? $betterQuery : $citationQuery,
            );
            // Multi-query retries retain the deterministic core unchanged. The
            // legacy A/B path still follows the assessor's shaped better_query.
            if (! (bool) config('gate-v2.retrieval.citation_multi_query', true)) {
                $citationQueries = [$citationQuery];
            }
        }

        if ($assessment !== null) {
            $assessment['queries_tried'] = $queriesTried;
        }

        return [
            'guideline' => $guideline,
            'assessment' => $assessment,
            'queries_tried' => $queriesTried,
            'snippet_digests' => $snippetDigests,
            'trace' => $trace,
        ];
    }

    /**
     * A retry must only ever ADD evidence. This previously assigned the latest
     * attempt's slice, so a retry that came back smaller discarded everything the
     * first attempt found. In Run 7 that hit 12 of 21 retry branches, and F4 wiped
     * all three guidelines to empty — two of them from a `partial` coverage verdict
     * to `not_covered` — while the trace still reported 26 chunks retrieved.
     *
     * Merging also makes the retry query safe to keep: the appended
     * "ESVS recommendation decision threshold anatomy" terms can return nothing
     * without costing the branch its evidence.
     *
     * @param  array<int, array<string, mixed>>  $kept
     * @param  array<int, array<string, mixed>>  $incoming
     * @return array<int, array<string, mixed>>
     */
    private function mergeSnippets(array $kept, array $incoming): array
    {
        $merged = $kept;
        $seen = [];
        foreach ($kept as $snippet) {
            $seen[$this->snippetKey($snippet)] = true;
        }

        foreach ($incoming as $snippet) {
            $key = $this->snippetKey($snippet);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $merged[] = $snippet;
        }

        // Rank WITHIN each bucket only. The two buckets come from different
        // datasets, so their similarity scores are not comparable — a raw
        // cross-bucket sort would let prose outrank recommendations on a number
        // that does not mean the same thing on both sides, and would silently
        // undo the citation reservation applied during retrieval.
        $buckets = GateEvidenceQuota::partition($merged);
        foreach ($buckets as $name => $snippets) {
            // PHP's sort is stable, so equal scores keep retrieval order.
            usort(
                $snippets,
                static fn (array $a, array $b): int => self::similarityRank($b) <=> self::similarityRank($a),
            );
            $buckets[$name] = $snippets;
        }

        return GateEvidenceQuota::fill(
            $buckets['citation'],
            $buckets['narrative'],
            self::MAX_MERGED_SNIPPETS,
        );
    }

    /**
     * Identity for de-duplication. Chunk text is the only field always present;
     * `recommendation_id` is absent on narrative-bucket chunks.
     *
     * @param  array<string, mixed>  $snippet
     */
    private function snippetKey(array $snippet): string
    {
        return md5(trim((string) ($snippet['text'] ?? '')));
    }

    /**
     * Unscored snippets sort last but are still retained — a missing similarity
     * is not evidence of irrelevance.
     *
     * @param  array<string, mixed>  $snippet
     */
    private static function similarityRank(array $snippet): float
    {
        return is_numeric($snippet['similarity'] ?? null)
            ? (float) $snippet['similarity']
            : -1.0;
    }

    /**
     * Seconds left on the parent deadline, or null when the caller imposed none.
     */
    private function remainingSeconds(?float $deadlineAt): ?int
    {
        return $deadlineAt === null ? null : (int) floor($deadlineAt - microtime(true));
    }

    /**
     * Bound a stage timeout by the parent's remaining wall-clock. Always returns
     * at least 1 so an exhausted budget surfaces as a fast child timeout rather
     * than a zero/negative timeout that some HTTP clients read as "no limit".
     */
    private function clampToDeadline(int $seconds, ?float $deadlineAt): int
    {
        $remaining = $this->remainingSeconds($deadlineAt);

        return max(1, $remaining === null ? $seconds : min($seconds, $remaining));
    }

    /**
     * A fresh attempt needs room for a retrieval and the assessment that reads
     * it. Starting one with less is how a branch overran the parent deadline.
     */
    private function canStartAttempt(?float $deadlineAt): bool
    {
        $remaining = $this->remainingSeconds($deadlineAt);

        return $remaining === null
            || $remaining >= max(1, (int) config('gate-v2.retrieval.minimum_attempt_seconds', 8));
    }

    /** @param array<string, mixed> $retrieved */
    private function firstPassEvidenceIsSufficient(array $retrieved): bool
    {
        return count((array) ($retrieved['snippets'] ?? [])) >= max(1, (int) config('gate-v2.retrieval.sufficient_snippet_count', 4))
            && (float) ($retrieved['diagnostics']['max_similarity'] ?? 0) >= (float) config('gate-v2.retrieval.sufficient_similarity', 0.78);
    }
}
