<?php

namespace App\Ai\Gate;

use App\Ai\Gate\Grounding\GatePathwayWorker;
use App\Ai\Gate\Guard\PreOrientGuardService;
use App\Ai\Gate\Progress\GateProgress;
use App\Ai\Gate\Progress\NullGateProgress;
use App\Ai\Gate\Routing\OrientRoutingPriorService;
use Illuminate\Support\Facades\Concurrency;
use RuntimeException;
use Throwable;

final class GateWorkflowService
{
    /** @var array<int, array<string, mixed>> */
    private array $trace = [];

    private float $startedAt;

    private int $iteration = 0;

    public function __construct(
        private readonly PreOrientGuardService $guard,
        private readonly OrientRoutingPriorService $routing,
        private readonly GatePathwayWorker $pathwayWorker,
        private readonly EvidenceStatusService $evidenceStatus,
        private readonly GateDecisionTail $tail,
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
        $this->startedAt = microtime(true);
        $this->iteration = 0;
        $progress ??= new NullGateProgress;

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
        $orient = $this->orient($turn, $priorState, []);
        if ($priorState === []) {
            $orient['same_case'] = null;
        }
        if (($priorState['_force_case'] ?? false) === true) {
            $orient['mode'] = 'case_followup_substantive';
        }

        if ($orient['mode'] === 'knowledge') {
            return $this->knowledgePath($turn, $orient, $priorState, $progress);
        }

        $progress->emit('retrieve', '🔍 Retrieving the selected ESVS guidance…');
        $ground = $this->ground($turn, $orient, []);
        $evidenceStatus = $this->evidenceStatus->assess($turn, $ground['pathways']);
        $probe = $this->probe($turn, $orient, $ground, $evidenceStatus, [], $priorState);

        $candidate = compact('orient', 'ground', 'evidenceStatus', 'probe');
        $bestCandidate = $candidate;
        $bestScore = -1.0;
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
            $score = (float) ($lastCritic['score'] ?? 0);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestCandidate = $candidate;
            }

            if (($lastCritic['approved'] ?? false) === true) {
                $bestCandidate = $candidate;
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
            'critic' => $lastCritic,
            'best_score' => $bestScore,
            'iterations' => min($this->iteration, $maxIterations),
            'stage_trace' => $this->trace,
            'state' => [
                'patient_model' => $bestCandidate['orient']['patient_model'],
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
        $signals = $this->routing->turnSignals($turn);
        $deterministicCandidates = $this->routing->candidates(
            json_encode([$priorState['patient_model'] ?? [], $turn]) ?: $turn,
        );
        $response = $this->prompt(new OrientAgent, [
            'current_turn' => $turn,
            'turn_index' => (int) ($priorState['turn_index'] ?? 0) + 1,
            'prior_state' => $priorState,
            'turn_signals' => $signals,
            'deterministic_candidate_priors' => $deterministicCandidates,
            'guideline_reference' => OrientRoutingPriorService::GUIDELINE_REFERENCE,
            'critic_issues' => $issues,
        ]);
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
        $this->record('orient', $started, [
            'mode' => $response['mode'] ?? null,
            'guidelines' => $response['candidate_guidelines'],
        ]);

        return $response;
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
        $guidelines = array_slice((array) ($orient['candidate_guidelines'] ?? []), 0, 2);
        $query = $this->serializeRetrievalQuery($turn, (array) $orient['patient_model'], $issues);
        $this->assertWithinDeadline();

        if ((string) config('gate-v2.deep_path_mode', 'parallel') === 'parallel' && count($guidelines) > 1) {
            $started = microtime(true);
            $tasks = [];
            foreach ($guidelines as $guideline) {
                $patientModel = (array) $orient['patient_model'];
                $tasks[$guideline] = static fn (): array => app(GatePathwayWorker::class)->run(
                    $guideline,
                    $query,
                    $patientModel,
                    $turn,
                );
            }
            $results = Concurrency::driver((string) config('gate-v2.concurrency_driver', 'process'))
                ->run($tasks);
            $this->record('ground_parallel', $started, ['guidelines' => $guidelines]);
        } else {
            $results = [];
            foreach ($guidelines as $guideline) {
                $this->assertWithinDeadline();
                $results[$guideline] = $this->pathwayWorker->run(
                    $guideline,
                    $query,
                    (array) $orient['patient_model'],
                    $turn,
                );
            }
        }
        $this->assertWithinDeadline();

        foreach ($results as $result) {
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
            'source_snippets' => $ground['snippet_digests'],
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
            'orient' => $candidate['orient'],
            'pathways' => $candidate['ground']['pathways'],
            'source_snippet_digests' => $candidate['ground']['snippet_digests'],
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

            return $this->run($turn, $forcedState, $progress);
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
        $response = $agent->prompt(
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            provider: (string) config('gate-v2.provider'),
            model: (string) config('gate-v2.model'),
            timeout: max(1, min(60, $this->remainingSeconds())),
        );

        $structured = $response->toArray();
        if ($structured === []) {
            throw new RuntimeException($agent::class.' returned an empty structured response.');
        }

        return $structured;
    }

    /**
     * @param  array<string, mixed>  $patientModel
     * @param  array<int, array<string, mixed>>  $issues
     */
    private function serializeRetrievalQuery(string $turn, array $patientModel, array $issues): string
    {
        return 'Decision at issue: '.$turn."\nPatient model: "
            .json_encode($patientModel, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            .($issues === [] ? '' : "\nCritic retrieval issues: ".json_encode($issues));
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
        if ($this->remainingSeconds() <= 0) {
            throw new RuntimeException('Gate workflow wall-clock deadline exceeded.');
        }
    }

    private function remainingSeconds(): int
    {
        $deadline = max(1, (int) config('gate-v2.deadline_seconds', 90));

        return (int) floor($deadline - (microtime(true) - $this->startedAt));
    }
}
