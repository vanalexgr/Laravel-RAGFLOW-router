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
    public function finalize(array $candidate, array $openQuestions = []): array
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

        return array_merge($candidate, [
            'decision' => $decision,
            'questions' => $decision === 'ask' ? $questions : [],
            'interpretive_frame' => $renderedInterpretive,
            'lint_violations' => $lint,
            'answer_markdown' => "## ESVS-grounded answer\n\n"
                .($grounded !== '' ? $grounded : '_No grounded ESVS statement was located._')
                ."\n\n## Interpretation\n\n".$renderedInterpretive,
        ]);
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
