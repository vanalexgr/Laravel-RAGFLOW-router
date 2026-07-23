<?php

namespace App\GateEval;

use App\GateEval\Contracts\GateSubject;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class HttpGateSubject implements GateSubject
{
    public function runTurn(array $scenario, array $turn, int $turnIndex, array $priorOutputs): array
    {
        $url = (string) config('gate-eval.subject.url');
        if ($url === '') {
            throw new RuntimeException('GATE_EVAL_SUT_URL is required for the HTTP subject.');
        }

        return Http::acceptJson()
            ->timeout((int) config('gate-eval.subject.timeout', 120))
            ->post($url, [
                'scenario_id' => $scenario['id'],
                'turn_index' => $turnIndex,
                'user' => $turn['user'],
                'attachments' => $turn['attachments'] ?? [],
                'prior_outputs' => $priorOutputs,
            ])
            ->throw()
            ->json();
    }

    public function identity(): string
    {
        return (string) config('gate-eval.subject.model');
    }
}
