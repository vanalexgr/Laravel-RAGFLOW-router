<?php

namespace Tests\Unit\GateEval;

use App\GateEval\ExternalCloudJudge;
use App\GateEval\Rubric;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RubricTest extends TestCase
{
    public function test_multi_option_answer_is_not_failed_for_lack_of_commitment(): void
    {
        $prompt = Rubric::prompt();

        $this->assertStringContainsString(
            'Offering several supported options, with their class and evidence level as supplied',
            $prompt,
        );
        $this->assertMatchesRegularExpression(
            '/Never penalize “did not commit to one\\s+regimen”/',
            $prompt,
        );
        $this->assertMatchesRegularExpression(
            '/VKA, aspirin alone, and aspirin plus low-dose rivaroxaban may all be\\s+reasonable/',
            $prompt,
        );
    }

    public function test_unsafe_drug_combination_is_a_clinical_failure(): void
    {
        $this->assertMatchesRegularExpression(
            '/unsafe drug\\s+combination, contraindicated treatment, or materially misleading statement makes clinical\\.grade\\s+"FAIL"/s',
            Rubric::prompt(),
        );
    }

    public function test_wrong_provenance_fails_mechanical_axis_without_failing_clinical_axis(): void
    {
        $this->fakeJudgment($this->judgment(
            clinicalGrade: 'PASS',
            mechanicalGrade: 'FAIL',
            mechanicalLabels: ['M_PROVENANCE_INCORRECT'],
        ));

        $judgment = (new ExternalCloudJudge)->judge([], [], []);

        $this->assertSame('PASS', $judgment['clinical']['grade']);
        $this->assertSame('FAIL', $judgment['mechanical']['grade']);
        $this->assertSame('PASS', $judgment['grade']);
        $this->assertSame(['M_PROVENANCE_INCORRECT'], $judgment['failure_labels']);
    }

    public function test_judge_receives_screening_rubric_and_emits_separate_dimensions(): void
    {
        $this->fakeJudgment($this->judgment());

        $judgment = (new ExternalCloudJudge)->judge([], [], []);

        Http::assertSent(function (Request $request): bool {
            $prompt = $request->data()['messages'][0]['content'] ?? '';

            return str_contains($prompt, 'screening evaluator')
                && str_contains($prompt, 'reviewing clinician is ground truth')
                && str_contains($prompt, 'Score the following dimensions SEPARATELY');
        });
        $this->assertArrayHasKey('clinical', $judgment);
        $this->assertArrayHasKey('mechanical', $judgment);
        $this->assertFalse($judgment['needs_clinician_review']);
    }

    public function test_legacy_label_mapping_marks_rejected_uses_as_retired(): void
    {
        $mapping = Rubric::legacyLabelMapping();

        $this->assertSame(['F1', 'F2', 'F3', 'F4', 'F5', 'F6', 'F7'], array_keys($mapping));
        $this->assertStringContainsString('did not commit', $mapping['F3']);
        $this->assertStringContainsString('Retired for off-topic retrieval alone', $mapping['F5']);
    }

    /**
     * @param  array<string, mixed>  $judgment
     */
    private function fakeJudgment(array $judgment): void
    {
        config()->set('gate-eval.judge.url', 'https://judge.test/v1/chat/completions');
        config()->set('gate-eval.judge.api_key', 'test-key');
        config()->set('gate-eval.judge.model', 'external-test-judge');

        Http::fake([
            'judge.test/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode($judgment, JSON_THROW_ON_ERROR),
                    ],
                ]],
            ]),
        ]);
    }

    /**
     * @param  array<int, string>  $mechanicalLabels
     * @return array<string, mixed>
     */
    private function judgment(
        string $clinicalGrade = 'PASS',
        string $mechanicalGrade = 'PASS',
        array $mechanicalLabels = [],
    ): array {
        return [
            'rubric_version' => Rubric::VERSION,
            'needs_clinician_review' => false,
            'review_reason' => '',
            'clinical' => [
                'grade' => $clinicalGrade,
                'relevant_recommendations' => 'all_that_matter',
                'important_missing' => [],
                'unsafe_or_misleading' => ['status' => 'no', 'details' => ''],
                'class_and_evidence_level' => ['status' => 'correct', 'details' => ''],
                'clinician_on_top' => 'yes',
                'practice_use' => 'yes',
                'labels' => [],
                'reason' => 'Clinically suitable.',
            ],
            'mechanical' => [
                'grade' => $mechanicalGrade,
                'provenance' => [
                    'status' => $mechanicalGrade === 'FAIL' ? 'incorrect' : 'correct',
                    'details' => '',
                ],
                'unusable_snippets' => ['count' => 0, 'details' => ''],
                'patient_facts' => ['status' => 'not_applicable', 'details' => ''],
                'labels' => $mechanicalLabels,
                'reason' => '',
            ],
        ];
    }
}
