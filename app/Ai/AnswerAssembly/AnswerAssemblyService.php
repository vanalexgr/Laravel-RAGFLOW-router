<?php

namespace App\Ai\AnswerAssembly;

use LogicException;
use RuntimeException;

final class AnswerAssemblyService
{
    public function __construct(
        private readonly AnswerSkeletonRenderer $renderer,
    ) {}

    /**
     * @param  array<string, mixed>  $pipeline
     * @return array<string, mixed>
     */
    public function assemble(array $pipeline): array
    {
        if ((string) config('gate-v2.synthesis_owner', 'adapter') !== 'laravel') {
            throw new LogicException('Laravel synthesis is disabled; SYNTHESIS_OWNER remains adapter.');
        }

        $mode = (string) ($pipeline['response_mode'] ?? 'case');
        $evidenceStatus = (array) ($pipeline['evidence_status'] ?? []);
        $payload = [
            'question' => (string) ($pipeline['question'] ?? ''),
            'response_mode' => $mode,
            'planner' => (array) ($pipeline['planner'] ?? []),
            'patient_facts' => (array) ($pipeline['patient_facts'] ?? []),
            'retrieved_evidence' => array_slice((array) ($pipeline['retrieved_evidence'] ?? []), 0, 12),
            'evidence_status' => $evidenceStatus,
            'section_contract' => [
                'two_frames_required' => true,
                'non_esvs_banner_owned_by_php' => true,
                'assets_owned_by_php' => true,
            ],
            'audited_snippets' => $this->auditedSnippets(),
        ];

        $response = (new AnswerFillAgent)->prompt(
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            provider: (string) config('gate-v2.synthesis.provider', 'openai'),
            model: $this->model(),
            timeout: max(1, (int) config('gate-v2.synthesis.timeout_seconds', 60)),
        )->toArray();
        if ($response === []) {
            throw new RuntimeException('AnswerFillAgent returned an empty structured response.');
        }

        $assets = (array) ($pipeline['assets'] ?? []);

        return [
            'answer_markdown' => $this->renderer->render($mode, $evidenceStatus, $response, $assets),
            'questions' => array_slice(array_values((array) ($response['questions'] ?? [])), 0, 2),
            'evidence_status' => $evidenceStatus,
            'assets' => $assets,
            'synthesis' => [
                'owner' => 'laravel',
                'model_target' => (string) config('gate-v2.synthesis_model', 'cloud'),
                'model' => $this->model(),
                'fill_calls' => 1,
            ],
        ];
    }

    private function model(): string
    {
        return (string) config(
            (string) config('gate-v2.synthesis_model', 'cloud') === 'local'
                ? 'gate-v2.synthesis.local_model'
                : 'gate-v2.synthesis.cloud_model',
        );
    }

    /**
     * @return array<int, string>
     */
    private function auditedSnippets(): array
    {
        if (! (bool) config('gate-v2.audited_snippets.enabled', false)) {
            return [];
        }

        // TODO(human): This flag must remain OFF until a named clinician signs every candidate.
        $path = (string) config('gate-v2.audited_snippets.path');
        if ($path === '' || ! is_file($path)) {
            throw new RuntimeException('Enabled audited snippet library is unavailable.');
        }

        return [(string) file_get_contents($path)];
    }
}
