<?php

namespace Tests\Unit\GateEval;

use App\Ai\Gate\CriticAgent;
use App\Ai\Gate\EvidenceStatusService;
use App\Ai\Gate\GateDecisionTail;
use App\Ai\Gate\GateWorkflowService;
use App\Ai\Gate\Grounding\GatePathwayWorker;
use App\Ai\Gate\Guard\PreOrientGuardService;
use App\Ai\Gate\OrientAgent;
use App\Ai\Gate\PathwayAgent;
use App\Ai\Gate\ProbeAgent;
use App\Ai\Gate\Routing\OrientRoutingPriorService;
use App\Ai\Gate\Tools\RetrieveEsvsSnippetsTool;
use App\Services\PHIScrubberService;
use App\Services\RetrievalService;
use Illuminate\Http\Client\ConnectionException;
use Tests\TestCase;

class GateDegradationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('gate-v2.deep_path_mode', 'sequential');
        config()->set('gate-v2.retrieval.max_attempts', 1);
        config()->set('gate-v2.retrieval.citation_multi_query', false);
        config()->set('gate-v2.audit.persist_snippet_digests', true);
        config()->set('gate-v2.max_iterations', 1);
    }

    public function test_critic_that_never_scores_still_yields_the_probe_answer(): void
    {
        $this->fakeOrient(['abdominal_aortic_aneurysm']);
        $this->fakePathways(1);
        ProbeAgent::fake([$this->probe('Probe answer survives Critic timeout.')])
            ->preventStrayPrompts();
        CriticAgent::fake(function (): never {
            throw $this->timeout();
        })->preventStrayPrompts();

        $result = $this->workflow($this->retrieval())->run($this->aaaTurn());

        $this->assertStringContainsString('Probe answer survives Critic timeout.', $result['answer_markdown']);
        $this->assertSame('not_evaluated', $result['critic']['status']);
        $this->assertNull($result['best_score']);
        $this->assertContains('no_candidate_scored', array_column($result['degradation'], 'reason'));
        $this->assertContains(
            'fallback_to_unevaluated_probe_candidate',
            array_column(array_column($result['stage_trace'], 'detail'), 'reason'),
        );
    }

    public function test_failing_branch_preserves_other_evidence_and_declares_partial_grounding(): void
    {
        $this->fakeOrient(['clti', 'antithrombotic_therapy'], lesion: 'CLTI after bypass');
        $this->fakePathways(1, 'antithrombotic_therapy');
        ProbeAgent::fake([$this->probe('Answer from the surviving evidence branch.')])
            ->preventStrayPrompts();
        $this->fakeApprovedCritic();

        $retrieval = $this->retrieval('clti');
        $result = $this->workflow($retrieval)->run(
            'A 72-year-old patient has CLTI after bypass. What antithrombotic therapy is appropriate?',
        );

        $this->assertSame(1, $retrieval->successfulCalls);
        $this->assertArrayHasKey('antithrombotic_therapy', $result['snippet_digests']);
        $this->assertNotEmpty($result['snippet_digests']['antithrombotic_therapy']);
        $this->assertContains('partial_evidence', array_column($result['degradation'], 'reason'));
        $this->assertStringContainsString(
            '1 of 2 routed guidelines supplied evidence',
            $result['answer_markdown'],
        );
        $this->assertStringContainsString('Answer from the surviving evidence branch.', $result['answer_markdown']);
    }

    public function test_probe_timing_out_once_is_retried_and_can_succeed(): void
    {
        $this->fakeOrient(['abdominal_aortic_aneurysm']);
        $this->fakePathways(1);
        $calls = 0;
        ProbeAgent::fake(function () use (&$calls): array {
            $calls++;
            if ($calls === 1) {
                throw $this->timeout();
            }

            return $this->probe('Probe retry completed.');
        })->preventStrayPrompts();
        $this->fakeApprovedCritic();

        $result = $this->workflow($this->retrieval())->run($this->aaaTurn());

        $this->assertSame(2, $calls);
        $this->assertStringContainsString('Probe retry completed.', $result['answer_markdown']);
        $this->assertNotContains('probe', array_column($result['degradation'], 'stage'));
        $this->assertContains('probe_failed', array_column($result['stage_trace'], 'stage'));
    }

    public function test_probe_failing_twice_returns_structured_evidence_only_result(): void
    {
        $this->fakeOrient(['abdominal_aortic_aneurysm']);
        $this->fakePathways(1);
        $calls = 0;
        ProbeAgent::fake(function () use (&$calls): never {
            $calls++;
            throw $this->timeout();
        })->preventStrayPrompts();
        $this->fakeApprovedCritic();

        $result = $this->workflow($this->retrieval())->run($this->aaaTurn());

        $this->assertSame(2, $calls);
        $this->assertSame('EVIDENCE_ABSENT', $result['baseline_pathway']);
        $this->assertSame(0.0, $result['confidence']);
        $this->assertStringContainsString(
            'answer synthesis stage did not complete',
            $result['guideline_grounded_answer'],
        );
        $this->assertContains('probe', array_column($result['degradation'], 'stage'));
        $this->assertStringContainsString('## Degradation notice', $result['answer_markdown']);
    }

    private function fakeOrient(array $guidelines, string $lesion = 'infrarenal AAA 5.8 cm'): void
    {
        OrientAgent::fake([[
            'mode' => 'case_new',
            'same_case' => false,
            'new_case_reason' => 'new patient',
            'response_mode' => 'management',
            'core_question' => 'What management is appropriate?',
            'expansion_terms' => ['management'],
            'interpretation_terms' => ['risk'],
            'must_include_terms' => ['timing'],
            'patient_model' => [
                'demographics' => '72-year-old',
                'lesion' => $lesion,
                'anatomy' => $lesion,
                'laterality' => 'n/a',
                'diameter_value' => str_contains($lesion, 'AAA') ? '5.8' : '',
                'diameter_unit' => str_contains($lesion, 'AAA') ? 'cm' : '',
                'other_findings' => [],
                'symptom_status' => 'unknown',
                'timing' => 'unknown',
                'fitness' => 'unknown',
                'imaging' => 'unknown',
                'comorbidities' => [],
                'medications' => [],
                'prior_interventions' => [],
            ],
            'changed_fields' => ['patient_model'],
            'provenance' => [],
            'open_questions' => [],
            'differential' => [],
            'candidate_guidelines' => $guidelines,
        ]])->preventStrayPrompts();
    }

    private function fakePathways(int $count, string $guideline = 'abdominal_aortic_aneurysm'): void
    {
        PathwayAgent::fake(array_fill(0, $count, [
            'guideline_key' => $guideline,
            'relevant' => true,
            'better_query' => '',
            'coverage' => 'covered',
            'covered_components' => ['management'],
            'interaction_gap' => false,
            'pathways' => [],
        ]))->preventStrayPrompts();
    }

    private function fakeApprovedCritic(): void
    {
        CriticAgent::fake([[
            'approved' => true,
            'score' => 0.9,
            'revise_stage' => 'none',
            'issues' => [],
        ]])->preventStrayPrompts();
    }

    /** @return array<string, mixed> */
    private function probe(string $answer): array
    {
        return [
            'baseline_pathway' => 'Retrieved baseline.',
            'patient_deviations' => [],
            'actionable_plan' => [
                'timing' => 'Per retrieved evidence.',
                'pharmacotherapy_regimen' => 'EVIDENCE_ABSENT',
                'what_not_to_do' => [],
                'deferral_justification' => 'NOT_DEFERRED',
                'antithrombotic_combination_justification' => 'NOT_APPLICABLE',
            ],
            'escalation_and_reassessment' => [
                'trigger_event' => 'clinical change',
                'action_on_trigger' => 'reassess',
            ],
            'unknowns' => [],
            'questions' => [],
            'evidence_status' => [
                'coverage' => 'covered',
                'core_question' => 'What management is appropriate?',
                'covered_components' => ['management'],
                'gap_summary' => '',
            ],
            'guideline_grounded_answer' => $answer,
            'interpretive_frame' => 'No additional interpretation.',
            'assumptions' => [],
            'confidence' => 0.8,
        ];
    }

    private function retrieval(?string $failingGuideline = null): RetrievalService
    {
        return new class($failingGuideline) extends RetrievalService
        {
            public int $successfulCalls = 0;

            public function __construct(private readonly ?string $failingGuideline) {}

            public function retrieve(
                string $question,
                array $history = [],
                ?array $requestedKeys = null,
                ?string $citationQuestion = null,
            ): array {
                $guideline = (string) ($requestedKeys[0] ?? '');
                if ($guideline === $this->failingGuideline) {
                    throw new ConnectionException(
                        'cURL error 28: Operation timed out after 30002 milliseconds',
                    );
                }
                $this->successfulCalls++;

                return [
                    'duration_ms' => 1,
                    'llm_citation_chunks' => [[
                        'text' => "Recommendation evidence for {$guideline}",
                        'similarity' => 0.9,
                        'source' => $guideline,
                    ]],
                    'llm_narrative_chunks' => [],
                ];
            }
        };
    }

    private function workflow(RetrievalService $retrieval): GateWorkflowService
    {
        return new GateWorkflowService(
            new PreOrientGuardService,
            new OrientRoutingPriorService,
            new GatePathwayWorker(new RetrieveEsvsSnippetsTool($retrieval)),
            new EvidenceStatusService,
            new GateDecisionTail,
            new PHIScrubberService,
        );
    }

    private function timeout(): ConnectionException
    {
        return new ConnectionException(
            'cURL error 28: Operation timed out after 30002 milliseconds',
        );
    }

    private function aaaTurn(): string
    {
        return 'A 72-year-old patient has a 5.8 cm infrarenal AAA. What management is appropriate?';
    }
}
