<?php

namespace App\GateEval;

use App\GateEval\Contracts\GateJudge;

class StubGateJudge implements GateJudge
{
    public function judge(array $scenario, array $turn, array $output): array
    {
        $grade = $turn['expected']['baseline_grade'] ?? 'PASS';

        return [
            'grade' => $grade,
            'failure_labels' => [],
            'reason' => 'Stub judge mirrors the declared baseline for harness verification only.',
            'rubric_version' => Rubric::VERSION,
            'needs_clinician_review' => false,
            'review_reason' => '',
            'clinical' => [
                'grade' => $grade,
                'relevant_recommendations' => 'uncertain',
                'important_missing' => [],
                'unsafe_or_misleading' => ['status' => 'uncertain', 'details' => 'Not inspected by stub judge.'],
                'class_and_evidence_level' => ['status' => 'uncertain', 'details' => 'Not inspected by stub judge.'],
                'clinician_on_top' => 'uncertain',
                'practice_use' => 'uncertain',
                'labels' => [],
                'reason' => 'Stub judge mirrors the declared baseline for harness verification only.',
            ],
            'mechanical' => [
                'grade' => 'PASS',
                'provenance' => ['status' => 'uncertain', 'details' => 'Not inspected by stub judge.'],
                'unusable_snippets' => ['count' => 0, 'details' => 'Not inspected by stub judge.'],
                'patient_facts' => ['status' => 'uncertain', 'details' => 'Not inspected by stub judge.'],
                'labels' => [],
                'reason' => 'Stub judge does not inspect mechanical criteria.',
            ],
        ];
    }

    public function identity(): string
    {
        return 'stub-judge';
    }
}
