<?php

namespace Tests\Unit\GateEval;

use App\Ai\Gate\Evaluation\GateCandidateLedger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class GateCandidateLedgerTest extends TestCase
{
    public function test_approved_revision_wins_over_higher_scored_disapproved_candidate(): void
    {
        $ledger = new GateCandidateLedger;
        $ledger->consider(['id' => 'initial'], ['approved' => false, 'score' => 0.95]);
        $ledger->consider(['id' => 'revision'], ['approved' => true, 'score' => 0.80]);

        $this->assertSame('revision', $ledger->best()['candidate']['id']);
    }

    public function test_unscored_candidates_can_never_be_returned(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No candidate completed Critic scoring');

        (new GateCandidateLedger)->best();
    }
}
