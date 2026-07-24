<?php

namespace Tests\Unit\GateEval;

use App\Ai\Gate\Latency\GateLatencyScorecard;
use PHPUnit\Framework\TestCase;

class GateLatencyScorecardTest extends TestCase
{
    public function test_it_reports_conservative_nearest_rank_stage_and_total_percentiles(): void
    {
        $turns = [
            [
                'total_ms' => 40,
                'stage_trace' => [
                    ['stage' => 'orient', 'duration_ms' => 10],
                    ['stage' => 'probe', 'duration_ms' => 20],
                ],
            ],
            [
                'total_ms' => 80,
                'stage_trace' => [
                    ['stage' => 'orient', 'duration_ms' => 30],
                    ['stage' => 'probe', 'duration_ms' => 50],
                ],
            ],
        ];

        $result = (new GateLatencyScorecard)->summarize($turns);

        $this->assertSame(
            ['count' => 2, 'p50_ms' => 40, 'p95_ms' => 80, 'max_ms' => 80],
            $result['total'],
        );
        $this->assertSame(10, $result['stages']['orient']['p50_ms']);
        $this->assertSame(30, $result['stages']['orient']['p95_ms']);
        $this->assertSame(50, $result['stages']['probe']['p95_ms']);
    }
}
