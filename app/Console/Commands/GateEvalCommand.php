<?php

namespace App\Console\Commands;

use App\GateEval\ExternalCloudJudge;
use App\GateEval\GateEvalRunner;
use App\GateEval\HttpGateSubject;
use App\GateEval\ScenarioRepository;
use App\GateEval\StubGateJudge;
use App\GateEval\StubGateSubject;
use Illuminate\Console\Command;
use Throwable;

class GateEvalCommand extends Command
{
    protected $signature = 'gate:eval
        {--sut=stub : stub or http}
        {--judge=stub : stub or external}
        {--scenario=* : Run only matching scenario ids}
        {--only=* : Run only scenario ids or selected turns, e.g. aaa_evolving_context:1,3}';

    protected $description = 'Run the binding Agentic Gate v2 scenario evaluation';

    public function handle(ScenarioRepository $repository, GateEvalRunner $runner): int
    {
        try {
            $scenarios = $repository->load();
            $filters = array_values($this->option('scenario'));
            if ($filters !== []) {
                $scenarios = array_values(array_filter(
                    $scenarios,
                    fn (array $scenario): bool => in_array($scenario['id'], $filters, true)
                ));
            }
            $only = array_values(array_filter(array_map('strval', (array) $this->option('only'))));
            if ($only !== []) {
                $scenarios = $this->selectOnly($scenarios, $only);
            }
            if ($scenarios === []) {
                $this->error('No scenarios matched.');

                return self::FAILURE;
            }

            $subject = $this->option('sut') === 'http' ? new HttpGateSubject : new StubGateSubject;
            $judge = $this->option('judge') === 'external' ? new ExternalCloudJudge : new StubGateJudge;
            $run = $runner->run($scenarios, $subject, $judge);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $score = $run['scorecard'];
        $this->table(
            ['Scenarios', 'Turns', 'PASS', 'MINOR', 'FAIL', 'Routing', 'No grade drop', 'Verbatim'],
            [[
                $score['scenarios'],
                $score['turns'],
                $score['grades']['PASS'],
                $score['grades']['PASS_WITH_MINOR'],
                $score['grades']['FAIL'],
                number_format($score['routing_accuracy'] * 100, 1).'%',
                $score['no_grade_drop'] ? 'YES' : 'NO',
                number_format($score['verbatim_fidelity'] * 100, 1).'%',
            ]]
        );
        $this->line('Artifact: '.$run['artifact']);

        return $score['no_grade_drop'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Keep preceding turns in a selected scenario so stateful T3 can still
     * consume T1/T2, but mark only explicitly selected turns for judging.
     *
     * @param array<int, array<string, mixed>> $scenarios
     * @param array<int, string> $selectors
     * @return array<int, array<string, mixed>>
     */
    private function selectOnly(array $scenarios, array $selectors): array
    {
        $selection = [];
        foreach ($selectors as $selector) {
            [$id, $turnList] = array_pad(explode(':', $selector, 2), 2, null);
            $id = trim($id);
            if ($id === '') {
                continue;
            }
            if ($turnList === null || trim($turnList) === '') {
                $selection[$id] = null;

                continue;
            }
            $turns = array_values(array_unique(array_filter(
                array_map('intval', explode(',', $turnList)),
                static fn (int $turn): bool => $turn > 0,
            )));
            $selection[$id] = array_values(array_unique(array_merge(
                (array) ($selection[$id] ?? []),
                $turns,
            )));
        }

        $filtered = [];
        foreach ($scenarios as $scenario) {
            $id = (string) ($scenario['id'] ?? '');
            if (! array_key_exists($id, $selection)) {
                continue;
            }
            $selectedTurns = $selection[$id];
            if ($selectedTurns === null) {
                $filtered[] = $scenario;

                continue;
            }
            $lastSelected = max($selectedTurns);
            $scenario['turns'] = array_values(array_map(
                static function (array $turn, int $index) use ($selectedTurns): array {
                    $turn['_gate_eval_selected'] = in_array($index + 1, $selectedTurns, true);
                    $turn['_gate_eval_turn_number'] = $index + 1;

                    return $turn;
                },
                array_slice((array) $scenario['turns'], 0, $lastSelected),
                array_keys(array_slice((array) $scenario['turns'], 0, $lastSelected)),
            ));
            $filtered[] = $scenario;
        }

        return $filtered;
    }
}
