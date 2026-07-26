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
            $queries = array_values(array_filter(array_map(
                static fn (mixed $value): string => trim((string) $value),
                (array) ($detail['citation_queries'] ?? [$query]),
            ), static fn (string $value): bool => $value !== ''));
            if ($queries === [] && $query !== '') {
                $queries = [$query];
            }
            $branches[$guideline] = [
                'citation_count' => (int) ($detail['citation_count'] ?? 0),
                'citation_available' => (int) ($detail['citation_available'] ?? 0),
                'narrative_available' => (int) ($detail['narrative_available'] ?? 0),
                'citation_query' => $query,
                'citation_queries' => $queries,
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
                'patient_model' => (array) ($output['patient_model'] ?? $state['patient_model'] ?? []),
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
        $observedBranches = [];
        $successfulRuns = [];
        $errors = 0;

        foreach ($runs as $run) {
            if (array_key_exists('error', $run)) {
                $errors++;

                continue;
            }

            $successfulRuns[] = $run;
            $grade = is_string($run['grade'] ?? null) ? $run['grade'] : 'NOT_JUDGED';
            $grades[$grade] = ($grades[$grade] ?? 0) + 1;

            foreach (array_keys((array) ($run['branches'] ?? [])) as $branch) {
                $observedBranches[(string) $branch] = true;
            }
        }

        ksort($grades);
        $branches = [];
        $allNormalizedQueries = [];
        $allRawQueries = [];
        foreach (array_keys($observedBranches) as $branch) {
            $counts = [];
            $runsMissing = 0;
            $normalizedQueries = [];
            $rawQueries = [];

            foreach ($successfulRuns as $run) {
                $runBranches = (array) ($run['branches'] ?? []);
                if (! array_key_exists($branch, $runBranches)) {
                    $counts[] = 0;
                    $runsMissing++;

                    continue;
                }

                $metrics = (array) $runBranches[$branch];
                $counts[] = (int) ($metrics['citation_count'] ?? 0);
                $rawQuery = (string) ($metrics['citation_query'] ?? '');
                $rawQueryPlan = array_values((array) ($metrics['citation_queries'] ?? [$rawQuery]));
                $rawQueryPlan = array_values(array_filter(array_map(
                    static fn (mixed $value): string => (string) $value,
                    $rawQueryPlan,
                ), static fn (string $value): bool => trim($value) !== ''));
                $normalizedQueryPlan = array_map($this->normalizeQuery(...), $rawQueryPlan);
                $rawQueryPlanText = implode(' || ', $rawQueryPlan);
                $normalizedQueryPlanKey = json_encode(
                    $normalizedQueryPlan,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                );
                $rawQueries[$rawQueryPlanText] = true;
                $normalizedQueries[$normalizedQueryPlanKey] = true;
                $allRawQueries[$rawQueryPlanText] = true;
                $allNormalizedQueries[$normalizedQueryPlanKey] = true;
            }

            $rawQueryStrings = array_keys($rawQueries);
            sort($rawQueryStrings);
            $branches[$branch] = [
                'citation_count' => $this->distribution($counts),
                'runs_missing' => $runsMissing,
                'distinct_citation_query_count' => count($normalizedQueries),
                'distinct_citation_queries' => $rawQueryStrings,
                'raw_citation_queries' => $rawQueryStrings,
            ];
        }
        ksort($branches);

        $rawQueries = array_keys($allRawQueries);
        sort($rawQueries);

        return [
            'successful_runs' => count($successfulRuns),
            'errors' => $errors,
            'grade_distribution' => $grades,
            'branches' => $branches,
            'distinct_citation_query_count' => count($allNormalizedQueries),
            'distinct_citation_queries' => $rawQueries,
            'raw_citation_queries' => $rawQueries,
        ];
    }

    private function normalizeQuery(string $query): string
    {
        return mb_strtolower(trim($query));
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
