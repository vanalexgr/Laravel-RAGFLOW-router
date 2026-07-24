<?php

namespace App\Console\Commands;

use App\Ai\Gate\GateWorkflowService;
use App\Ai\Gate\Progress\GateProgress;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class GateProbe2Command extends Command
{
    protected $signature = 'gate:probe2
        {case? : One clinical case/question}
        {--scenario= : Scenario id from eval/scenarios}
        {--json : Print the full result JSON}';

    protected $description = 'Run the Agentic Gate v2 deterministic workflow';

    public function handle(GateWorkflowService $workflow): int
    {
        try {
            $turns = $this->turns();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $state = [];
        foreach ($turns as $index => $turn) {
            $this->newLine();
            $this->info(sprintf('TURN %d: %s', $index + 1, $turn));
            $progress = new class($this) implements GateProgress
            {
                public function __construct(private readonly Command $command) {}

                public function emit(string $stage, string $message, array $context = []): void
                {
                    $this->command->line(sprintf('[%s] %s', $stage, $message));
                }
            };

            try {
                $result = $workflow->run($turn, $state, $progress);
            } catch (Throwable $exception) {
                $this->error($exception::class.': '.$exception->getMessage());

                return self::FAILURE;
            }
            $state = (array) ($result['state'] ?? $state);

            $this->line('mode: '.($result['mode'] ?? 'unknown'));
            $this->line('decision: '.($result['decision'] ?? 'unknown'));
            $this->line('guidelines: '.implode(', ', (array) ($result['routed_guidelines'] ?? [])));
            $this->line('iterations: '.($result['iterations'] ?? 0));
            $this->newLine();
            $this->line((string) ($result['answer_markdown'] ?? ''));
            $this->newLine();
            $this->comment('STAGE TRACE');
            foreach ((array) ($result['stage_trace'] ?? []) as $entry) {
                $this->line(json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }

            if ($this->option('json')) {
                $this->newLine();
                $this->comment('FULL RESULT');
                $this->line(json_encode(
                    $result,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ));
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function turns(): array
    {
        $scenarioId = trim((string) $this->option('scenario'));
        if ($scenarioId === '') {
            $case = trim((string) $this->argument('case'));
            if ($case === '') {
                throw new RuntimeException('Provide a case argument or --scenario=id.');
            }

            return [$case];
        }

        foreach (glob(base_path('eval/scenarios/*.json')) ?: [] as $path) {
            $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $scenarios = array_is_list($decoded) ? $decoded : [$decoded];
            foreach ($scenarios as $scenario) {
                if (($scenario['id'] ?? null) === $scenarioId) {
                    return array_values(array_map(
                        static fn (array $turn): string => (string) $turn['user'],
                        (array) ($scenario['turns'] ?? []),
                    ));
                }
            }
        }

        throw new RuntimeException("Scenario '{$scenarioId}' was not found.");
    }
}
