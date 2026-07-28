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
                        'content' => Rubric::prompt(),
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
        if (! is_array($judgment) || ! is_array($judgment['clinical'] ?? null) || ! is_array($judgment['mechanical'] ?? null)) {
            throw new RuntimeException('External judge returned an invalid judgment payload.');
        }

        $clinical = $this->dimension($judgment['clinical'], 'clinical');
        $mechanical = $this->dimension($judgment['mechanical'], 'mechanical');

        // Compatibility projection: old artifact readers still see their three
        // top-level fields. Grade deliberately projects the clinical axis only,
        // so a mechanical defect can never mask or rewrite the clinical result.
        return [
            'grade' => $clinical['grade'],
            'failure_labels' => array_values(array_unique([
                ...$clinical['labels'],
                ...$mechanical['labels'],
            ])),
            'reason' => (string) ($clinical['reason'] ?? ''),
            'rubric_version' => Rubric::VERSION,
            'needs_clinician_review' => (bool) ($judgment['needs_clinician_review'] ?? false),
            'review_reason' => (string) ($judgment['review_reason'] ?? ''),
            'clinical' => $clinical,
            'mechanical' => $mechanical,
        ];
    }

    public function identity(): string
    {
        return (string) config('gate-eval.judge.model');
    }

    /**
     * @param  array<string, mixed>  $dimension
     * @return array<string, mixed>
     */
    private function dimension(array $dimension, string $name): array
    {
        $grade = (string) ($dimension['grade'] ?? '');
        if (! in_array($grade, ['FAIL', 'PASS_WITH_MINOR', 'PASS'], true)) {
            throw new RuntimeException("External judge returned an invalid {$name} grade.");
        }

        $dimension['grade'] = $grade;
        $dimension['labels'] = array_values($dimension['labels'] ?? []);
        $dimension['reason'] = (string) ($dimension['reason'] ?? '');

        return $dimension;
    }
}
