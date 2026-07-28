<?php

namespace App\Ai\Gate;

final class GateDecisionTail
{
    public const NON_ESVS_BANNER = 'Non-ESVS interpretation (clinical reasoning beyond the retrieved guideline text):';

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<int, array<string, mixed>>  $openQuestions
     * @return array<string, mixed>
     */
    public function finalize(
        array $candidate,
        array $openQuestions = [],
        array $degradation = [],
    ): array
    {
        $closed = [];
        foreach ($openQuestions as $question) {
            if (in_array(($question['status'] ?? null), ['answered', 'declined'], true)) {
                $closed[] = $this->normalize((string) ($question['question'] ?? ''));
            }
        }

        $highImpact = array_values(array_filter(
            (array) ($candidate['unknowns'] ?? []),
            static fn (mixed $unknown): bool => is_array($unknown)
                && ($unknown['branch_impact'] ?? null) === 'high'
                && ($unknown['currently_known'] ?? true) === false,
        ));
        $questions = array_values(array_filter(
            (array) ($candidate['questions'] ?? []),
            fn (mixed $question): bool => is_array($question)
                && ! in_array($this->normalize((string) ($question['question'] ?? '')), $closed, true),
        ));
        $questions = array_slice($questions, 0, 2);
        $decision = $highImpact !== [] && $questions !== [] ? 'ask' : 'proceed';

        $interpretive = trim((string) ($candidate['interpretive_frame'] ?? ''));
        $lint = $this->doseLint($interpretive);
        $renderedInterpretive = self::NON_ESVS_BANNER."\n".$interpretive;
        $grounded = trim((string) ($candidate['guideline_grounded_answer'] ?? ''));

        $degradationNotice = $this->degradationNotice($degradation);

        return array_merge($candidate, [
            'decision' => $decision,
            'questions' => $decision === 'ask' ? $questions : [],
            'interpretive_frame' => $renderedInterpretive,
            'lint_violations' => $lint,
            'degradation' => array_values($degradation),
            'answer_markdown' => $degradationNotice
                ."## ESVS-grounded answer\n\n"
                .($grounded !== '' ? $grounded : '_No grounded ESVS statement was located._')
                ."\n\n## Interpretation\n\n".$renderedInterpretive,
        ]);
    }

    /**
     * Degradation is safety-relevant output, not diagnostic metadata. Keep the
     * machine-readable records and render the same limitations before the answer.
     *
     * @param  array<int, array<string, mixed>>  $degradation
     */
    private function degradationNotice(array $degradation): string
    {
        if ($degradation === []) {
            return '';
        }

        $items = array_map(static function (array $item): string {
            $stage = (string) ($item['stage'] ?? 'pipeline');
            $reason = str_replace('_', ' ', (string) ($item['reason'] ?? 'unavailable'));
            $coverage = isset($item['evidence_branch_count'], $item['routed_branch_count'])
                ? sprintf(
                    ' (%d of %d routed guidelines supplied evidence)',
                    (int) $item['evidence_branch_count'],
                    (int) $item['routed_branch_count'],
                )
                : '';
            $unavailable = array_values(array_filter(array_map(
                'strval',
                (array) ($item['unavailable'] ?? []),
            )));
            $suffix = $unavailable === []
                ? ''
                : '; unavailable: '.implode(', ', $unavailable);

            return "- {$stage}: {$reason}{$coverage}{$suffix}";
        }, $degradation);

        return "## Degradation notice\n\n"
            ."This answer was produced with partial processing or evidence:\n"
            .implode("\n", $items)."\n\n";
    }

    /**
     * @return array<int, string>
     */
    private function doseLint(string $text): array
    {
        preg_match_all('/\b\d+(?:\.\d+)?\s*(?:mg(?:\/kg)?|mcg|µg|g|ml|units?|iu)\b/iu', $text, $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    private function normalize(string $question): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $question) ?? $question), 'UTF-8');
    }
}
