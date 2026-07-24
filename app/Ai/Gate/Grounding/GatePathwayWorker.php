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
     * Laravel's process driver can run up to two branches concurrently.
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
    ): array {
        $query = $initialQuery;
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
            $queriesTried[] = $query;
            $topKIndex = $attempt === $maxAttempts
                ? count($topKCaps) - 1
                : min($attempt - 1, count($topKCaps) - 1);
            $topK = (int) ($topKCaps[$topKIndex] ?? 24);
            if ($attempt === 1 && $prefetched !== null) {
                $retrieved = (array) $prefetched['retrieved'];
                $retrievalDuration = (int) ($prefetched['duration_ms'] ?? 0);
            } else {
                $retrievalStarted = microtime(true);
                $retrieved = $this->retrieval->retrieve(
                    $guideline,
                    $query,
                    $attempt === $maxAttempts,
                    $topK,
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
                    'attempt' => $attempt,
                    'final_attempt' => $attempt === $maxAttempts,
                    'snippets' => $retrieved['snippets'],
                    'retrieval_diagnostics' => $retrieved['diagnostics'],
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                provider: (string) config('gate-v2.provider'),
                model: (string) config('gate-v2.stage_models.pathway', config('gate-v2.model')),
                timeout: max(1, min(60, (int) config('gate-v2.stage_timeouts.pathway', 12))),
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

            if (
                ($assessment['relevant'] ?? false) === true
                && (
                    ($assessment['coverage'] ?? null) === 'covered'
                    || $attempt === $maxAttempts
                )
            ) {
                break;
            }

            $betterQuery = trim((string) ($assessment['better_query'] ?? ''));
            $query = $betterQuery !== '' && ! in_array($betterQuery, $queriesTried, true)
                ? $betterQuery
                : $query.' ESVS recommendation decision threshold anatomy';
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
}
