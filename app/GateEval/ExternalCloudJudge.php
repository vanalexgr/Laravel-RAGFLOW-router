<?php

namespace App\GateEval;

use App\GateEval\Contracts\GateJudge;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ExternalCloudJudge implements GateJudge
{
    public function judge(array $scenario, array $turn, array $output): array
    {
        $url = (string) config('gate-eval.judge.url');
        $key = (string) config('gate-eval.judge.api_key');
        if ($url === '' || $key === '') {
            throw new RuntimeException('GATE_EVAL_JUDGE_URL and GATE_EVAL_JUDGE_API_KEY are required.');
        }

        $response = Http::withToken($key)
            ->acceptJson()
            ->timeout((int) config('gate-eval.judge.timeout', 120))
            ->post($url, [
                'model' => $this->identity(),
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are an external clinical-answer evaluator. Grade only against the supplied rubric. Return JSON with grade (FAIL, PASS_WITH_MINOR, or PASS), failure_labels, and reason. Do not add clinical content.',
                    ],
                    [
                        'role' => 'user',
                        'content' => json_encode([
                            'scenario' => $scenario,
                            'turn' => $turn,
                            'system_output' => $output,
                        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ],
                ],
                'response_format' => ['type' => 'json_object'],
            ])
            ->throw()
            ->json();

        $content = data_get($response, 'choices.0.message.content');
        $judgment = is_string($content) ? json_decode($content, true, flags: JSON_THROW_ON_ERROR) : null;
        if (! is_array($judgment) || ! isset($judgment['grade'], $judgment['reason'])) {
            throw new RuntimeException('External judge returned an invalid judgment payload.');
        }

        return [
            'grade' => (string) $judgment['grade'],
            'failure_labels' => array_values($judgment['failure_labels'] ?? []),
            'reason' => (string) $judgment['reason'],
        ];
    }

    public function identity(): string
    {
        return (string) config('gate-eval.judge.model');
    }
}
