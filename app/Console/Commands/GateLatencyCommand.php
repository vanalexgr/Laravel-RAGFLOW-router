<?php

namespace App\Console\Commands;

use App\Ai\Gate\GateWorkflowService;
use App\Ai\Gate\Latency\GateLatencyScorecard;
use App\GateEval\ScenarioRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class GateLatencyCommand extends Command
{
    private const DEFAULT_SCENARIOS = [
        'aaa_evolving_context',
        'adversarial_retrieval_trap',
    ];

    protected $signature = 'gate:latency
        {--scenario=* : Scenario ids; defaults to AAA and retrieval trap}
        {--repeat=1 : Number of complete scenario repetitions}
        {--json : Print the complete benchmark artifact}';

    protected $description = 'Benchmark Gate v2 stage and deep-turn latency with raw trace artifacts';

    public function handle(
        ScenarioRepository $repository,
        GateWorkflowService $workflow,
        GateLatencyScorecard $scorecard,
    ): int {
        $filters = array_values(array_filter(
            (array) $this->option('scenario'),
            static fn (mixed $value): bool => is_string($value) && $value !== '',
        ));
        $filters = $filters === [] ? self::DEFAULT_SCENARIOS : $filters;
        $repeat = max(1, min(10, (int) $this->option('repeat')));

        $scenarios = array_values(array_filter(
            $repository->load(),
            static fn (array $scenario): bool => in_array($scenario['id'], $filters, true),
        ));
        if (count($scenarios) !== count(array_unique($filters))) {
            $found = array_column($scenarios, 'id');
            $this->error('Missing scenario(s): '.implode(', ', array_diff($filters, $found)));

            return self::FAILURE;
        }

        $turnResults = [];
        for ($run = 1; $run <= $repeat; $run++) {
            foreach ($scenarios as $scenario) {
                $state = [];
                foreach ((array) $scenario['turns'] as $turnIndex => $turn) {
                    $this->info(sprintf(
                        'RUN %d/%d · %s · TURN %d',
                        $run,
                        $repeat,
                        $scenario['id'],
                        $turnIndex + 1,
                    ));

                    $turnStarted = microtime(true);
                    $error = null;
                    try {
                        $result = $workflow->run((string) $turn['user'], $state);
                    } catch (Throwable $exception) {
                        $error = $exception::class.': '.$exception->getMessage();
                        $result = [
                            'stage_trace' => $workflow->lastTrace(),
                            'state' => $state,
                        ];
                    }
                    $state = (array) ($result['state'] ?? $state);
                    $trace = (array) ($result['stage_trace'] ?? []);
                    $total = max(
                        (int) round((microtime(true) - $turnStarted) * 1000),
                        max(array_map(
                            static fn (array $entry): int => (int) ($entry['elapsed_ms'] ?? 0),
                            $trace ?: [['elapsed_ms' => 0]],
                        )),
                    );
                    $turnResults[] = [
                        'run' => $run,
                        'scenario' => $scenario['id'],
                        'turn' => $turnIndex + 1,
                        'total_ms' => $total,
                        'mode' => $result['mode'] ?? null,
                        'decision' => $result['decision'] ?? null,
                        'best_score' => $result['best_score'] ?? null,
                        'critic_approved' => $result['critic']['approved'] ?? null,
                        'error' => $error,
                        'answer_markdown' => $result['answer_markdown'] ?? null,
                        'stage_trace' => $trace,
                    ];
                    $this->line(sprintf(
                        '  total=%dms mode=%s score=%s approved=%s',
                        $total,
                        $result['mode'] ?? 'unknown',
                        isset($result['best_score']) ? (string) $result['best_score'] : 'n/a',
                        isset($result['critic']['approved'])
                            ? (($result['critic']['approved'] ?? false) ? 'yes' : 'no')
                            : 'n/a',
                    ));
                    if ($error !== null) {
                        $this->error('  '.$error);
                    }
                }
            }
        }

        $summary = $scorecard->summarize($turnResults);
        $this->table(
            ['Stage', 'Calls', 'p50 ms', 'p95 ms', 'max ms'],
            array_merge(
                [[
                    'TOTAL/TURN',
                    $summary['total']['count'],
                    $summary['total']['p50_ms'],
                    $summary['total']['p95_ms'],
                    $summary['total']['max_ms'],
                ]],
                array_map(
                    static fn (string $stage, array $stats): array => [
                        $stage,
                        $stats['count'],
                        $stats['p50_ms'],
                        $stats['p95_ms'],
                        $stats['max_ms'],
                    ],
                    array_keys($summary['stages']),
                    array_values($summary['stages']),
                ),
            ),
        );

        $artifact = [
            'created_at' => now()->toIso8601String(),
            'configuration' => [
                'provider' => config('gate-v2.provider'),
                'model' => config('gate-v2.model'),
                'deadline_seconds' => config('gate-v2.deadline_seconds'),
                'scenarios' => $filters,
                'repeat' => $repeat,
            ],
            'summary' => $summary,
            'turns' => $turnResults,
        ];
        $path = 'gate-latency/runs/'.now()->format('Ymd_His_u').'.json';
        Storage::disk('local')->put(
            $path,
            json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
        $this->line('Artifact: '.$path);

        if ($this->option('json')) {
            $this->line(json_encode(
                $artifact,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        }

        return self::SUCCESS;
    }
}
