<?php

namespace App\Ai\Gate\Latency;

final class GateLatencyScorecard
{
    /**
     * @param  array<int, array<string, mixed>>  $turns
     * @return array<string, mixed>
     */
    public function summarize(array $turns): array
    {
        $durations = [];
        $totals = [];

        foreach ($turns as $turn) {
            $total = (int) ($turn['total_ms'] ?? 0);
            if ($total > 0) {
                $totals[] = $total;
            }

            foreach ((array) ($turn['stage_trace'] ?? []) as $entry) {
                $stage = (string) ($entry['stage'] ?? 'unknown');
                $duration = (int) ($entry['duration_ms'] ?? 0);
                if ($duration > 0) {
                    $durations[$stage][] = $duration;
                }
            }
        }

        ksort($durations);
        $stages = [];
        foreach ($durations as $stage => $values) {
            $stages[$stage] = $this->statistics($values);
        }

        return [
            'turn_count' => count($turns),
            'total' => $this->statistics($totals),
            'stages' => $stages,
        ];
    }

    /**
     * @param  array<int, int>  $values
     * @return array{count: int, p50_ms: int, p95_ms: int, max_ms: int}
     */
    private function statistics(array $values): array
    {
        if ($values === []) {
            return ['count' => 0, 'p50_ms' => 0, 'p95_ms' => 0, 'max_ms' => 0];
        }

        sort($values, SORT_NUMERIC);

        return [
            'count' => count($values),
            'p50_ms' => $this->percentile($values, 0.50),
            'p95_ms' => $this->percentile($values, 0.95),
            'max_ms' => max($values),
        ];
    }

    /**
     * Nearest-rank percentile: deterministic and conservative for small benchmark samples.
     *
     * @param  array<int, int>  $sorted
     */
    private function percentile(array $sorted, float $percentile): int
    {
        $rank = max(1, (int) ceil($percentile * count($sorted)));

        return $sorted[$rank - 1];
    }
}
