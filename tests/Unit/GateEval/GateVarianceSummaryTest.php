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
        $this->assertSame(
            ['min' => 4, 'median' => 8, 'max' => 12],
            $summary['branches']['clti']['citation_count'],
        );
        $this->assertSame(2, $summary['branches']['clti']['distinct_citation_query_count']);
        $this->assertSame(['query other', 'query shared'], $summary['branches']['clti']['raw_citation_queries']);
    }

    public function test_distribution_uses_the_mean_of_middle_values_for_even_runs(): void
    {
        $distribution = (new GateVarianceSummary)->distribution([9, 1, 7, 3]);

        $this->assertSame(['min' => 1, 'median' => 5, 'max' => 9], $distribution);
    }

    public function test_it_fills_a_missing_branch_with_zero_and_reports_the_missing_run(): void
    {
        $runs = [
            $this->run('PASS', 5, 'query', 6, 'clti query'),
            [
                'grade' => 'PASS',
                'branches' => [
                    'clti' => ['citation_count' => 10, 'citation_query' => 'clti query'],
                ],
            ],
        ];

        $summary = (new GateVarianceSummary)->summarize($runs);

        $this->assertSame(
            ['min' => 0, 'median' => 2.5, 'max' => 5],
            $summary['branches']['antithrombotic_therapy']['citation_count'],
        );
        $this->assertSame(1, $summary['branches']['antithrombotic_therapy']['runs_missing']);
        $this->assertSame(
            ['min' => 6, 'median' => 8, 'max' => 10],
            $summary['branches']['clti']['citation_count'],
        );
        $this->assertSame(0, $summary['branches']['clti']['runs_missing']);
    }

    public function test_it_normalizes_whitespace_and_case_but_reports_raw_queries(): void
    {
        $runs = [
            $this->run('PASS', 1, ' Query Alpha ', 2, 'CLTI'),
            $this->run('PASS', 2, 'query alpha', 3, ' clti '),
        ];

        $summary = (new GateVarianceSummary)->summarize($runs);

        $this->assertSame(1, $summary['branches']['antithrombotic_therapy']['distinct_citation_query_count']);
        $this->assertSame([' Query Alpha ', 'query alpha'], $summary['branches']['antithrombotic_therapy']['raw_citation_queries']);
        $this->assertSame(2, $summary['distinct_citation_query_count']);
        $this->assertSame(
            [' Query Alpha ', ' clti ', 'CLTI', 'query alpha'],
            $summary['raw_citation_queries'],
        );
    }

    public function test_null_grade_falls_back_to_not_judged(): void
    {
        $summary = (new GateVarianceSummary)->summarize([
            $this->run(null, 1, 'query', 2, 'clti query'),
        ]);

        $this->assertSame(['NOT_JUDGED' => 1], $summary['grade_distribution']);
    }

    public function test_an_errored_run_is_counted_and_excluded_from_measurements(): void
    {
        $summary = (new GateVarianceSummary)->summarize([
            $this->run('PASS', 5, 'query', 7, 'clti query'),
            ['run' => 2, 'error' => 'network timeout', 'grade' => null, 'branches' => []],
        ]);

        $this->assertSame(1, $summary['errors']);
        $this->assertSame(1, $summary['successful_runs']);
        $this->assertSame(['PASS' => 1], $summary['grade_distribution']);
        $this->assertSame(
            ['min' => 5, 'median' => 5, 'max' => 5],
            $summary['branches']['antithrombotic_therapy']['citation_count'],
        );
        $this->assertSame(0, $summary['branches']['antithrombotic_therapy']['runs_missing']);
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
