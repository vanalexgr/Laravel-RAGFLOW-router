<?php

namespace App\Ai\Gate\Grounding;

use App\Ai\Gate\PathwayAgent;
use App\Ai\Gate\Tools\RetrieveEsvsSnippetsTool;
use RuntimeException;

final class GatePathwayWorker
{
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
    ): array {
        $query = $initialQuery;
        $citationQuery = $initialCitationQuery ?? $initialQuery;
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
            $usingPrefetch = $attempt === 1 && $prefetched !== null;
            if (! $usingPrefetch && ! $this->canStartAttempt($deadlineAt)) {
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
            if ($usingPrefetch) {
                $retrieved = (array) $prefetched['retrieved'];
                $retrievalDuration = (int) ($prefetched['duration_ms'] ?? 0);
            } else {
                $retrievalStarted = microtime(true);
                $retrieved = $this->retrieval->retrieve(
                    $guideline,
                    $query,
                    $attempt === $maxAttempts,
                    $topK,
                    $this->clampToDeadline(
                        $timeoutSeconds ?? (int) config('gate-v2.retrieval.timeout_seconds', 20),
                        $deadlineAt,
                    ),
                    $citationQuery,
                );
                $retrievalDuration = (int) round((microtime(true) - $retrievalStarted) * 1000);
            }
            $trace[] = [
                'stage' => 'retrieve',
                'duration_ms' => $retrievalDuration,
                'detail' => [
                    'guideline' => $guideline,
                    'attempt' => $attempt,
                    'full_pipeline' => $attempt === $maxAttempts,
                    'top_k' => $topK,
                    'snippet_count' => $retrieved['diagnostics']['snippet_count'] ?? 0,
                    'retrieval_ms' => $retrieved['diagnostics']['duration_ms'] ?? null,
                    'prefetched' => $attempt === 1 && $prefetched !== null,
                ],
            ];
            $snippetDigests = array_slice((array) $retrieved['snippets'], 0, 10);

            $assessmentStarted = microtime(true);
            $response = (new PathwayAgent($guideline))->prompt(
                json_encode([
                    'patient_model' => $patientModel,
                    'current_question' => $turn,
                    'query' => $query,
                    'citation_query' => $citationQuery,
                    'attempt' => $attempt,
                    'final_attempt' => $attempt === $maxAttempts,
                    'snippets' => $retrieved['snippets'],
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
            $citationQuery = 'ESVS recommendation class evidence level decision threshold for: '.$query;
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
