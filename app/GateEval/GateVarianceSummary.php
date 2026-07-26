<?php

namespace App\GateEval;

final class GateVarianceSummary
{
    /**
     * Reduce a normal GateEvalRunner result to the fields needed for variance
     * analysis. If a branch retries, its final retrieval attempt is recorded.
     *
     * @param  array<string, mixed>  $eval
     * @return array<string, mixed>
     */
    public function capture(array $eval, int $runNumber): array
    {
        $result = (array) (($eval['results'] ?? [])[0] ?? []);
        $output = (array) ($result['output'] ?? []);
        $branches = [];

        foreach ((array) ($result['stage_trace'] ?? $output['stage_trace'] ?? []) as $entry) {
            if (($entry['stage'] ?? null) !== 'retrieve') {
                continue;
            }

            $detail = (array) ($entry['detail'] ?? []);
            $guideline = trim((string) ($detail['guideline'] ?? ''));
            if ($guideline === '') {
                continue;
            }

            $query = (string) ($detail['citation_query'] ?? '');
            $branches[$guideline] = [
                'citation_count' => (int) ($detail['citation_count'] ?? 0),
                'citation_available' => (int) ($detail['citation_available'] ?? 0),
                'narrative_available' => (int) ($detail['narrative_available'] ?? 0),
                'citation_query' => $query,
                'citation_query_chars' => array_key_exists('citation_query_chars', $detail)
                    ? (int) $detail['citation_query_chars']
                    : mb_strlen($query),
                'citation_top_k' => (int) ($detail['citation_top_k'] ?? 0),
            ];
        }

        $state = (array) ($output['state'] ?? []);

        return [
            'run' => $runNumber,
            'grade' => $result['grade'] ?? null,
            'branches' => $branches,
            'orient' => [
                'must_include_terms' => array_values((array) ($state['must_include_terms'] ?? [])),
                'expansion_terms' => array_values((array) ($state['expansion_terms'] ?? [])),
                'interpretation_terms' => array_values((array) ($state['interpretation_terms'] ?? [])),
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $runs
     * @return array<string, mixed>
     */
    public function summarize(array $runs): array
    {
        $grades = [];
        $branchCounts = [];
        $branchQueries = [];
        $allQueries = [];

        foreach ($runs as $run) {
            $grade = is_string($run['grade'] ?? null) ? $run['grade'] : 'NOT_JUDGED';
            $grades[$grade] = ($grades[$grade] ?? 0) + 1;

            foreach ((array) ($run['branches'] ?? []) as $branch => $metrics) {
                $branchCounts[$branch][] = (int) ($metrics['citation_count'] ?? 0);
                $query = (string) ($metrics['citation_query'] ?? '');
                $branchQueries[$branch][$query] = true;
                $allQueries[$query] = true;
            }
        }

        ksort($grades);
        $branches = [];
        foreach ($branchCounts as $branch => $counts) {
            $queries = array_keys($branchQueries[$branch] ?? []);
            sort($queries);
            $branches[$branch] = [
                'citation_count' => $this->distribution($counts),
                'distinct_citation_query_count' => count($queries),
                'distinct_citation_queries' => $queries,
            ];
        }
        ksort($branches);

        $queries = array_keys($allQueries);
        sort($queries);

        return [
            'grade_distribution' => $grades,
            'branches' => $branches,
            'distinct_citation_query_count' => count($queries),
            'distinct_citation_queries' => $queries,
        ];
    }

    /**
     * @param  array<int, int|float>  $values
     * @return array{min:int|float|null, median:int|float|null, max:int|float|null}
     */
    public function distribution(array $values): array
    {
        if ($values === []) {
            return ['min' => null, 'median' => null, 'max' => null];
        }

        sort($values, SORT_NUMERIC);
        $count = count($values);
        $middle = intdiv($count, 2);
        $median = $count % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;

        return [
            'min' => $values[0],
            'median' => $median,
            'max' => $values[$count - 1],
        ];
    }
}
