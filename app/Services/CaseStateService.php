<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class CaseStateService
{
    public const TTL_SECONDS = 900;

    public function __construct(
        private readonly PHIScrubberService $phiScrubber,
    ) {}

    public function get(string $chatId): ?array
    {
        $record = Cache::store('redis')->get($this->key($chatId));

        return is_array($record) ? $this->allowedFields($record) : null;
    }

    public function put(string $chatId, array $state): array
    {
        $record = [
            'provisional_diagnosis' => $this->scrub((string) ($state['provisional_diagnosis'] ?? '')),
            'guidelines' => array_values(array_map(
                static fn ($guideline): string => (string) $guideline,
                array_slice((array) ($state['guidelines'] ?? []), 0, 6),
            )),
            'retrieval_query' => $this->scrub((string) ($state['retrieval_query'] ?? '')),
            'ts' => now()->timestamp,
        ];

        Cache::store('redis')->put($this->key($chatId), $record, self::TTL_SECONDS);

        return $record;
    }

    public function forget(string $chatId): void
    {
        Cache::store('redis')->forget($this->key($chatId));
    }

    private function key(string $chatId): string
    {
        return "casestate:{$chatId}";
    }

    private function scrub(string $value): string
    {
        return trim((string) ($this->phiScrubber->scrub($value)['scrubbed_text'] ?? ''));
    }

    private function allowedFields(array $record): array
    {
        return [
            'provisional_diagnosis' => (string) ($record['provisional_diagnosis'] ?? ''),
            'guidelines' => array_values((array) ($record['guidelines'] ?? [])),
            'retrieval_query' => (string) ($record['retrieval_query'] ?? ''),
            'ts' => (int) ($record['ts'] ?? 0),
        ];
    }
}
