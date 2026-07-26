<?php

namespace Tests\Unit\GateEval;

use App\GateEval\GateVarianceSummary;
use PHPUnit\Framework\TestCase;

class GateVarianceSummaryTest extends TestCase
{
    public function test_it_counts_distinct_queries_and_summarizes_branch_citations(): void
    {
        $runs = [
            $this->run('PASS', 0, 'query alpha', 4, 'query shared'),
            $this->run('FAIL', 2, 'query beta', 8, 'query shared'),
            $this->run('PASS', 7, 'query alpha', 12, 'query other'),
        ];

        $summary = (new GateVarianceSummary)->summarize($runs);

        $this->assertSame(['FAIL' => 1, 'PASS' => 2], $summary['grade_distribution']);
        $this->assertSame(
            ['min' => 0, 'median' => 2, 'max' => 7],
            $summary['branches']['antithrombotic_therapy']['citation_count'],
        );
        $this->assertSame(2, $summary['branches']['antithrombotic_therapy']['distinct_citation_query_count']);
        $this->assertSame(['query alpha', 'query beta'], $summary['branches']['antithrombotic_therapy']['distinct_citation_queries']);
        $this->assertSame(4, $summary['distinct_citation_query_count']);
    }

    public function test_distribution_uses_the_mean_of_middle_values_for_even_runs(): void
    {
        $distribution = (new GateVarianceSummary)->distribution([9, 1, 7, 3]);

        $this->assertSame(['min' => 1, 'median' => 5, 'max' => 9], $distribution);
    }

    /**
     * @return array<string, mixed>
     */
    private function run(
        ?string $grade,
        int $antithromboticCount,
        string $antithromboticQuery,
        int $cltiCount,
        string $cltiQuery,
    ): array {
        return [
            'grade' => $grade,
            'branches' => [
                'antithrombotic_therapy' => [
                    'citation_count' => $antithromboticCount,
                    'citation_query' => $antithromboticQuery,
                ],
                'clti' => [
                    'citation_count' => $cltiCount,
                    'citation_query' => $cltiQuery,
                ],
            ],
        ];
    }
}
