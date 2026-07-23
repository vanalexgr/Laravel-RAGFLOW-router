<?php

namespace App\GateEval;

use App\GateEval\Contracts\GateSubject;

class StubGateSubject implements GateSubject
{
    public function runTurn(array $scenario, array $turn, int $turnIndex, array $priorOutputs): array
    {
        $expected = $turn['expected'];
        $coverage = $expected['evidence_status']['coverage'];
        $coverage = is_array($coverage) ? $coverage[0] : $coverage;
        $facts = implode('; ', $expected['must_include_facts']);
        $grounded = $facts !== '' ? "Grounded fixture: {$facts}" : 'Grounded fixture response.';
        $interpretive = 'Non-ESVS interpretive frame: stub evaluation output only.';

        return [
            'mode' => $expected['mode'],
            'same_case' => $expected['same_case'],
            'guideline_keys' => $expected['guideline_keys'],
            'patient_model' => ['fixture_facts' => $expected['must_include_facts']],
            'questions' => array_slice($expected['expected_questions_semantic'], 0, $expected['max_questions']),
            'evidence_status' => [
                'coverage' => $coverage,
                'core_question' => $scenario['id'],
                'covered_components' => [],
                'gap_summary' => '',
            ],
            'guideline_grounded_answer' => $grounded,
            'interpretive_frame' => $interpretive,
            'answer_markdown' => "{$grounded}\n\n{$interpretive}",
            'rendered_answer' => "{$grounded}\n\n{$interpretive}",
            'snippets' => [],
            'latency_ms' => 1,
            'iterations' => 1,
            'stage_trace' => [
                ['stage' => 'stub', 'scenario' => $scenario['id'], 'turn' => $turnIndex + 1],
            ],
        ];
    }

    public function identity(): string
    {
        return 'stub-sut';
    }
}
