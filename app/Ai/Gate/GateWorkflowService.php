<?php

namespace App\Ai\Gate;

use App\Ai\Gate\Evaluation\GateCandidateLedger;
use App\Ai\Gate\Grounding\GatePathwayWorker;
use App\Ai\Gate\Guard\PreOrientGuardService;
use App\Ai\Gate\Progress\GateProgress;
use App\Ai\Gate\Progress\NullGateProgress;
use App\Ai\Gate\Retrieval\GateChunkCleaner;
use App\Ai\Gate\Retrieval\GateRetrievalQueryBuilder;
use App\Ai\Gate\Routing\OrientRoutingPriorService;
use App\Services\PHIScrubberService;
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

    public function __construct(
        private readonly PreOrientGuardService $guard,
        private readonly OrientRoutingPriorService $routing,
        private readonly GatePathwayWorker $pathwayWorker,
        private readonly EvidenceStatusService $evidenceStatus,
        private readonly GateDecisionTail $tail,
        private readonly PHIScrubberService $scrubber,
        private readonly ?GateRetrievalQueryBuilder $queryBuilder = null,
        private readonly ?GateChunkCleaner $chunkCleaner = null,
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
        $this->deadlineActive = false;

        [$turn, $priorState] = $this->deidentify($turn, $priorState);

        return $this->execute($turn, $priorState, $progress ?? new NullGateProgress);
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
        $orient = $this->orientWithPrefetch($turn, $priorState);
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
        $progress->emit('retrieve', '🔍 Retrieving the selected ESVS guidance…');
        $ground = $this->ground($turn, $orient, []);
        $evidenceStatus = $this->evidenceStatus->assess($turn, $ground['pathways']);
        $probe = $this->probe($turn, $orient, $ground, $evidenceStatus, [], $priorState);

        $candidate = compact('orient', 'ground', 'evidenceStatus', 'probe');
        $ledger = new GateCandidateLedger;
        $budgets = config('gate-v2.bounce_budgets');
        $seenBounceFingerprints = [];
        $lastCritic = null;
        $maxIterations = max(1, (int) config('gate-v2.max_iterations', 3));

        for ($this->iteration = 1; $this->iteration <= $maxIterations; $this->iteration++) {
            try {
                $progress->emit('evaluate', '🧪 Checking state, grounding, and question value…', [
                    'iteration' => $this->iteration,
                ]);
                $lastCritic = $this->critic($turn, $candidate, $priorState);
            } catch (Throwable $exception) {
                $this->record('decide', 0, [
                    'reason' => 'critic_or_deadline_failure',
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

            $progress->emit('revise', '✍️ Refining the earliest failing stage…', [
                'iteration' => $this->iteration,
                'stage' => $stage,
            ]);

            try {
                if ($stage === 'orient_route') {
                    $candidate['orient'] = $this->orient($turn, $priorState, $issues);
                    $candidate['ground'] = $this->ground($turn, $candidate['orient'], $issues);
                    $candidate['evidenceStatus'] = $this->evidenceStatus->assess(
                        $turn,
                        $candidate['ground']['pathways'],
                    );
                } elseif ($stage === 'ground') {
                    $candidate['ground'] = $this->ground($turn, $candidate['orient'], $issues);
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

        $best = $ledger->best();
        $bestCandidate = $best['candidate'];
        $bestCritic = $best['critic'];
        $bestScore = (float) $bestCritic['score'];
        $final = $this->tail->finalize(
            $bestCandidate['probe'],
            (array) ($bestCandidate['orient']['open_questions'] ?? []),
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
        $this->record('orient', $started, [
            'mode' => $response['mode'] ?? null,
            'guidelines' => $response['candidate_guidelines'],
        ]);

        return $response;
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

    /**
     * @param  array<string, mixed>  $orient
     * @param  array<int, array<string, mixed>>  $issues
     * @return array{pathways: array<int, array<string, mixed>>, queries_tried: array<string, array<int, string>>, snippet_digests: array<string, array<int, array<string, mixed>>>}
     */
    private function ground(string $turn, array $orient, array $issues): array
    {
        $allPathways = [];
        $queriesTried = [];
        $snippetDigests = [];
        $guidelines = array_slice((array) ($orient['candidate_guidelines'] ?? []), 0, 3);
        $usePrefetch = $this->iteration === 0 && $issues === [] && $this->prefetchedQuery !== null;
        $maxAttempts = $issues === []
            ? null
            : max(1, (int) config('gate-v2.retrieval.revision_max_attempts', 1));
        $queryPair = $this->serializeRetrievalQuery($orient, $issues);
        $query = $queryPair['narrative'];
        $citationQuery = $queryPair['citation'];
        $this->assertWithinDeadline();
        $results = [];
        $pending = [];
        foreach ($guidelines as $guideline) {
            $cacheKey = $this->groundCacheKey(
                $guideline,
                $query,
                $citationQuery,
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
            // Forked branches cannot read the parent's elapsed time, so bound
            // them by the same absolute deadline the sequential path respects.
            $deadlineAt = $this->deadlineAt();
            $remaining = $this->remainingWallSeconds();
            foreach (array_keys($pending) as $guideline) {
                $patientModel = (array) $orient['patient_model'];
                $prefetched = $usePrefetch ? ($this->prefetchedGround[$guideline] ?? null) : null;
                $tasks[$guideline] = static fn (): array => app(GatePathwayWorker::class)->run(
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
                );
            }
            $completed = Concurrency::driver((string) config('gate-v2.concurrency_driver', 'process'))
                ->run($tasks);
            $this->record('ground_parallel', $started, ['guidelines' => array_keys($pending)]);
            $results = array_merge($results, $completed);
        } else {
            foreach (array_keys($pending) as $guideline) {
                $this->assertWithinDeadline();
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
                );
            }
        }
        $this->prefetchedGround = [];
        $this->prefetchedQuery = null;
        foreach ($pending as $guideline => $cacheKey) {
            $this->groundCache[$cacheKey] = $results[$guideline];
        }
        $this->assertWithinDeadline();

        foreach ($guidelines as $guideline) {
            $result = $results[$guideline];
            $guideline = (string) $result['guideline'];
            $queriesTried[$guideline] = (array) $result['queries_tried'];
            $snippetDigests[$guideline] = (array) $result['snippet_digests'];
            foreach ((array) $result['trace'] as $entry) {
                $this->recordExternal($entry);
            }
            if (is_array($result['assessment'] ?? null)) {
                $allPathways[] = $result['assessment'];
            }
        }

        return [
            'pathways' => $allPathways,
            'queries_tried' => $queriesTried,
            'snippet_digests' => $snippetDigests,
        ];
    }

    /**
     * @param  array<string, mixed>  $patientModel
     */
    private function groundCacheKey(
        string $guideline,
        string $query,
        string $citationQuery,
        array $patientModel,
    ): string
    {
        return hash('sha256', json_encode(
            [$guideline, $query, $citationQuery, $patientModel],
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
        $started = microtime(true);
        $response = $this->prompt(new ProbeAgent, [
            'current_question' => $turn,
            'patient_model' => $orient['patient_model'],
            'response_mode' => $orient['response_mode'],
            'house_sections' => ['ESVS-grounded answer', 'Interpretation'],
            'pathways' => $ground['pathways'],
            'source_snippets' => $this->compactSnippetDigests($ground['snippet_digests']),
            'evidence_status' => $evidenceStatus,
            'open_questions' => $orient['open_questions'],
            'prior_assumptions' => $priorState['assumptions'] ?? [],
            'critic_issues' => $issues,
        ]);
        $this->record('probe', $started, [
            'questions' => count((array) ($response['questions'] ?? [])),
            'coverage' => $response['evidence_status']['coverage'] ?? null,
        ]);

        return $response;
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
            'source_snippet_digests' => $this->compactSnippetDigests(
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
        $ground = $this->ground($turn, $orient, []);
        $evidenceStatus = $this->evidenceStatus->assess($turn, $ground['pathways']);
        $started = microtime(true);
        $answer = $this->prompt(new KnowledgeAnswerAgent, [
            'current_question' => $turn,
            'patient_model_digest' => $orient['patient_model'],
            'snippets' => $ground['snippet_digests'],
            'evidence_status' => $evidenceStatus,
        ]);
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

        $final = $this->tail->finalize($answer + ['unknowns' => [], 'questions' => []]);
        $progress->emit('done', '✅ Gate reasoning complete.');

        return array_merge($final, [
            'mode' => 'knowledge',
            'same_case' => $orient['same_case'],
            'patient_model' => $orient['patient_model'],
            'routed_guidelines' => $orient['candidate_guidelines'],
            'pathways' => $ground['pathways'],
            'queries_tried' => $ground['queries_tried'],
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
    private function compactSnippetDigests(array $digests): array
    {
        foreach ($digests as $guideline => $snippets) {
            $digests[$guideline] = array_map(
                function (array $snippet): array {
                    $snippet['text'] = ($this->chunkCleaner ?? new GateChunkCleaner)->truncateForLlm(
                        (string) ($snippet['text'] ?? ''),
                        1200,
                    );

                    return $snippet;
                },
                array_slice($snippets, 0, 6),
            );
        }

        return $digests;
    }

    /**
     * @param  array<string, mixed>  $orient
     * @param  array<int, array<string, mixed>>  $issues
     * @return array{narrative: string, citation: string}
     */
    private function serializeRetrievalQuery(array $orient, array $issues): array
    {
        return ($this->queryBuilder ?? new GateRetrievalQueryBuilder)->build($orient, $issues);
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
            ? $this->startedAt + max(1, (int) config('gate-v2.deadline_seconds', 90))
            : null;
    }
}
