<?php

namespace App\Ai\Gate;

final class EvidenceStatusService
{
    /**
     * @param  array<int, array<string, mixed>>  $pathways
     * @return array{coverage: string, core_question: string, covered_components: array<int, string>, gap_summary: string}
     */
    public function assess(string $coreQuestion, array $pathways): array
    {
        $coverages = array_values(array_filter(array_map(
            static fn (array $pathway): ?string => isset($pathway['coverage'])
                ? (string) $pathway['coverage']
                : null,
            $pathways,
        )));
        $components = array_values(array_unique(array_merge(...array_map(
            static fn (array $pathway): array => array_values(array_filter(
                array_map('strval', (array) ($pathway['covered_components'] ?? []))
            )),
            $pathways,
        ))));
        $interactionGap = in_array(true, array_map(
            static fn (array $pathway): bool => ($pathway['interaction_gap'] ?? false) === true,
            $pathways,
        ), true);

        $coverage = match (true) {
            $interactionGap && $components !== [] => 'interaction_gap',
            $coverages !== [] && count(array_filter($coverages, static fn (string $value): bool => $value === 'covered')) === count($coverages) => 'covered',
            in_array('covered', $coverages, true) || in_array('partial', $coverages, true) => 'partial_principles',
            $coverages !== [] && count(array_filter($coverages, static fn (string $value): bool => $value === 'not_covered')) === count($coverages) => 'not_covered',
            default => 'retrieval_uncertain',
        };

        $gapSummary = match ($coverage) {
            'covered' => '',
            'partial_principles' => 'General ESVS principles were located, but they do not fully resolve the core question.',
            'interaction_gap' => 'ESVS components were located separately, but no recommendation on their interaction was located.',
            'not_covered' => 'Repeated retrieval found credible evidence that ESVS does not cover the core question.',
            default => 'The retrieval attempts could not establish whether ESVS covers the core question.',
        };

        return [
            'coverage' => $coverage,
            'core_question' => $coreQuestion,
            'covered_components' => $components,
            'gap_summary' => $gapSummary,
        ];
    }
}
