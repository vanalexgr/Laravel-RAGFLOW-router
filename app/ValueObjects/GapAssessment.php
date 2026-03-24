<?php

namespace App\ValueObjects;

class GapAssessment
{
    public function __construct(
        public readonly bool   $hasGuidelineGap,
        public readonly array  $coveredFacets,
        public readonly array  $partialFacets,
        public readonly array  $uncoveredFacets,
        public readonly string $gapSummary,
        public readonly bool   $supplementaryPermitted,
    ) {}

    public static function noGap(): self
    {
        return new self(
            hasGuidelineGap:        false,
            coveredFacets:          [],
            partialFacets:          [],
            uncoveredFacets:        [],
            gapSummary:             '',
            supplementaryPermitted: false,
        );
    }

    public static function fromArray(array $data): self
    {
        $coverage  = is_array($data['facet_coverage'] ?? null) ? $data['facet_coverage'] : [];
        $covered   = [];
        $partial   = [];
        $uncovered = [];

        foreach ($coverage as $item) {
            if (!is_array($item)) {
                continue;
            }
            $facet = (string) ($item['facet'] ?? '');
            match ($item['coverage'] ?? '') {
                'direct'  => $covered[]   = $facet,
                'partial' => $partial[]   = $facet,
                default   => $uncovered[] = $facet,
            };
        }

        $hasGap = !empty($uncovered) || count($partial) >= 2;

        return new self(
            hasGuidelineGap:        $hasGap,
            coveredFacets:          $covered,
            partialFacets:          $partial,
            uncoveredFacets:        $uncovered,
            gapSummary:             (string) ($data['gap_summary'] ?? ''),
            supplementaryPermitted: $hasGap,
        );
    }

    public function toArray(): array
    {
        return [
            'has_guideline_gap'        => $this->hasGuidelineGap,
            'covered_facets'           => $this->coveredFacets,
            'partial_facets'           => $this->partialFacets,
            'uncovered_facets'         => $this->uncoveredFacets,
            'gap_summary'              => $this->gapSummary,
            'supplementary_permitted'  => $this->supplementaryPermitted,
        ];
    }
}
