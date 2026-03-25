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
        public readonly string $coreQuestion,
        public readonly bool   $questionGap,              // core question itself has no full guidance
        public readonly string $coreQuestionCoveredLevel, // 'direct' | 'partial' | 'none'
    ) {}

    public static function noGap(): self
    {
        return new self(
            hasGuidelineGap:           false,
            coveredFacets:             [],
            partialFacets:             [],
            uncoveredFacets:           [],
            gapSummary:                '',
            supplementaryPermitted:    false,
            coreQuestion:              '',
            questionGap:               false,
            coreQuestionCoveredLevel:  'direct',
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

        $coreQuestion        = (string) ($data['core_question'] ?? '');
        $coreQuestionCovered = (string) ($data['core_question_covered'] ?? 'direct');
        $questionGap         = in_array($coreQuestionCovered, ['none', 'partial'], true);
        $hasGap              = !empty($uncovered) || count($partial) >= 2 || $questionGap;

        return new self(
            hasGuidelineGap:           $hasGap,
            coveredFacets:             $covered,
            partialFacets:             $partial,
            uncoveredFacets:           $uncovered,
            gapSummary:                (string) ($data['gap_summary'] ?? ''),
            supplementaryPermitted:    $hasGap,
            coreQuestion:              $coreQuestion,
            questionGap:               $questionGap,
            coreQuestionCoveredLevel:  in_array($coreQuestionCovered, ['direct', 'partial', 'none'], true)
                                       ? $coreQuestionCovered : 'direct',
        );
    }

    public function toArray(): array
    {
        return [
            'has_guideline_gap'            => $this->hasGuidelineGap,
            'covered_facets'               => $this->coveredFacets,
            'partial_facets'               => $this->partialFacets,
            'uncovered_facets'             => $this->uncoveredFacets,
            'gap_summary'                  => $this->gapSummary,
            'supplementary_permitted'      => $this->supplementaryPermitted,
            'core_question'                => $this->coreQuestion,
            'question_gap'                 => $this->questionGap,
            'core_question_covered'        => $this->coreQuestionCoveredLevel,
        ];
    }
}
