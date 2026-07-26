<?php

namespace App\Console\Commands;

use App\GateEval\ExternalCloudJudge;
use App\GateEval\GateEvalRunner;
use App\GateEval\GateVarianceSummary;
use App\GateEval\HttpGateSubject;
use App\GateEval\ScenarioRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

final class GateVarianceCommand extends Command
{
    protected $signature = 'gate:variance
        {--case=batch_s2_post_vein_bypass_antithrombotics : Scenario id}
        {--runs=5 : Number of independent turns}
        {--json : Print the complete variance artifact}
        {--judge : Grade each turn with the external judge}
        {--no-judge : Explicitly skip grading (the default)}';

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
        $subject = new HttpGateSubject;
        $judge = $judged ? new ExternalCloudJudge : null;
        $records = [];

        $environment = $this->enableSnippetDigests();
        try {
            for ($runNumber = 1; $runNumber <= $runs; $runNumber++) {
                $this->info("RUN {$runNumber}/{$runs} · {$case}".($judged ? ' · JUDGED' : ' · UNJUDGED'));
                $eval = $judge === null
                    ? $runner->runUnjudged([$scenario], $subject)
                    : $runner->runJudgedWithoutArtifact([$scenario], $subject, $judge);
                $record = $variance->capture($eval, $runNumber);
                $records[] = $record;
                $this->printRun($record);
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $this->restoreSnippetDigests($environment);
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
        $this->line('Grade distribution: '.implode(', ', array_map(
            static fn (string $grade, int $count): string => "{$grade}={$count}",
            array_keys($summary['grade_distribution']),
            array_values($summary['grade_distribution']),
        )));
        foreach ($summary['branches'] as $branch => $metrics) {
            $counts = $metrics['citation_count'];
            $this->line(sprintf(
                '%s citation_count min/median/max: %s/%s/%s; distinct queries: %d',
                $branch,
                $counts['min'],
                $counts['median'],
                $counts['max'],
                $metrics['distinct_citation_query_count'],
            ));
        }
        $this->line('Distinct citation queries observed: '.$summary['distinct_citation_query_count']);
        foreach ($summary['distinct_citation_queries'] as $query) {
            $this->line('  - '.$query);
        }
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
