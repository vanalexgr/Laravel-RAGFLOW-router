<?php

namespace App\ValueObjects;

class ChangeDetectionResult
{
    public function __construct(
        public readonly string $decision,
        public readonly string $reason,
        public readonly ?string $enrichedQuery,
        public readonly array $updatedGuidelines = [],
    ) {
    }

    public static function fromArray(array $data): self
    {
        $decision = (string) ($data['decision'] ?? 'reuse');
        if (!in_array($decision, ['reuse', 'requery'], true)) {
            $decision = 'reuse';
        }

        $enrichedQuery = $data['enriched_query'] ?? null;
        if (!is_string($enrichedQuery) || trim($enrichedQuery) === '') {
            $enrichedQuery = null;
        }

        if ($decision === 'reuse') {
            $enrichedQuery = null;
        }

        $updatedGuidelines = $data['updated_guidelines'] ?? [];
        if (!is_array($updatedGuidelines)) {
            $updatedGuidelines = [];
        }
        $updatedGuidelines = array_values(array_unique(array_filter(array_map(
            fn($guideline) => is_string($guideline) ? trim($guideline) : '',
            $updatedGuidelines
        ), fn(string $guideline): bool => $guideline !== '')));

        if ($decision === 'reuse') {
            $updatedGuidelines = [];
        }

        return new self(
            decision: $decision,
            reason: trim((string) ($data['reason'] ?? '')),
            enrichedQuery: $enrichedQuery,
            updatedGuidelines: $updatedGuidelines,
        );
    }

    public function toArray(): array
    {
        return [
            'decision' => $this->decision,
            'reason' => $this->reason,
            'enriched_query' => $this->enrichedQuery,
            'updated_guidelines' => $this->updatedGuidelines,
        ];
    }
}
