<?php

namespace App\Console\Commands;

use App\Ai\Gate\Routing\OrientRoutingPriorService;
use App\GateEval\ScenarioRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

class GateRoutingProofCommand extends Command
{
    protected $signature = 'gate:routing-proof
        {path? : JSONL replay log}
        {--scenarios : Replay the gate-v2 scenario corpus instead of a log}';

    protected $description = 'Replay Orient routing priors against live-route logs or eval scenarios';

    public function handle(
        OrientRoutingPriorService $router,
        ScenarioRepository $scenarios,
    ): int {
        try {
            $rows = $this->option('scenarios')
                ? $this->scenarioRows($scenarios)
                : $this->logRows((string) ($this->argument('path') ?: base_path('eval/routing/sample_log.jsonl')));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $passed = 0;
        $byClass = [];
        $failures = [];
        foreach ($rows as $row) {
            $actual = $router->candidates($row['serialized_patient_model'], $row['model_candidates'] ?? []);
            $expected = $row['expected_guideline_keys'];
            $ok = array_diff($expected, $actual) === [];
            $passed += (int) $ok;
            $class = $row['turn_class'] ?? 'unknown';
            $byClass[$class] ??= ['pass' => 0, 'total' => 0];
            $byClass[$class]['pass'] += (int) $ok;
            $byClass[$class]['total']++;
            if (! $ok) {
                $failures[] = [
                    $row['label'] ?? mb_substr($row['serialized_patient_model'], 0, 48),
                    implode(', ', $expected),
                    implode(', ', $actual),
                ];
            }
        }

        $table = [];
        foreach ($byClass as $class => $score) {
            $table[] = [$class, $score['pass'], $score['total'], number_format(100 * $score['pass'] / $score['total'], 1).'%'];
        }
        $this->table(['Turn class', 'Pass', 'Total', 'Accuracy'], $table);
        $total = count($rows);
        $accuracy = $total > 0 ? $passed / $total : 0;
        $this->line(sprintf('Overall: %d/%d (%.1f%%)', $passed, $total, $accuracy * 100));
        if ($this->output->isVerbose() && $failures !== []) {
            $this->table(['Failure', 'Expected', 'Actual'], $failures);
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function logRows(string $path): array
    {
        if (! File::exists($path)) {
            throw new \RuntimeException("Replay log not found: {$path}");
        }

        $rows = [];
        foreach (File::lines($path) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $rows[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            }
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function scenarioRows(ScenarioRepository $repository): array
    {
        $rows = [];
        foreach ($repository->load() as $scenario) {
            foreach ($scenario['turns'] as $turn) {
                $expected = $turn['expected'];
                $rows[] = [
                    'label' => $scenario['id'],
                    'turn_class' => $expected['mode'],
                    'serialized_patient_model' => $turn['user'].' '.implode(' ', $expected['must_include_facts']),
                    'expected_guideline_keys' => array_slice($expected['guideline_keys'], 0, 2),
                ];
            }
        }

        return $rows;
    }
}
