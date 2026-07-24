<?php

namespace App\Ai\AnswerAssembly;

use InvalidArgumentException;

final class AnswerSkeletonRenderer
{
    private const MODES = ['management', 'knowledge', 'surveillance', 'diagnostic', 'case'];

    /**
     * @param  array<string, mixed>  $evidenceStatus
     * @param  array<string, mixed>  $fill
     * @param  array<int, array<string, mixed>>  $assets
     */
    public function render(
        string $mode,
        array $evidenceStatus,
        array $fill,
        array $assets = [],
    ): string {
        if (! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException("Unsupported answer response mode: {$mode}");
        }

        $lines = [
            $this->openingHeading($mode),
            '',
            trim((string) ($fill['direct_answer'] ?? '')),
            '',
            '## ESVS-grounded answer',
            '',
            trim((string) ($fill['guideline_grounded_answer'] ?? '')),
        ];

        $gap = $this->gapText($evidenceStatus);
        if ($gap !== null) {
            $lines = [...$lines, '', '## Guideline Gap', '', $gap];
        }

        $lines = [
            ...$lines,
            '',
            '## Interpretation',
            '',
            'Non-ESVS interpretation (clinical reasoning beyond the retrieved guideline text):',
            trim((string) ($fill['interpretive_frame'] ?? '')),
        ];

        $practical = array_values(array_filter(
            (array) ($fill['practical_points'] ?? []),
            static fn (mixed $point): bool => is_string($point) && trim($point) !== '',
        ));
        if ($practical !== []) {
            $lines = [...$lines, '', $this->practicalHeading($mode), ''];
            foreach ($practical as $point) {
                $lines[] = '- '.trim($point);
            }
        }

        $evidence = array_values(array_filter(
            (array) ($fill['evidence_used'] ?? []),
            static fn (mixed $item): bool => is_string($item) && trim($item) !== '',
        ));
        $lines = [...$lines, '', '## Evidence Used', ''];
        if ($evidence === []) {
            $lines[] = '- No applicable retrieved evidence was supplied.';
        } else {
            foreach ($evidence as $item) {
                $lines[] = '- '.trim($item);
            }
        }

        $assetLines = array_values(array_filter(array_map(
            static fn (array $asset): string => trim((string) ($asset['markdown'] ?? '')),
            $assets,
        )));
        if ($assetLines !== []) {
            $lines = [...$lines, '', '## Figures / Tables', '', ...$assetLines];
        }

        return trim(implode("\n", $lines));
    }

    private function openingHeading(string $mode): string
    {
        return match ($mode) {
            'management' => '## Clinical Decision',
            'knowledge' => '## Direct Answer',
            'surveillance' => '## Surveillance Summary',
            'diagnostic' => '## Diagnostic / Imaging Focus',
            default => '## Clinical Synthesis',
        };
    }

    private function practicalHeading(string $mode): string
    {
        return match ($mode) {
            'surveillance' => '## Timing and Escalation Triggers',
            'diagnostic' => '## Practical Takeaway',
            'management' => '## Clinical Decision Summary',
            default => '## Practical Points',
        };
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function gapText(array $status): ?string
    {
        $coverage = (string) ($status['coverage'] ?? 'retrieval_uncertain');
        $core = trim((string) ($status['core_question'] ?? 'the core question'));
        $summary = trim((string) ($status['gap_summary'] ?? ''));

        return match ($coverage) {
            'covered' => null,
            'partial_principles' => "ESVS does not provide a condition-specific protocol for {$core}; "
                .'the supplied general perioperative principles still apply.'
                .($summary === '' ? '' : ' '.$summary),
            'interaction_gap' => "ESVS provides no recommendation on the interaction: {$core}."
                .($summary === '' ? '' : ' '.$summary),
            'not_covered' => "No applicable ESVS recommendation was retrieved for {$core}."
                .($summary === '' ? '' : ' '.$summary),
            default => "Retrieval remains uncertain for {$core}; absence of retrieved evidence is not "
                .'treated as proof that ESVS is silent.'
                .($summary === '' ? '' : ' '.$summary),
        };
    }
}
