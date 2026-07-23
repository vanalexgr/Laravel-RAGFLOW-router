<?php

namespace App\GateEval;

use App\GateEval\Contracts\GateJudge;

class StubGateJudge implements GateJudge
{
    public function judge(array $scenario, array $turn, array $output): array
    {
        return [
            'grade' => $turn['expected']['baseline_grade'] ?? 'PASS',
            'failure_labels' => [],
            'reason' => 'Stub judge mirrors the declared baseline for harness verification only.',
        ];
    }

    public function identity(): string
    {
        return 'stub-judge';
    }
}
