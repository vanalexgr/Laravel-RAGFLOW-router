<?php

namespace App\Ai\Gate;

use App\Ai\Gate\Evaluation\GateCandidateLedger;
use App\Ai\Gate\Grounding\GatePathwayWorker;
use App\Ai\Gate\Guard\PreOrientGuardService;
use App\Ai\Gate\Progress\GateProgress;
use App\Ai\Gate\Progress\NullGateProgress;
use App\Ai\Gate\Presentation\GateCitationBuilder;
use App\Ai\Gate\Retrieval\GateChunkCleaner;
use App\Ai\Gate\Retrieval\GateEvidenceQuota;
use App\Ai\Gate\Retrieval\GateRetrievalQueryBuilder;
use App\Ai\Gate\Routing\OrientRoutingPriorService;
use App\Ai\Gate\State\PatientModelProjection;
use App\Ai\Gate\State\ShadowStateRecorder;
use App\Services\PHIScrubberService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class GateWorkflowService
{
    /** @var array<int, array<string, mixed>> */
    private array $trace = [];

    /** @var array<string, array<string, mixed>> */
    private array $groundCache = [];

    /** @var array<string, array<string, mixed>> */
    private array $prefetchedGround = [];

    private ?string $prefetchedQuery = null;

    private float $startedAt;

    private int $iteration = 0;

    private int $reservedRevisionSeconds = 0;

    private bool $deadlineActive = false;

    private string $rawTurnText = '';

    /** @var array<int, array<string, mixed>> */
    private array $degradation = [];

    public function __construct(
        private readonly PreOrientGuardService $guard,
        private readonly OrientRoutingPriorService $routing,
        private readonly GatePathwayWorker $pathwayWorker,
        private readonly EvidenceStatusService $evidenceStatus,
        private readonly GateDecisionTail $tail,
        private readonly PHIScrubberService $scrubber,
        private readonly ?GateRetrievalQueryBuilder $queryBuilder = null,
        private readonly ?GateChunkCleaner $chunkCleaner = null,
        private readonly ?GateCitationBuilder $citationBuilder = null,
    ) {}

    /**
     * Exposes the current run trace to diagnostic harnesses when a baseline
     * workflow throws before it can return a normal result.
     *
     * @return array<int, array<string, mixed>>
     */
    public function lastTrace(): array
    {
        return $this->trace;
    }

    /**
     * @param  array<string, mixed>  $priorState
     * @return array<string, mixed>
     */
    public function run(string $turn, array $priorState = [], ?GateProgress $progress = null): array
    {
        $this->trace = [];
        $this->groundCache = [];
        $this->prefetchedGround = [];
        $this->prefetchedQuery = null;
        $this->startedAt = microtime(true);
        $this->iteration = 0;
        $this->reservedRevisionSeconds = 0;
        $this->deadlineActive = true;
        $this->degradation = [];

        [$turn, $priorState] = $this->deidentify($turn, $priorState);
        $this->rawTurnText = $this->accumulateRawTurnText($turn, $priorState);

        return $this->execute($turn, $priorState, $progress ?? new NullGateProgress);
    }

    /** @param array<string, mixed> $priorState */
    private function accumulateRawTurnText(string $turn, array $priorState): string
    {
        $priorTurns = trim((string) ($priorState['raw_turn_text'] ?? ''));

        return $priorTurns === '' ? $turn : $priorTurns."\n".$turn;
    }

    /**
     * HIPAA Safe Harbor de-identification for the whole gate, applied once here.
     *
     * Every downstream model call — Orient, Pathway, Probe, Critic, Knowledge,
     * and Laravel-side synthesis — is reached from this turn and the state it
     * produces, so scrubbing at the entry point covers all of them and cannot be
     * bypassed by adding a stage. Scrubbing at each egress instead would mean
     * running the scrubber over serialized JSON, where a replacement can corrupt
     * the payload, and would leave every new call site as a fresh way to leak.
     *
     * Prior state is scrubbed too: it round-trips through the client between
     * turns, so it is caller-controlled input rather than something the gate can
     * assume it already cleaned.
     *
     * @param  array<string, mixed>  $priorState
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function deidentify(string $turn, array $priorState): array
    {
        $result = $this->scrubber->scrub($turn);
        $scrubbedTurn = (string) $result['scrubbed_text'];
        $scrubbedState = $this->scrubStrings($priorState);

        if ($result['was_modified'] || $scrubbedState !== $priorState) {
            $correlationId = substr(hash('sha256', (string) $this->startedAt), 0, 8);
            $this->scrubber->logAudit($correlationId, $result);
            // Counts only — the trace is returned to the caller and logged.
            $this->record('phi_scrub', 0, [
                'redaction_counts' => array_filter((array) $result['redaction_counts']),
                'prior_state_modified' => $scrubbedState !== $priorState,
            ]);
        }

        if (($result['names_dictionary_loaded'] ?? false) !== true) {
            Log::channel('retrieval')->warning(
                '[PHI SCRUBBER] Name redaction unavailable; gate turn sent to the model without it.',
                ['dictionary' => config('phi.files.common_names')],
            );
        }

        return [$scrubbedTurn, $scrubbedState];
    }

    /**
     * Scrub every string in a nested structure, leaving keys and non-strings
     * alone. Keys are gate-defined, and the numeric fields are counters.
     *
     * @param  array<mixed, mixed>  $values
     * @return array<mixed, mixed>
     */
    private function scrubStrings(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->scrubStrings($value);
            } elseif (is_string($value) && $value !== '') {
                $values[$key] = (string) $this->scrubber->scrub($value)['scrubbed_text'];
            }
        }

        return $values;
    }

    /**
     * The turn body, separated from run() so an escalating knowledge turn can
     * re-enter the deep path on the *same* wall-clock budget and trace. Calling
     * run() recursively restarted both, handing the escalated turn a second full
     * deadline and discarding the evidence of how long the first path took.
     *
     * @param  array<string, mixed>  $priorState
     * @return array<string, mixed>
     */
    private function execute(string $turn, array $priorState, GateProgress $progress): array
    {
        $guard = $this->guard->evaluate($turn, $priorState !== []);
        if ($guard['blocked']) {
            $this->record('guard', 0, ['mode' => $guard['mode']]);

            return [
                'mode' => $guard['mode'],
                'decision' => 'proceed',
                'answer_markdown' => $guard['response'],
                'stage_trace' => $this->trace,
                'state' => $priorState,
            ];
        }

        $progress->emit('orient', '🧭 Framing and routing the case…');
        try {
            $orient = $this->orientWithPrefetch($turn, $priorState);
        } catch (Throwable $exception) {
            if (! $this->isUpstreamTimeout($exception)) {
                throw $exception;
            }

            return $this->orientFailureResult($priorState, $exception, $progress);
        }
        if ($priorState === []) {
            $orient['same_case'] = null;
        }
        if (($priorState['_force_case'] ?? false) === true) {
            $orient['mode'] = 'case_followup_substantive';
        }

        if ($orient['mode'] === 'knowledge') {
            return $this->knowledgePath($turn, $orient, $priorState, $progress);
        }

        $this->reservedRevisionSeconds = max(
            0,
            min(60, (int) config('gate-v2.revision_reserve_seconds', 35)),
        );
        $progress->emit('retrieve', '🔍 Preparing the selected guideline searches…');
        $ground = $this->ground($turn, $orient, [], $progress);
        $evidenceStatus = $this->evidenceStatus->assess($turn, $ground['pathways']);
        $probe = $this->probe($turn, $orient, $ground, $evidenceStatus, [], $priorState);

        $candidate = compact('orient', 'ground', 'evidenceStatus', 'probe');
        $ledger = new GateCandidateLedger;
        $budgets = config('gate-v2.bounce_budgets');
        $seenBounceFingerprints = [];
        $lastCritic = null;
        $criticFailure = null;
        $maxIterations = max(1, (int) config('gate-v2.max_iterations', 3));

        for ($this->iteration = 1; $this->iteration <= $maxIterations; $this->iteration++) {
            try {
                $progress->emit('evaluate', '🩺 Weighing the case against the retrieved recommendations…', [
                    'iteration' => $this->iteration,
                ]);
                $lastCritic = $this->critic($turn, $candidate, $priorState);
            } catch (Throwable $exception) {
                $criticFailure = [
                    'stage' => 'critic',
                    'reason' => $this->failureReason($exception),
                    'unavailable' => ['Critic evaluation and score'],
                ];
                $this->record('decide', 0, [
                    'reason' => 'critic_skipped_accept_unevaluated_candidate',
                    'error' => $exception->getMessage(),
                ]);
                break;
            }
            $ledger->consider($candidate, $lastCritic);
            // The first complete orient → ground → probe → critic pass must
            // always produce a scored candidate. Only revisions compete for
            // the original wall-clock budget.
            $this->deadlineActive = true;
            $this->reservedRevisionSeconds = 0;

            if (($lastCritic['approved'] ?? false) === true) {
                break;
            }

            $stage = (string) ($lastCritic['revise_stage'] ?? 'none');
            if (! isset($budgets[$stage]) || $budgets[$stage] <= 0) {
                $this->record('decide', 0, ['reason' => 'bounce_budget_exhausted', 'stage' => $stage]);
                break;
            }

            $fingerprint = hash('sha256', json_encode([
                $stage,
                array_column((array) ($lastCritic['issues'] ?? []), 'invariant'),
            ]));
            if (isset($seenBounceFingerprints[$fingerprint])) {
                $this->record('decide', 0, ['reason' => 'oscillation_detected', 'stage' => $stage]);
                break;
            }
            $seenBounceFingerprints[$fingerprint] = true;
            $budgets[$stage]--;
            $issues = (array) ($lastCritic['issues'] ?? []);
            $minimumRevisionSeconds = (int) config("gate-v2.minimum_revision_seconds.{$stage}", 15);
            if ($this->remainingWallSeconds() < $minimumRevisionSeconds) {
                $this->record('decide', 0, [
                    'reason' => 'insufficient_revision_budget',
                    'stage' => $stage,
                    'remaining_seconds' => $this->remainingWallSeconds(),
                    'required_seconds' => $minimumRevisionSeconds,
                ]);
                break;
            }

            $progress->emit('revise', '⚠️ Evidence needs another look — re-checking…', [
                'iteration' => $this->iteration,
            ]);

            try {
                if ($stage === 'orient_route') {
                    $candidate['orient'] = $this->orient($turn, $priorState, $issues);
                    $candidate['ground'] = $this->ground($turn, $candidate['orient'], $issues, $progress);
                    $candidate['evidenceStatus'] = $this->evidenceStatus->assess(
                        $turn,
                        $candidate['ground']['pathways'],
                    );
                } elseif ($stage === 'ground') {
                    $candidate['ground'] = $this->ground($turn, $candidate['orient'], $issues, $progress);
                    $candidate['evidenceStatus'] = $this->evidenceStatus->assess(
                        $turn,
                        $candidate['ground']['pathways'],
                    );
                }

                $candidate['probe'] = $this->probe(
                    $turn,
                    $candidate['orient'],
                    $candidate['ground'],
                    $candidate['evidenceStatus'],
                    $issues,
                    $priorState,
                );
            } catch (Throwable $exception) {
                $this->record('decide', 0, [
                    'reason' => 'revision_or_deadline_failure',
                    'stage' => $stage,
                    'error' => $exception->getMessage(),
                ]);
                break;
            }
        }

        try {
            $best = $ledger->best();
            $bestCandidate = $best['candidate'];
            $bestCritic = $best['critic'];
            $bestScore = (float) $bestCritic['score'];
            if ($criticFailure !== null) {
                $this->addDegradation($criticFailure);
            }
        } catch (RuntimeException) {
            $bestCandidate = $candidate;
            $bestCritic = [
                'status' => 'not_evaluated',
                'approved' => null,
                'score' => null,
                'issues' => [],
            ];
            $bestScore = null;
            $this->addDegradation([
                'stage' => 'critic',
                'reason' => 'no_candidate_scored',
                'cause' => $criticFailure['reason'] ?? 'critic_unavailable',
                'unavailable' => ['Critic evaluation and score'],
            ]);
            $this->record('decide', 0, [
                'reason' => 'fallback_to_unevaluated_probe_candidate',
            ]);
        }
        $promptSnippets = $this->promptSnippetDigests(
            $bestCandidate['ground']['snippet_digests'],
        );
        $final = $this->tail->finalize(
            $bestCandidate['probe'],
            (array) ($bestCandidate['orient']['open_questions'] ?? []),
            $this->degradation,
            ($this->citationBuilder ?? new GateCitationBuilder)->build($promptSnippets),
        );
        $this->record('decide', 0, [
            'decision' => $final['decision'],
            'best_score' => $bestScore,
            'iterations' => min($this->iteration, $maxIterations),
        ]);
        $progress->emit('done', '✅ Gate reasoning complete.');
        $openQuestions = $this->mergeOpenQuestions(
            (array) $bestCandidate['orient']['open_questions'],
            (array) $final['questions'],
        );

        return array_merge($final, [
            'mode' => $bestCandidate['orient']['mode'],
            'same_case' => $bestCandidate['orient']['same_case'],
            'patient_model' => $bestCandidate['orient']['patient_model'],
            'changed_fields' => $bestCandidate['orient']['changed_fields'],
            'routed_guidelines' => $bestCandidate['orient']['candidate_guidelines'],
            'pathways' => $bestCandidate['ground']['pathways'],
            'queries_tried' => $bestCandidate['ground']['queries_tried'],
            'snippet_digests' => $this->auditSnippetDigests(
                $bestCandidate['ground']['snippet_digests'],
            ),
            'citations' => $final['citations'],
            'critic' => $bestCritic,
            'best_score' => $bestScore,
            'iterations' => min($this->iteration, $maxIterations),
            'stage_trace' => $this->trace,
            'state' => [
                'patient_model' => $bestCandidate['orient']['patient_model'],
                'core_question' => $bestCandidate['orient']['core_question'],
                'expansion_terms' => $bestCandidate['orient']['expansion_terms'],
                'interpretation_terms' => $bestCandidate['orient']['interpretation_terms'],
                'must_include_terms' => $bestCandidate['orient']['must_include_terms'],
                'provenance' => $bestCandidate['orient']['provenance'],
                'open_questions' => $openQuestions,
                'assumptions' => array_values(array_map(
                    static fn (array $question): string => (string) ($question['answer'] ?? 'Declined: '.$question['question']),
                    array_filter(
                        $openQuestions,
                        static fn (array $question): bool => ($question['status'] ?? null) === 'declined',
                    ),
                )),
                'candidate_guidelines' => $bestCandidate['orient']['candidate_guidelines'],
                'raw_turn_text' => $this->rawTurnText,
                'turn_index' => (int) ($priorState['turn_index'] ?? 0) + 1,
                'version' => (int) ($priorState['version'] ?? 0) + 1,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $priorState
     * @param  array<int, array<string, mixed>>  $issues
     * @return array<string, mixed>
     */
    private function orient(string $turn, array $priorState, array $issues): array
    {
        $started = microtime(true);
        $context = $this->orientContext($turn, $priorState, $issues);
        $response = $this->prompt(new OrientAgent, $context['payload']);
        $response = $this->finalizeOrient(
            $response,
            $context['signals'],
            $context['deterministic_candidates'],
            $turn,
            $priorState,
        );
        $this->recordShadowState($turn, $priorState, $response);
        $this->record('orient', $started, [
            'mode' => $response['mode'] ?? null,
            'guidelines' => $response['candidate_guidelines'],
        ]);

        return $response;
    }

    /**
     * Register shadow persistence for response termination. It therefore runs
     * after the answer has been produced and cannot consume the clinical
     * deadline, mutate the returned trace, or feed any downstream stage.
     *
     * @param  array<string, mixed>  $priorState
     * @param  array<string, mixed>  $orient
     */
    private function recordShadowState(string $turn, array $priorState, array $orient): void
    {
        if (config('gate-state.shadow_enabled', false) !== true) {
            return;
        }

        try {
            app()->terminating(function () use ($turn, $priorState, $orient): void {
                try {
                    (new ShadowStateRecorder)->record($turn, $priorState, $orient);
                } catch (Throwable $exception) {
                    $this->logShadowFailure($exception);
                }
            });
        } catch (Throwable $exception) {
            $this->logShadowFailure($exception);
        }
    }

    private function logShadowFailure(Throwable $exception): void
    {
        try {
            Log::channel('retrieval')->error('[GATE STATE SHADOW] Recorder failed.', [
                'error_type' => $exception::class,
                'error_digest' => substr(hash('sha256', $exception->getMessage()), 0, 16),
            ]);
        } catch (Throwable) {
            // Logging is best-effort; shadow diagnostics must never escape.
        }
    }

    /**
     * @param  array<string, mixed>  $priorState
     * @return array<string, mixed>
     */
    private function orientWithPrefetch(string $turn, array $priorState): array
    {
        // Retrieval now depends on Orient's English core_question, normalized
        // patient model, and expansion terms. Starting it speculatively from the
        // raw turn would preserve the exact JSON/query defect R7.1 removes.
        return $this->orient($turn, $priorState, []);
    }

    /**
     * @param  array<string, mixed>  $priorState
     * @param  array<int, array<string, mixed>>  $issues
     * @return array{signals: array<string, bool>, deterministic_candidates: array<int, string>, payload: array<string, mixed>}
     */
    private function orientContext(string $turn, array $priorState, array $issues): array
    {
        $signals = $this->routing->turnSignals($turn);
        $deterministicCandidates = $this->routing->candidates(
            json_encode([$priorState['patient_model'] ?? [], $turn]) ?: $turn,
        );

        return [
            'signals' => $signals,
            'deterministic_candidates' => $deterministicCandidates,
            'payload' => [
                'current_turn' => $turn,
                'turn_index' => (int) ($priorState['turn_index'] ?? 0) + 1,
                'prior_state' => $priorState,
                'turn_signals' => $signals,
                'deterministic_candidate_priors' => $deterministicCandidates,
                'guideline_reference' => OrientRoutingPriorService::GUIDELINE_REFERENCE,
                'critic_issues' => $issues,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, bool>  $signals
     * @param  array<int, string>  $deterministicCandidates
     * @param  array<string, mixed>  $priorState
     * @return array<string, mixed>
     */
    private function finalizeOrient(
        array $response,
        array $signals,
        array $deterministicCandidates,
        string $turn,
        array $priorState,
    ): array {
        foreach ([
            'patient_model',
            'core_question',
            'expansion_terms',
            'interpretation_terms',
            'must_include_terms',
            'open_questions',
            'provenance',
            'changed_fields',
        ] as $required) {
            if (! array_key_exists($required, $response)) {
                throw new RuntimeException("OrientAgent response is missing required field: {$required}.");
            }
        }
        if (! isset($response['response_mode'])) {
            $response['response_mode'] = $this->fallbackResponseMode($turn);
            $this->record('orient_fallback', 0, ['field' => 'response_mode']);
        }
        // Carry decomposed clinical facts across turns before anything reads the model.
        // Orient re-derives from the latest turn alone, so a turn that does not restate
        // the diameter would otherwise drop it permanently — measured on the AAA case,
        // where turn 2 replaced the whole lesion string and lost "5.8 cm".
        $priorModel = (array) ($priorState['patient_model'] ?? []);
        if ($priorModel !== []) {
            $suppressed = [];
            $response['patient_model'] = PatientModelProjection::merge(
                $priorModel,
                (array) ($response['patient_model'] ?? []),
                $turn,
                $suppressed,
            );
            // The turn discussed a fact the extraction did not return. Carrying the
            // old value forward would answer a premise-changing question against
            // stale clinical state while looking complete, so the field is left
            // empty and the omission is declared rather than papered over.
            if ($suppressed !== []) {
                $this->addDegradation([
                    'stage' => 'orient',
                    'reason' => 'extraction_missed_stated_fact',
                    'unavailable' => $suppressed,
                ]);
                $this->record('orient_fallback', 0, [
                    'reason' => 'retention_suppressed',
                    'fields' => $suppressed,
                ]);
            }
        }
        $response['mode'] = $this->constrainMode(
            (string) ($response['mode'] ?? 'case_new'),
            $signals,
            $priorState,
        );
        $response['candidate_guidelines'] = $this->constrainCandidates(
            json_encode($response['patient_model'] ?? []) ?: $turn,
            $deterministicCandidates,
            (array) ($response['candidate_guidelines'] ?? []),
        );

        return $response;
    }

    private function fallbackResponseMode(string $turn): string
    {
        return match (true) {
            preg_match('/\b(?:surveillance|follow[- ]?up|monitor(?:ing)?)\b/iu', $turn) === 1 => 'surveillance',
            preg_match('/\b(?:diagnos|workup|investigat|imaging|criteria)\b/iu', $turn) === 1 => 'diagnostic',
            preg_match('/\b(?:manage|management|treat(?:ed|ment|ing)?|therapy|intervention|operate)\b/iu', $turn) === 1 => 'management',
            default => 'case',
        };
    }

    private function guidelineProgressLabel(string $guidelineKey): string
    {
        $name = match ($guidelineKey) {
            'aaa' => 'AAA',
            'clti' => 'CLTI',
            default => str_replace('_', ' ', $guidelineKey),
        };

        return $name.' guidelines';
    }

    /** @param array<string, mixed> $result */
    private function emitRetrievalRecheck(
        ?GateProgress $progress,
        string $guideline,
        array $result,
    ): void {
        if ($progress === null) {
            return;
        }

        $attempts = array_values(array_filter(array_map(
            static fn (mixed $entry): int => is_array($entry)
                ? (int) ($entry['detail']['attempt'] ?? 0)
                : 0,
            (array) ($result['trace'] ?? []),
        )));
        $attempt = $attempts === [] ? 1 : max($attempts);
        if ($attempt <= 1) {
            return;
        }

        $progress->emit(
            'revise',
            '⚠️ Evidence looked thin — re-checked '.$this->guidelineProgressLabel($guideline).'.',
            ['guideline' => $guideline, 'attempt' => $attempt],
        );
    }

    /**
     * @param  array<string, mixed>  $orient
     * @param  array<int, array<string, mixed>>  $issues
     * @return array{pathways: array<int, array<string, mixed>>, queries_tried: array<string, array<int, string>>, snippet_digests: array<string, array<int, array<string, mixed>>>, degradation: array<int, array<string, mixed>>}
     */
    private function ground(
        string $turn,
        array $orient,
        array $issues,
        ?GateProgress $progress = null,
    ): array
    {
        $allPathways = [];
        $queriesTried = [];
        $snippetDigests = [];
        $degradation = [];
        $guidelines = array_slice((array) ($orient['candidate_guidelines'] ?? []), 0, 3);
        $usePrefetch = $this->iteration === 0 && $issues === [] && $this->prefetchedQuery !== null;
        $maxAttempts = $issues === []
            ? null
            : max(1, (int) config('gate-v2.retrieval.revision_max_attempts', 1));
        $queryPair = $this->serializeRetrievalQuery($orient, $issues);
        $query = $queryPair['narrative'];
        $citationQuery = $queryPair['citation'];
        $citationQueries = $queryPair['citation_queries'];
        $this->assertWithinDeadline();
        $results = [];
        $pending = [];
        foreach ($guidelines as $guideline) {
            $cacheKey = $this->groundCacheKey(
                $guideline,
                $query,
                $citationQuery,
                $citationQueries,
                (array) $orient['patient_model'],
            );
            if (isset($this->groundCache[$cacheKey])) {
                $results[$guideline] = $this->groundCache[$cacheKey];
                $this->record('ground_cache', 0, ['guideline' => $guideline, 'hit' => true]);
            } else {
                $pending[$guideline] = $cacheKey;
            }
        }

        if ((string) config('gate-v2.deep_path_mode', 'parallel') === 'parallel' && count($pending) > 1) {
            $started = microtime(true);
            $tasks = [];
            $runtimeConfig = $this->parallelRetrievalRuntimeConfig();
            // Forked branches cannot read the parent's elapsed time, so bound
            // them by the same absolute deadline the sequential path respects.
            // Process workers also bootstrap a fresh application. Explicitly carry
            // retrieval switches that may have been supplied to the long-running
            // SUT process at launch; otherwise only multi-guideline requests fall
            // back to the checkout's .env values.
            $deadlineAt = $this->deadlineAt();
            $remaining = $this->remainingWallSeconds();
            foreach (array_keys($pending) as $guideline) {
                $progress?->emit('retrieve', '🔍 Searching '.$this->guidelineProgressLabel($guideline).'…', [
                    'guideline' => $guideline,
                    'attempt' => $issues === [] ? 1 : $this->iteration + 1,
                ]);
                $patientModel = (array) $orient['patient_model'];
                $prefetched = $usePrefetch ? ($this->prefetchedGround[$guideline] ?? null) : null;
                $tasks[$guideline] = static function () use (
                    $runtimeConfig,
                    $guideline,
                    $query,
                    $patientModel,
                    $turn,
                    $prefetched,
                    $maxAttempts,
                    $remaining,
                    $deadlineAt,
                    $citationQuery,
                    $citationQueries,
                ): array {
                    self::applyParallelRetrievalRuntimeConfig($runtimeConfig);

                    return app(GatePathwayWorker::class)->run(
                        $guideline,
                        $query,
                        $patientModel,
                        $turn,
                        $prefetched,
                        $maxAttempts,
                        max(1, min(
                            (int) config('gate-v2.retrieval.timeout_seconds', 20),
                            $remaining,
                        )),
                        $deadlineAt,
                        $citationQuery,
                        $citationQueries,
                    );
                };
            }
            $completed = Concurrency::driver((string) config('gate-v2.concurrency_driver', 'process'))
                ->run($tasks);
            $this->record('ground_parallel', $started, ['guidelines' => array_keys($pending)]);
            $results = array_merge($results, $completed);
            $completedCount = count($guidelines) - count($pending);
            foreach (array_keys($completed) as $guideline) {
                $completedCount++;
                $this->emitRetrievalRecheck($progress, $guideline, (array) $completed[$guideline]);
                $progress?->emit(
                    'retrieve',
                    "Gathered evidence from {$completedCount} of ".count($guidelines).' guidelines.',
                    [
                        'guideline' => $guideline,
                        'completed' => $completedCount,
                        'total' => count($guidelines),
                    ],
                );
            }
        } else {
            $completedCount = count($guidelines) - count($pending);
            foreach (array_keys($pending) as $guideline) {
                $this->assertWithinDeadline();
                $progress?->emit('retrieve', '🔍 Searching '.$this->guidelineProgressLabel($guideline).'…', [
                    'guideline' => $guideline,
                    'attempt' => $issues === [] ? 1 : $this->iteration + 1,
                ]);
                $results[$guideline] = $this->pathwayWorker->run(
                    $guideline,
                    $query,
                    (array) $orient['patient_model'],
                    $turn,
                    $usePrefetch ? ($this->prefetchedGround[$guideline] ?? null) : null,
                    $maxAttempts,
                    max(1, min(
                        (int) config('gate-v2.retrieval.timeout_seconds', 20),
                        $this->remainingWallSeconds(),
                    )),
                    $this->deadlineAt(),
                    $citationQuery,
                    $citationQueries,
                );
                $completedCount++;
                $this->emitRetrievalRecheck($progress, $guideline, (array) $results[$guideline]);
                $progress?->emit(
                    'retrieve',
                    "Gathered evidence from {$completedCount} of ".count($guidelines).' guidelines.',
                    [
                        'guideline' => $guideline,
                        'completed' => $completedCount,
                        'total' => count($guidelines),
                    ],
                );
            }
        }
        $this->prefetchedGround = [];
        $this->prefetchedQuery = null;
        foreach ($pending as $guideline => $cacheKey) {
            if (isset($results[$guideline])) {
                $this->groundCache[$cacheKey] = $results[$guideline];
            }
        }
        $this->assertWithinDeadline();

        foreach ($guidelines as $guideline) {
            if (! isset($results[$guideline])) {
                $failure = [
                    'stage' => 'pathway',
                    'reason' => 'branch_unavailable',
                    'guideline' => $guideline,
                    'unavailable' => ['retrieved evidence and pathway assessment for '.$guideline],
                ];
                $degradation[] = $failure;
                $this->addDegradation($failure);

                continue;
            }
            $result = $results[$guideline];
            $guideline = (string) $result['guideline'];
            $queriesTried[$guideline] = (array) $result['queries_tried'];
            $snippetDigests[$guideline] = (array) $result['snippet_digests'];
            foreach ((array) $result['trace'] as $entry) {
                $this->recordExternal($entry);
            }
            foreach ((array) ($result['degradation'] ?? []) as $failure) {
                if (is_array($failure)) {
                    $degradation[] = $failure;
                    $this->addDegradation($failure);
                }
            }
            if (is_array($result['assessment'] ?? null)) {
                $allPathways[] = $result['assessment'];
            }
        }

        if ($degradation !== []) {
            $evidenceGuidelines = array_values(array_keys(array_filter(
                $snippetDigests,
                static fn (array $snippets): bool => $snippets !== [],
            )));
            $summary = [
                'stage' => 'grounding',
                'reason' => 'partial_evidence',
                'routed_branch_count' => count($guidelines),
                'evidence_branch_count' => count($evidenceGuidelines),
                'available_guidelines' => $evidenceGuidelines,
                'unavailable_guidelines' => array_values(array_diff($guidelines, $evidenceGuidelines)),
                'unavailable' => ['complete routed-guideline evidence'],
            ];
            $degradation[] = $summary;
            $this->addDegradation($summary);
        }

        return [
            'pathways' => $allPathways,
            'queries_tried' => $queriesTried,
            'snippet_digests' => $snippetDigests,
            'degradation' => $degradation,
        ];
    }

    /**
     * Non-secret runtime retrieval values that a process worker must inherit from
     * its parent. In particular, eval SUTs can select the local bridge reranker at
     * process launch without changing the disposable checkout's .env.
     *
     * @return array<string, mixed>
     */
    private function parallelRetrievalRuntimeConfig(): array
    {
        return [
            'gate-v2.retrieval' => config('gate-v2.retrieval'),
            'ragflow.retrieval' => config('ragflow.retrieval'),
            'ragflow.lean' => config('ragflow.lean'),
            'ragflow.single_case' => config('ragflow.single_case'),
            'ragflow.bridge_rerank.enabled' => config('ragflow.bridge_rerank.enabled'),
            'ragflow.request_timeout' => config('ragflow.request_timeout'),
            'ragflow.connect_timeout' => config('ragflow.connect_timeout'),
        ];
    }

    /** @param  array<string, mixed>  $runtimeConfig */
    private static function applyParallelRetrievalRuntimeConfig(array $runtimeConfig): void
    {
        foreach ($runtimeConfig as $key => $value) {
            config()->set($key, $value);
        }
    }

    /**
     * @param  array<string, mixed>  $patientModel
     */
    private function groundCacheKey(
        string $guideline,
        string $query,
        string $citationQuery,
        array $citationQueries,
        array $patientModel,
    ): string {
        return hash('sha256', json_encode(
            [$guideline, $query, $citationQuery, $citationQueries, $patientModel],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * @param  array<string, mixed>  $orient
     * @param  array<string, mixed>  $ground
     * @param  array<string, mixed>  $evidenceStatus
     * @param  array<int, array<string, mixed>>  $issues
     * @param  array<string, mixed>  $priorState
     * @return array<string, mixed>
     */
    private function probe(
        string $turn,
        array $orient,
        array $ground,
        array $evidenceStatus,
        array $issues,
        array $priorState,
    ): array {
        $payload = [
            'current_question' => $turn,
            'patient_model' => $orient['patient_model'],
            'response_mode' => $orient['response_mode'],
            'house_sections' => ['ESVS-grounded answer', 'Interpretation'],
            'pathways' => $ground['pathways'],
            'source_snippets' => $this->promptSnippetDigests($ground['snippet_digests']),
            'evidence_status' => $evidenceStatus,
            'open_questions' => $orient['open_questions'],
            'prior_assumptions' => $priorState['assumptions'] ?? [],
            'critic_issues' => $issues,
        ];

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            if (! $this->canStartStage('probe')) {
                $this->addDegradation([
                    'stage' => 'probe',
                    'reason' => 'insufficient_budget',
                    'attempts' => $attempt - 1,
                    'unavailable' => ['LLM-synthesized answer'],
                ]);
                $this->record('probe_skipped', 0, [
                    'attempt' => $attempt,
                    'remaining_seconds' => $this->remainingSeconds(),
                ]);
                break;
            }

            $started = microtime(true);
            try {
                $response = $this->prompt(new ProbeAgent, $payload);
                $this->record('probe', $started, [
                    'attempt' => $attempt,
                    'questions' => count((array) ($response['questions'] ?? [])),
                    'coverage' => $response['evidence_status']['coverage'] ?? null,
                ]);

                return $response;
            } catch (Throwable $exception) {
                if (! $this->isUpstreamTimeout($exception)) {
                    throw $exception;
                }
                $this->record('probe_failed', $started, [
                    'attempt' => $attempt,
                    'reason' => 'upstream_timeout',
                    'will_retry' => $attempt === 1 && $this->canStartStage('probe'),
                ]);
                if ($attempt === 1 && $this->canStartStage('probe')) {
                    continue;
                }
                $this->addDegradation([
                    'stage' => 'probe',
                    'reason' => 'upstream_timeout',
                    'attempts' => $attempt,
                    'unavailable' => ['LLM-synthesized answer'],
                ]);
                break;
            }
        }

        $this->record('probe_fallback', 0, ['reason' => 'evidence_only_response']);

        return $this->evidenceOnlyProbe($orient, $ground, $evidenceStatus);
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $priorState
     * @return array<string, mixed>
     */
    private function critic(string $turn, array $candidate, array $priorState): array
    {
        $started = microtime(true);
        $response = $this->prompt(new CriticAgent, [
            'current_question' => $turn,
            'prior_patient_model' => $priorState['patient_model'] ?? null,
            'open_questions' => $candidate['orient']['open_questions'],
            'orient' => [
                'mode' => $candidate['orient']['mode'],
                'same_case' => $candidate['orient']['same_case'],
                'patient_model' => $candidate['orient']['patient_model'],
                'changed_fields' => $candidate['orient']['changed_fields'],
                'candidate_guidelines' => $candidate['orient']['candidate_guidelines'],
            ],
            'pathways' => $candidate['ground']['pathways'],
            'source_snippet_digests' => $this->promptSnippetDigests(
                $candidate['ground']['snippet_digests'],
            ),
            'probe' => $candidate['probe'],
        ]);
        $this->record('critic', $started, [
            'approved' => $response['approved'] ?? null,
            'score' => $response['score'] ?? null,
            'revise_stage' => $response['revise_stage'] ?? null,
        ]);

        return $response;
    }

    /**
     * @param  array<string, mixed>  $orient
     * @param  array<string, mixed>  $priorState
     * @return array<string, mixed>
     */
    private function knowledgePath(
        string $turn,
        array $orient,
        array $priorState,
        GateProgress $progress,
    ): array {
        $progress->emit('knowledge_fast', '🧭 Straightforward question — answering directly.');
        $ground = $this->ground($turn, $orient, [], $progress);
        $evidenceStatus = $this->evidenceStatus->assess($turn, $ground['pathways']);
        $started = microtime(true);
        $promptSnippets = $this->promptSnippetDigests($ground['snippet_digests']);
        try {
            $answer = $this->prompt(new KnowledgeAnswerAgent, [
                'current_question' => $turn,
                'patient_model_digest' => $orient['patient_model'],
                'snippets' => $promptSnippets,
                'evidence_status' => $evidenceStatus,
            ]);
        } catch (Throwable $exception) {
            if (! $this->isUpstreamTimeout($exception)
                && ! str_contains($exception->getMessage(), 'insufficient remaining budget')) {
                throw $exception;
            }
            $this->addDegradation([
                'stage' => 'knowledge',
                'reason' => $this->failureReason($exception),
                'unavailable' => ['LLM-synthesized knowledge answer'],
            ]);
            $answer = $this->evidenceOnlyProbe($orient, $ground, $evidenceStatus);
            $this->record('knowledge_fallback', 0, ['reason' => 'evidence_only_response']);
        }
        $this->record('knowledge', $started, [
            'escalate' => $answer['escalate'] ?? false,
            'coverage' => $answer['evidence_status']['coverage'] ?? null,
        ]);

        if (($answer['escalate'] ?? false) === true) {
            $forcedState = $priorState;
            $forcedState['_force_case'] = true;
            $this->record('knowledge_escalated', 0, [
                'remaining_seconds' => $this->remainingWallSeconds(),
            ]);

            return $this->execute($turn, $forcedState, $progress);
        }

        $final = $this->tail->finalize(
            $answer + ['unknowns' => [], 'questions' => []],
            [],
            $this->degradation,
            ($this->citationBuilder ?? new GateCitationBuilder)->build($promptSnippets),
        );
        $progress->emit('done', '✅ Gate reasoning complete.');

        return array_merge($final, [
            'mode' => 'knowledge',
            'same_case' => $orient['same_case'],
            'patient_model' => $orient['patient_model'],
            'routed_guidelines' => $orient['candidate_guidelines'],
            'pathways' => $ground['pathways'],
            'queries_tried' => $ground['queries_tried'],
            'snippet_digests' => $this->auditSnippetDigests($ground['snippet_digests']),
            'citations' => $final['citations'],
            'iterations' => 0,
            'stage_trace' => $this->trace,
            'state' => [
                'patient_model' => $orient['patient_model'],
                'core_question' => $orient['core_question'],
                'expansion_terms' => $orient['expansion_terms'],
                'interpretation_terms' => $orient['interpretation_terms'],
                'must_include_terms' => $orient['must_include_terms'],
                'provenance' => $orient['provenance'],
                'open_questions' => $orient['open_questions'],
                'candidate_guidelines' => $orient['candidate_guidelines'],
                'raw_turn_text' => $this->rawTurnText,
                'turn_index' => (int) ($priorState['turn_index'] ?? 0) + 1,
                'version' => (int) ($priorState['version'] ?? 0) + 1,
            ],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lifecycle
     * @param  array<int, array<string, mixed>>  $asked
     * @return array<int, array<string, mixed>>
     */
    private function mergeOpenQuestions(array $lifecycle, array $asked): array
    {
        $known = array_map(
            fn (array $question): string => $this->normalizeQuestion((string) ($question['question'] ?? '')),
            $lifecycle,
        );

        foreach ($asked as $question) {
            $text = trim((string) ($question['question'] ?? ''));
            if ($text === '' || in_array($this->normalizeQuestion($text), $known, true)) {
                continue;
            }
            $lifecycle[] = [
                'question' => $text,
                'status' => 'pending',
                'answer' => '',
            ];
            $known[] = $this->normalizeQuestion($text);
        }

        return array_values($lifecycle);
    }

    private function normalizeQuestion(string $question): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $question) ?? $question), 'UTF-8');
    }

    /**
     * @param  array<int, string>  $deterministic
     * @param  array<int, string>  $model
     * @return array<int, string>
     */
    private function constrainCandidates(string $patientModel, array $deterministic, array $model): array
    {
        return $this->routing->candidates(
            $patientModel,
            $deterministic !== [] ? $deterministic : $model,
        );
    }

    /**
     * @param  array<string, bool>  $signals
     * @param  array<string, mixed>  $priorState
     */
    private function constrainMode(string $modelMode, array $signals, array $priorState): string
    {
        if (($signals['specific_patient'] ?? false) && $priorState === []) {
            return 'case_new';
        }

        if (($signals['specific_patient'] ?? false) && $modelMode === 'knowledge') {
            return 'case_followup_substantive';
        }

        return $modelMode;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function prompt(object $agent, array $payload): array
    {
        $this->assertWithinDeadline();
        $stage = match ($agent::class) {
            OrientAgent::class => 'orient',
            ProbeAgent::class => 'probe',
            CriticAgent::class => 'critic',
            KnowledgeAnswerAgent::class => 'knowledge',
            default => 'default',
        };
        if (! $this->canStartStage($stage)) {
            throw new RuntimeException(
                "{$stage} skipped: insufficient remaining budget for a model call.",
            );
        }
        $response = $agent->prompt(
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            provider: (string) config('gate-v2.provider'),
            model: (string) config("gate-v2.stage_models.{$stage}", config('gate-v2.model')),
            timeout: max(1, min(
                (int) config("gate-v2.stage_timeouts.{$stage}", 15),
                $this->remainingSeconds(),
            )),
        );

        $structured = $response->toArray();
        if ($structured === []) {
            throw new RuntimeException($agent::class.' returned an empty structured response.');
        }

        return $structured;
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $digests
     * @return array<string, array<int, array<string, mixed>>>
     */
    /**
     * Ranked evidence exactly as the stages saw it, for post-hoc adjudication of a
     * coverage verdict. `snippet_count` alone cannot distinguish "reasoned badly over
     * good evidence" from "the evidence was irrelevant", and that ambiguity is what
     * blocked the Run 7 coverage audit.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $digests
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function auditSnippetDigests(array $digests): array
    {
        if (config('gate-v2.audit.persist_snippet_digests', false) !== true) {
            return [];
        }

        $maxPerGuideline = max(1, (int) config('gate-v2.audit.snippet_digest_max_per_guideline', 6));
        $maxChars = max(120, (int) config('gate-v2.audit.snippet_digest_max_chars', 600));
        $cleaner = $this->chunkCleaner ?? new GateChunkCleaner;

        foreach ($digests as $guideline => $snippets) {
            $audited = [];
            foreach (array_slice($snippets, 0, $maxPerGuideline) as $rank => $snippet) {
                $audited[] = [
                    'rank' => $rank + 1,
                    // Persisted so an eval can count recommendations directly rather
                    // than sniffing the identity-header prefix.
                    'bucket' => $snippet['bucket'] ?? null,
                    'similarity' => $snippet['similarity'] ?? null,
                    'source' => $snippet['source'] ?? null,
                    'metadata' => $snippet['metadata'] ?? [],
                    'signal_ratio' => $snippet['signal_ratio'] ?? null,
                    'text' => $cleaner->truncateForLlm((string) ($snippet['text'] ?? ''), $maxChars),
                ];
            }
            $digests[$guideline] = $audited;
        }

        return $digests;
    }

    private function compactSnippetDigests(array $digests): array
    {
        $cap = max(1, (int) config('gate-v2.retrieval.prompt_snippets_per_guideline', 6));

        foreach ($digests as $guideline => $snippets) {
            // The prompt cap is the last place recommendations can be dropped, so
            // it reserves the same citation share as retrieval and the retry merge.
            // A plain head-slice here would re-create the Run 8 failure whenever
            // narrative chunks happened to lead the list.
            $buckets = GateEvidenceQuota::partition($snippets);
            $digests[$guideline] = array_map(
                function (array $snippet): array {
                    $snippet['text'] = ($this->chunkCleaner ?? new GateChunkCleaner)->truncateForLlm(
                        (string) ($snippet['text'] ?? ''),
                        1200,
                    );

                    return $snippet;
                },
                GateEvidenceQuota::fill($buckets['citation'], $buckets['narrative'], $cap),
            );
        }

        return $digests;
    }

    /**
     * The prompt and citation projection must share one numbered source set.
     * Numbering earlier would include snippets later removed by the prompt cap;
     * numbering later would let the model and UI disagree about marker identity.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $digests
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function promptSnippetDigests(array $digests): array
    {
        return ($this->citationBuilder ?? new GateCitationBuilder)->numberForPrompt(
            $this->compactSnippetDigests($digests),
        );
    }

    /**
     * @param  array<string, mixed>  $orient
     * @param  array<int, array<string, mixed>>  $issues
     * @return array{
     *   narrative: string,
     *   citation: string,
     *   citation_queries: array<int, string>,
     *   citation_core_queries: array<int, string>
     * }
     */
    private function serializeRetrievalQuery(array $orient, array $issues): array
    {
        return ($this->queryBuilder ?? new GateRetrievalQueryBuilder)->build(
            $orient,
            $issues,
            $this->rawTurnText,
        );
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function record(string $stage, float|int $started, array $detail = []): void
    {
        $duration = $started > 0 ? (int) round((microtime(true) - $started) * 1000) : 0;
        $this->trace[] = [
            'stage' => $stage,
            'iteration' => $this->iteration,
            'duration_ms' => $duration,
            'elapsed_ms' => (int) round((microtime(true) - $this->startedAt) * 1000),
            'detail' => $detail,
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function recordExternal(array $entry): void
    {
        $this->trace[] = [
            'stage' => (string) ($entry['stage'] ?? 'unknown'),
            'iteration' => $this->iteration,
            'duration_ms' => (int) ($entry['duration_ms'] ?? 0),
            'elapsed_ms' => (int) round((microtime(true) - $this->startedAt) * 1000),
            'detail' => (array) ($entry['detail'] ?? []),
        ];
    }

    private function assertWithinDeadline(): void
    {
        if (! $this->deadlineActive) {
            return;
        }

        if ($this->remainingSeconds() <= 0) {
            throw new RuntimeException('Gate workflow wall-clock deadline exceeded.');
        }
    }

    private function remainingSeconds(): int
    {
        $deadline = max(1, (int) config('gate-v2.deadline_seconds', 90));

        if (! $this->deadlineActive) {
            return $deadline;
        }

        return (int) floor(
            $deadline
            - $this->reservedRevisionSeconds
            - (microtime(true) - $this->startedAt),
        );
    }

    private function remainingWallSeconds(): int
    {
        $deadlineAt = $this->deadlineAt();

        return $deadlineAt === null
            ? max(1, (int) config('gate-v2.deadline_seconds', 90))
            : (int) floor($deadlineAt - microtime(true));
    }

    /**
     * The turn's absolute wall-clock deadline as a Unix timestamp. Child calls
     * are bounded by this instant rather than by a duration so a budget computed
     * in the parent cannot go stale on its way into a forked worker.
     */
    private function deadlineAt(): ?float
    {
        return $this->deadlineActive
            ? $this->startedAt
                + max(1, (int) config('gate-v2.deadline_seconds', 90))
                - $this->reservedRevisionSeconds
            : null;
    }

    private function canStartStage(string $stage): bool
    {
        return $this->remainingSeconds()
            >= max(1, (int) config("gate-v2.minimum_stage_seconds.{$stage}", 3));
    }

    private function isUpstreamTimeout(Throwable $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        return $exception instanceof ConnectionException
            || str_contains($message, 'curl error 28')
            || str_contains($message, 'timed out');
    }

    private function failureReason(Throwable $exception): string
    {
        return $this->isUpstreamTimeout($exception)
            ? 'upstream_timeout'
            : (str_contains($exception->getMessage(), 'insufficient remaining budget')
                ? 'insufficient_budget'
                : 'stage_failure');
    }

    /** @param array<string, mixed> $failure */
    private function addDegradation(array $failure): void
    {
        $fingerprint = json_encode($failure, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        foreach ($this->degradation as $existing) {
            if (json_encode($existing, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) === $fingerprint) {
                return;
            }
        }
        $this->degradation[] = $failure;
    }

    /**
     * @param  array<string, mixed>  $priorState
     * @return array<string, mixed>
     */
    private function orientFailureResult(
        array $priorState,
        Throwable $exception,
        GateProgress $progress,
    ): array {
        $this->addDegradation([
            'stage' => 'orient',
            'reason' => 'upstream_timeout',
            'unavailable' => ['updated patient model', 'guideline routing', 'clinical answer'],
        ]);
        $this->record('orient_fallback', 0, [
            'reason' => 'fatal_case_stage_timeout',
            'error_type' => $exception::class,
        ]);
        $progress->emit('done', '⚠️ Gate could not frame this turn; prior state was preserved.');
        $final = $this->tail->finalize([
            'guideline_grounded_answer' => 'The case could not be framed before the upstream model timeout. No new guideline-grounded conclusion was produced.',
            'interpretive_frame' => 'No clinical interpretation was generated. Retry the turn; the structured prior patient state was preserved.',
            'evidence_status' => [
                'coverage' => 'retrieval_uncertain',
                'core_question' => '',
                'covered_components' => [],
                'gap_summary' => 'Orient did not complete, so retrieval and synthesis were unavailable.',
            ],
            'unknowns' => [],
            'questions' => [],
            'assumptions' => [],
            'confidence' => 0.0,
        ], [], $this->degradation);

        return array_merge($final, [
            'mode' => 'case_incomplete',
            'same_case' => $priorState === [] ? null : true,
            'patient_model' => (array) ($priorState['patient_model'] ?? []),
            'routed_guidelines' => (array) ($priorState['candidate_guidelines'] ?? []),
            'pathways' => [],
            'queries_tried' => [],
            'snippet_digests' => [],
            'critic' => ['status' => 'not_run', 'approved' => null, 'score' => null],
            'best_score' => null,
            'iterations' => 0,
            'stage_trace' => $this->trace,
            'state' => $priorState,
        ]);
    }

    /**
     * Deterministic last-resort answer: it states what was gathered and makes no
     * new clinical claim when Probe synthesis cannot complete.
     *
     * @param  array<string, mixed>  $orient
     * @param  array<string, mixed>  $ground
     * @param  array<string, mixed>  $evidenceStatus
     * @return array<string, mixed>
     */
    private function evidenceOnlyProbe(array $orient, array $ground, array $evidenceStatus): array
    {
        $routed = array_values((array) ($orient['candidate_guidelines'] ?? []));
        $available = array_values(array_keys(array_filter(
            (array) ($ground['snippet_digests'] ?? []),
            static fn (array $snippets): bool => $snippets !== [],
        )));
        $summary = $available === []
            ? 'No guideline evidence was available before synthesis stopped.'
            : 'Evidence was retrieved for '.implode(', ', $available)
                .', but the answer synthesis stage did not complete.';

        return [
            'baseline_pathway' => 'EVIDENCE_ABSENT',
            'patient_deviations' => [],
            'actionable_plan' => [
                'timing' => 'EVIDENCE_ABSENT',
                'pharmacotherapy_regimen' => 'EVIDENCE_ABSENT',
                'what_not_to_do' => ['Do not treat this partial result as a completed guideline synthesis.'],
                'deferral_justification' => 'NOT_DEFERRED',
                'antithrombotic_combination_justification' => 'NOT_APPLICABLE',
            ],
            'escalation_and_reassessment' => [
                'trigger_event' => 'Successful retry of the answer synthesis stage',
                'action_on_trigger' => 'Reassess the preserved evidence and patient model.',
            ],
            'unknowns' => [],
            'questions' => [],
            'evidence_status' => $evidenceStatus,
            'guideline_grounded_answer' => $summary,
            'interpretive_frame' => 'No new clinical interpretation was generated. Routed guidelines: '
                .($routed === [] ? 'none' : implode(', ', $routed)).'.',
            'assumptions' => [],
            'confidence' => 0.0,
        ];
    }
}
