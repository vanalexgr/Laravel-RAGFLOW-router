<?php

namespace App\ValueObjects;

final class RetrievalPlan
{
    public function __construct(
        public readonly string $language,
        public readonly string $normalizedQuery,
        public readonly bool $normalizedChanged,
        public readonly string $queryType,
        public readonly string $intent,
        public readonly array $guidelines,
        public readonly array $guidelineScores,
        public readonly array $expansionTerms,
        public readonly string $clinicalFrame,
        public readonly array $interpretationTerms,
        public readonly array $mustIncludeTerms,
        public readonly array $graphCoreConcepts,
        public readonly array $graphRelatedConcepts,
        public readonly array $graphSlots,
        public readonly bool $usedFallback,
    ) {
    }

    public static function fromArray(array $data, bool $usedFallback = false): self
    {
        $graph = is_array($data['graph'] ?? null) ? $data['graph'] : [];
        $slots = is_array($graph['slots'] ?? null) ? $graph['slots'] : [];
        $slotKeys = ['anatomy', 'pathology', 'stage', 'intervention', 'imaging', 'complications'];
        $normalizedSlots = [];
        foreach ($slotKeys as $key) {
            $normalizedSlots[$key] = self::strings($slots[$key] ?? []);
        }

        return new self(
            language: (string) ($data['language'] ?? 'en'),
            normalizedQuery: trim((string) ($data['normalized_query'] ?? '')),
            normalizedChanged: (bool) ($data['normalized_changed'] ?? false),
            queryType: in_array($data['query_type'] ?? '', ['knowledge', 'single_case'], true) ? $data['query_type'] : 'knowledge',
            intent: in_array($data['intent'] ?? '', ['definition', 'recommendation', 'management', 'other'], true) ? $data['intent'] : 'other',
            guidelines: self::strings($data['guidelines'] ?? []),
            guidelineScores: self::scores($data['guideline_scores'] ?? []),
            expansionTerms: self::strings($data['expansion_terms'] ?? []),
            clinicalFrame: trim((string) ($data['clinical_frame'] ?? '')),
            interpretationTerms: self::strings($data['interpretation_terms'] ?? []),
            mustIncludeTerms: self::strings($data['must_include_terms'] ?? []),
            graphCoreConcepts: self::strings($graph['core_concepts'] ?? []),
            graphRelatedConcepts: self::strings($graph['related_concepts'] ?? []),
            graphSlots: $normalizedSlots,
            usedFallback: $usedFallback,
        );
    }

    private static function strings(mixed $values): array
    {
        if (!is_array($values)) return [];
        return array_values(array_filter($values, static fn ($value) => is_string($value) && trim($value) !== ''));
    }

    private static function scores(mixed $scores): array
    {
        if (!is_array($scores)) return [];
        $normalized = [];
        foreach ($scores as $key => $score) {
            if (is_string($key) && is_numeric($score)) $normalized[$key] = (float) $score;
        }
        return $normalized;
    }
}
