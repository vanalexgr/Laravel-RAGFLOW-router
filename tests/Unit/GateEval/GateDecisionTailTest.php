<?php

namespace Tests\Unit\GateEval;

use App\Ai\Gate\GateDecisionTail;
use PHPUnit\Framework\TestCase;

class GateDecisionTailTest extends TestCase
{
    public function test_decision_uses_discrete_high_impact_unknown_and_never_reasks_declined(): void
    {
        $result = (new GateDecisionTail)->finalize([
            'unknowns' => [[
                'variable' => 'fitness',
                'branch_impact' => 'high',
                'currently_known' => false,
            ]],
            'questions' => [
                ['question' => 'Is the patient fit?', 'targets' => 'fitness'],
                ['question' => 'What is the anatomy?', 'targets' => 'anatomy'],
            ],
            'guideline_grounded_answer' => 'Grounded.',
            'interpretive_frame' => 'Interpretation.',
            'confidence' => 0.99,
        ], [
            ['question' => 'Is the patient fit?', 'status' => 'declined'],
        ]);

        $this->assertSame('ask', $result['decision']);
        $this->assertSame('What is the anatomy?', $result['questions'][0]['question']);
    }

    public function test_tail_adds_fixed_banner_and_dose_lint_without_using_confidence(): void
    {
        $result = (new GateDecisionTail)->finalize([
            'unknowns' => [],
            'questions' => [],
            'guideline_grounded_answer' => 'Grounded.',
            'interpretive_frame' => 'Consider 5 mg daily.',
            'confidence' => 0.01,
        ]);

        $this->assertSame('proceed', $result['decision']);
        $this->assertStringStartsWith(GateDecisionTail::NON_ESVS_BANNER, $result['interpretive_frame']);
        $this->assertSame(['5 mg'], $result['lint_violations']);
    }
}
