<?php

namespace Tests\Unit\GateEval;

use App\Ai\Gate\EvidenceStatusService;
use PHPUnit\Framework\TestCase;

class EvidenceStatusServiceTest extends TestCase
{
    public function test_interaction_gap_is_not_collapsed_to_not_covered(): void
    {
        $status = (new EvidenceStatusService)->assess('How do these recommendations interact?', [
            [
                'coverage' => 'partial',
                'covered_components' => ['AAA repair', 'antithrombotic management'],
                'interaction_gap' => true,
            ],
        ]);

        $this->assertSame('interaction_gap', $status['coverage']);
        $this->assertCount(2, $status['covered_components']);
    }

    public function test_all_uncertain_attempts_remain_retrieval_uncertain(): void
    {
        $status = (new EvidenceStatusService)->assess('Question', [
            ['coverage' => 'retrieval_uncertain'],
        ]);

        $this->assertSame('retrieval_uncertain', $status['coverage']);
    }
}
