<?php

namespace App\ValueObjects;

class ChangeDetectionResult
{
    public function __construct(
        public readonly string $decision,
        public readonly string $reason,
        public readonly ?string $enrichedQuery,
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

        return new self(
            decision: $decision,
            reason: trim((string) ($data['reason'] ?? '')),
            enrichedQuery: $enrichedQuery,
        );
    }

    public function toArray(): array
    {
        return [
            'decision' => $this->decision,
            'reason' => $this->reason,
            'enriched_query' => $this->enrichedQuery,
        ];
    }
}
