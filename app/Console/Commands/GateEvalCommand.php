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
        {--scenario=* : Run only matching scenario ids}';

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
}
