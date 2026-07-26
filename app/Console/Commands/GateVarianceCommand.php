<?php

namespace App\Console\Commands;

use App\GateEval\ExternalCloudJudge;
use App\GateEval\GateEvalRunner;
use App\GateEval\GateVarianceSummary;
use App\GateEval\HttpGateSubject;
use App\GateEval\ScenarioRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Throwable;

final class GateVarianceCommand extends Command
{
    private const SINGLE_RUN_PREFIX = 'GATE_VARIANCE_RUN_JSON:';

    protected $signature = 'gate:variance
        {--case=batch_s2_post_vein_bypass_antithrombotics : Scenario id}
        {--runs=5 : Number of independent turns}
        {--json : Print the complete variance artifact}
        {--judge : Grade each turn with the external judge}
        {--no-judge : Explicitly skip grading (the default)}
        {--single-run : Execute one isolated run for the parent variance process}';

    protected $description = 'Measure Gate eval retrieval and grade variance over repeated real-path turns';

    public function handle(
        ScenarioRepository $repository,
        GateEvalRunner $runner,
        GateVarianceSummary $variance,
    ): int {
        $case = trim((string) $this->option('case'));
        $runsOption = (string) $this->option('runs');
        if ($case === '' || ! ctype_digit($runsOption) || (int) $runsOption < 1) {
            $this->error('--case must be non-empty and --runs must be a positive integer.');

            return self::FAILURE;
        }
        if ($this->option('judge') && $this->option('no-judge')) {
            $this->error('--judge and --no-judge cannot be used together.');

            return self::FAILURE;
        }

        $runs = (int) $runsOption;
        $scenario = collect($repository->load())->first(
            static fn (array $candidate): bool => ($candidate['id'] ?? null) === $case,
        );
        if (! is_array($scenario)) {
            $this->error("Scenario not found: {$case}");

            return self::FAILURE;
        }
        if (count((array) $scenario['turns']) !== 1) {
            $this->error("Variance scenarios must contain exactly one turn: {$case}");

            return self::FAILURE;
        }

        $judged = (bool) $this->option('judge');
        if ($this->option('single-run')) {
            return $this->executeSingleRun($runner, $variance, $scenario, $judged);
        }

        $records = [];
        $succeeded = 0;
        for ($runNumber = 1; $runNumber <= $runs; $runNumber++) {
            $this->info("RUN {$runNumber}/{$runs} · {$case}".($judged ? ' · JUDGED' : ' · UNJUDGED'));
            try {
                $record = $this->runIsolated($case, $judged, $runNumber);
                if (array_key_exists('error', $record)) {
                    $this->error('  '.$record['error']);
                } else {
                    $succeeded++;
                }
                $this->printRun($record);
            } catch (Throwable $exception) {
                $record = [
                    'run' => $runNumber,
                    'error' => $exception->getMessage(),
                    'grade' => null,
                    'branches' => [],
                ];
                $this->error('  '.$exception->getMessage());
            }
            $records[] = $record;
        }

        if ($succeeded === 0) {
            $this->error('All variance runs failed; no summary artifact was written.');

            return self::FAILURE;
        }

        $summary = $variance->summarize($records);
        $artifact = [
            'created_at' => now()->toIso8601String(),
            'case' => $case,
            'requested_runs' => $runs,
            'judged' => $judged,
            'runs' => $records,
            'summary' => $summary,
        ];
        $safeCase = preg_replace('/[^A-Za-z0-9_-]+/', '_', $case) ?: 'case';
        $path = base_path('docs/eval/variance_'.$safeCase.'_'.now()->format('Ymd_His').'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode(
            $artifact,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        $this->newLine();
        $this->printSummary($summary);
        $this->line('Artifact: '.$path);
        if ($this->option('json')) {
            $this->line(json_encode(
                $artifact,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function printRun(array $record): void
    {
        if (array_key_exists('error', $record)) {
            return;
        }

        $this->line('  grade='.($record['grade'] ?? 'NOT_JUDGED'));
        foreach ((array) $record['branches'] as $branch => $metrics) {
            $this->line(sprintf(
                '  %s citations=%d available=%d narrative=%d query_chars=%d top_k=%d',
                $branch,
                $metrics['citation_count'],
                $metrics['citation_available'],
                $metrics['narrative_available'],
                $metrics['citation_query_chars'],
                $metrics['citation_top_k'],
            ));
            $this->line('    query: '.$metrics['citation_query']);
        }
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function printSummary(array $summary): void
    {
        $this->info('Variance summary');
        $this->line(sprintf(
            'Successful runs: %d; errors: %d',
            $summary['successful_runs'],
            $summary['errors'],
        ));
        $this->line('Grade distribution: '.implode(', ', array_map(
            static fn (string $grade, int $count): string => "{$grade}={$count}",
            array_keys($summary['grade_distribution']),
            array_values($summary['grade_distribution']),
        )));
        foreach ($summary['branches'] as $branch => $metrics) {
            $counts = $metrics['citation_count'];
            $this->line(sprintf(
                '%s citation_count min/median/max: %s/%s/%s; distinct queries: %d; runs missing: %d',
                $branch,
                $counts['min'],
                $counts['median'],
                $counts['max'],
                $metrics['distinct_citation_query_count'],
                $metrics['runs_missing'],
            ));
        }
        $this->line('Distinct citation queries observed: '.$summary['distinct_citation_query_count']);
        foreach ($summary['distinct_citation_queries'] as $query) {
            $this->line('  - '.$query);
        }
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    private function executeSingleRun(
        GateEvalRunner $runner,
        GateVarianceSummary $variance,
        array $scenario,
        bool $judged,
    ): int {
        $environment = $this->enableSnippetDigests();
        try {
            // These objects are deliberately constructed only after the fresh
            // child Laravel process has booted, so no run can reuse their state.
            $subject = new HttpGateSubject;
            $judge = $judged ? new ExternalCloudJudge : null;
            $eval = $judge === null
                ? $runner->runUnjudged([$scenario], $subject)
                : $runner->runJudgedWithoutArtifact([$scenario], $subject, $judge);
            $record = $variance->capture($eval, 1);
            $status = self::SUCCESS;
        } catch (Throwable $exception) {
            $record = [
                'run' => 1,
                'error' => $exception->getMessage(),
                'grade' => null,
                'branches' => [],
            ];
            $status = self::FAILURE;
        } finally {
            $this->restoreSnippetDigests($environment);
        }

        $this->line(self::SINGLE_RUN_PREFIX.base64_encode(json_encode(
            $record,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        )));

        return $status;
    }

    /**
     * @return array<string, mixed>
     */
    private function runIsolated(string $case, bool $judged, int $runNumber): array
    {
        $process = new Process([
            PHP_BINARY,
            base_path('artisan'),
            'gate:variance',
            '--single-run',
            '--runs=1',
            '--case='.$case,
            $judged ? '--judge' : '--no-judge',
        ], base_path());
        $process->setTimeout(null);
        $process->run();

        $output = $process->getOutput()."\n".$process->getErrorOutput();
        if (! preg_match(
            '/^'.preg_quote(self::SINGLE_RUN_PREFIX, '/').'([A-Za-z0-9+\/=]+)$/m',
            $output,
            $matches,
        )) {
            $message = trim($process->getErrorOutput()) ?: trim($process->getOutput());
            throw new \RuntimeException($message !== ''
                ? "Isolated run did not return a result: {$message}"
                : 'Isolated run did not return a result.');
        }

        $json = base64_decode($matches[1], true);
        $record = is_string($json) ? json_decode($json, true, 512, JSON_THROW_ON_ERROR) : null;
        if (! is_array($record)) {
            throw new \RuntimeException('Isolated run returned an invalid result.');
        }
        $record['run'] = $runNumber;

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    private function enableSnippetDigests(): array
    {
        $name = 'GATE_V2_PERSIST_SNIPPET_DIGESTS';
        $state = [
            'environment' => getenv($name),
            'env_exists' => array_key_exists($name, $_ENV),
            'env' => $_ENV[$name] ?? null,
            'server_exists' => array_key_exists($name, $_SERVER),
            'server' => $_SERVER[$name] ?? null,
            'config' => config('gate-v2.audit.persist_snippet_digests'),
        ];

        putenv("{$name}=true");
        $_ENV[$name] = 'true';
        $_SERVER[$name] = 'true';
        config()->set('gate-v2.audit.persist_snippet_digests', true);

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function restoreSnippetDigests(array $state): void
    {
        $name = 'GATE_V2_PERSIST_SNIPPET_DIGESTS';
        if ($state['environment'] === false) {
            putenv($name);
        } else {
            putenv("{$name}={$state['environment']}");
        }

        if ($state['env_exists']) {
            $_ENV[$name] = $state['env'];
        } else {
            unset($_ENV[$name]);
        }
        if ($state['server_exists']) {
            $_SERVER[$name] = $state['server'];
        } else {
            unset($_SERVER[$name]);
        }
        config()->set('gate-v2.audit.persist_snippet_digests', $state['config']);
    }
}
