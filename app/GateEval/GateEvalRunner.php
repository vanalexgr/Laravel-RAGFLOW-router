<?php

namespace App\GateEval;

use App\GateEval\Contracts\GateJudge;
use App\GateEval\Contracts\GateSubject;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class GateEvalRunner
{
    private const GRADE_RANK = ['FAIL' => 0, 'PASS_WITH_MINOR' => 1, 'PASS' => 2];

    /**
     * @param  array<int, array<string, mixed>>  $scenarios
     * @return array<string, mixed>
     */
    public function run(array $scenarios, GateSubject $subject, GateJudge $judge): array
    {
        if ($subject->identity() === $judge->identity()) {
            throw new RuntimeException('The external judge must not be the system-under-test model.');
        }

        $results = [];
        $totals = ['PASS' => 0, 'PASS_WITH_MINOR' => 0, 'FAIL' => 0];
        $routingPassed = $routingTotal = $noDropPassed = $noDropTotal = 0;

        foreach ($scenarios as $scenario) {
            $priorOutputs = [];
            foreach ($scenario['turns'] as $index => $turn) {
                $output = $subject->runTurn($scenario, $turn, $index, $priorOutputs);
                $priorOutputs[] = $output;
                $checks = $this->deterministicChecks($turn['expected'], $output);
                $judgment = $judge->judge($scenario, $turn, $output);
                $grade = $judgment['grade'];
                if (! isset(self::GRADE_RANK[$grade])) {
                    throw new RuntimeException("Judge returned unknown grade: {$grade}");
                }

                $baseline = $turn['expected']['baseline_grade'] ?? null;
                $noDrop = $baseline === null || self::GRADE_RANK[$grade] >= self::GRADE_RANK[$baseline];
                if ($baseline !== null) {
                    $noDropTotal++;
                    $noDropPassed += (int) $noDrop;
                }

                $routingTotal++;
                $routingPassed += (int) $checks['routing'];
                $totals[$grade]++;

                $results[] = [
                    'scenario_id' => $scenario['id'],
                    'turn' => $index + 1,
                    'baseline_grade' => $baseline,
                    'grade' => $grade,
                    'no_grade_drop' => $noDrop,
                    'deterministic_checks' => $checks,
                    'judgment' => $judgment,
                    'output' => $output,
                    'stage_trace' => $output['stage_trace'] ?? [],
                ];
            }
        }

        $scorecard = [
            'subject' => $subject->identity(),
            'judge' => $judge->identity(),
            'scenarios' => count($scenarios),
            'turns' => count($results),
            'grades' => $totals,
            'routing_accuracy' => $routingTotal > 0 ? $routingPassed / $routingTotal : 0,
            'no_grade_drop' => $noDropTotal === 0 || $noDropPassed === $noDropTotal,
            'baseline_cases' => $noDropTotal,
            'verbatim_fidelity' => $this->average($results, 'deterministic_checks.verbatim_fidelity'),
        ];

        $run = [
            'created_at' => now()->toIso8601String(),
            'scorecard' => $scorecard,
            'results' => $results,
        ];

        $path = trim((string) config('gate-eval.runs_path'), '/').'/'.now()->format('Ymd_His_u').'.json';
        Storage::disk((string) config('gate-eval.runs_disk'))->put(
            $path,
            json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
        $run['artifact'] = $path;

        return $run;
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $output
     * @return array<string, bool|float>
     */
    private function deterministicChecks(array $expected, array $output): array
    {
        $expectedCoverage = Arr::get($expected, 'evidence_status.coverage');
        $allowedCoverage = is_array($expectedCoverage) ? $expectedCoverage : [$expectedCoverage];
        $actualKeys = array_values($output['guideline_keys'] ?? []);
        $expectedKeys = array_values($expected['guideline_keys']);

        return [
            'mode' => ($output['mode'] ?? null) === $expected['mode'],
            'same_case' => ($output['same_case'] ?? null) === $expected['same_case'],
            'routing' => array_diff($expectedKeys, $actualKeys) === [],
            'max_questions' => count($output['questions'] ?? []) <= $expected['max_questions'],
            'evidence_status' => in_array(Arr::get($output, 'evidence_status.coverage'), $allowedCoverage, true),
            'interpretive_frame_present' => trim((string) ($output['interpretive_frame'] ?? '')) !== '',
            'dose_lint' => preg_match('/\b\d+(?:\.\d+)?\s*(?:mg(?:\/kg)?|mcg|units?)\b/i', (string) ($output['interpretive_frame'] ?? '')) !== 1,
            'verbatim_fidelity' => $this->fidelity(
                (string) ($output['answer_markdown'] ?? ''),
                (string) ($output['rendered_answer'] ?? $output['answer_markdown'] ?? '')
            ),
        ];
    }

    private function fidelity(string $expected, string $actual): float
    {
        if ($expected === '') {
            return $actual === '' ? 1.0 : 0.0;
        }
        similar_text($expected, $actual, $percent);

        return $percent / 100;
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    private function average(array $results, string $path): float
    {
        if ($results === []) {
            return 0;
        }

        return array_sum(array_map(fn (array $result): float => (float) data_get($result, $path, 0), $results))
            / count($results);
    }
}
