<?php

namespace Tests\Unit\GateEval;

use App\Ai\Gate\Retrieval\GateEvidenceQuota;
use Tests\TestCase;

class GateEvidenceQuotaTest extends TestCase
{
    /** @return array<int, array<string, mixed>> */
    private function snippets(string $bucket, int $count): array
    {
        $out = [];
        for ($i = 1; $i <= $count; $i++) {
            $out[] = ['text' => "{$bucket} {$i}", 'bucket' => $bucket];
        }

        return $out;
    }

    public function test_recommendations_are_not_crowded_out_by_abundant_narrative(): void
    {
        // Run 8's shape: plenty of prose, a couple of recommendations. A first-come
        // fill handed all ten slots to prose and the answer had nothing to cite.
        $filled = GateEvidenceQuota::fill(
            $this->snippets('citation', 2),
            $this->snippets('narrative', 30),
            10,
        );

        $this->assertCount(10, $filled);
        $this->assertSame(
            ['citation 1', 'citation 2'],
            array_column(array_slice($filled, 0, 2), 'text'),
            'Recommendations must lead the list so later head-truncation keeps them.',
        );
    }

    public function test_reservation_is_honoured_when_both_buckets_are_abundant(): void
    {
        $filled = GateEvidenceQuota::fill(
            $this->snippets('citation', 20),
            $this->snippets('narrative', 20),
            10,
        );

        $buckets = GateEvidenceQuota::partition($filled);

        $this->assertCount(10, $filled);
        $this->assertCount(4, $buckets['citation'], 'A 0.4 share of 10 reserves four citation slots.');
        $this->assertCount(6, $buckets['narrative']);
    }

    public function test_an_empty_citation_bucket_still_fills_the_whole_budget(): void
    {
        // The quota may only change the mix, never shrink the evidence.
        $filled = GateEvidenceQuota::fill([], $this->snippets('narrative', 12), 10);

        $this->assertCount(10, $filled);
    }

    public function test_a_small_cap_still_reserves_at_least_one_recommendation(): void
    {
        $filled = GateEvidenceQuota::fill(
            $this->snippets('citation', 5),
            $this->snippets('narrative', 5),
            2,
        );

        $buckets = GateEvidenceQuota::partition($filled);

        $this->assertCount(2, $filled);
        $this->assertCount(1, $buckets['citation'], 'Rounding must not erase the recommendations entirely.');
    }

    public function test_fractional_minimum_share_rounds_up_at_prompt_sized_capacities(): void
    {
        config()->set('gate-v2.retrieval.citation_share', 0.4);

        $this->assertSame(2, GateEvidenceQuota::citationSlots(3));
        $this->assertSame(2, GateEvidenceQuota::citationSlots(5));
        $this->assertSame(3, GateEvidenceQuota::citationSlots(6));
    }

    public function test_share_of_zero_disables_the_reservation(): void
    {
        config()->set('gate-v2.retrieval.citation_share', 0.0);

        $filled = GateEvidenceQuota::fill(
            $this->snippets('citation', 5),
            $this->snippets('narrative', 5),
            4,
        );

        $this->assertCount(0, GateEvidenceQuota::partition($filled)['citation']);
    }
}
