<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class PendingCaseStateService
{
    public const TTL_SECONDS = 300;

    public function __construct(
        private readonly PHIScrubberService $phiScrubber,
    ) {}

    public function get(string $chatId): ?array
    {
        $record = Cache::store('redis')->get($this->key($chatId));

        return is_array($record) ? $this->allowedFields($record) : null;
    }

    public function put(string $chatId, array $preResult): array
    {
        $record = [
            'proceed' => (bool) ($preResult['proceed'] ?? true),
            'soft_warn' => (bool) ($preResult['soft_warn'] ?? false),
            'clarification_questions' => array_values(array_map(
                fn ($question): string => $this->scrub((string) $question),
                array_slice((array) ($preResult['clarification_questions'] ?? []), 0, 6),
            )),
            'provisional_diagnosis' => $this->scrub((string) ($preResult['provisional_diagnosis'] ?? '')),
            'guidelines' => array_values(array_map(
                static fn ($guideline): string => (string) $guideline,
                array_slice($this->normalizedGuidelines((array) ($preResult['guidelines'] ?? [])), 0, 6),
            )),
            'retrieval_query' => $this->scrub((string) ($preResult['retrieval_query'] ?? '')),
            'scope' => $this->normalizedScope((string) ($preResult['scope'] ?? 'single_guideline')),
            'confirmation_message' => $this->scrub((string) ($preResult['confirmation_message'] ?? '')),
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
        return "pending:{$chatId}";
    }

    private function scrub(string $value): string
    {
        return trim((string) ($this->phiScrubber->scrub($value)['scrubbed_text'] ?? ''));
    }

    private function normalizedGuidelines(array $guidelines): array
    {
        $allowed = [];
        foreach ((array) config('guidelines.categories', []) as $category) {
            $allowed = array_merge($allowed, array_keys((array) ($category['guidelines'] ?? [])));
        }

        return array_values(array_filter(
            $guidelines,
            static fn ($guideline): bool => is_string($guideline) && in_array($guideline, $allowed, true),
        ));
    }

    private function normalizedScope(string $scope): string
    {
        return in_array($scope, ['knowledge_question', 'single_guideline', 'multi_guideline'], true)
            ? $scope
            : 'single_guideline';
    }

    private function allowedFields(array $record): array
    {
        return [
            'proceed' => (bool) ($record['proceed'] ?? true),
            'soft_warn' => (bool) ($record['soft_warn'] ?? false),
            'clarification_questions' => array_values((array) ($record['clarification_questions'] ?? [])),
            'provisional_diagnosis' => (string) ($record['provisional_diagnosis'] ?? ''),
            'guidelines' => array_values((array) ($record['guidelines'] ?? [])),
            'retrieval_query' => (string) ($record['retrieval_query'] ?? ''),
            'scope' => (string) ($record['scope'] ?? 'single_guideline'),
            'confirmation_message' => (string) ($record['confirmation_message'] ?? ''),
            'ts' => (int) ($record['ts'] ?? 0),
        ];
    }
}
