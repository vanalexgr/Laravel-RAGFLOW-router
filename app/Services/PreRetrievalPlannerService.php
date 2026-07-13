<?php

namespace App\Services;

use App\Contracts\LlmClient;
use App\ValueObjects\RetrievalPlan;
use Illuminate\Support\Facades\Log;

final class PreRetrievalPlannerService
{
    public function __construct(private readonly LlmClient $llm)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('ragflow.planner.merged_enabled', false);
    }

    /** Returns null on any failure so callers can run the legacy pipeline unchanged. */
    public function plan(string $question, array $history = [], ?array $requestedKeys = null): ?RetrievalPlan
    {
        try {
            $raw = $this->llm->complete(
                $this->buildPrompt($question, $history, $requestedKeys),
                (int) config('ragflow.planner.max_tokens', 1000),
                (float) config('ragflow.planner.temperature', 0.0),
            );
            $data = $this->parseJson($raw);
            if ($data === null) {
                Log::channel('retrieval')->warning('[PLANNER] JSON parse failed; falling back to legacy chain', ['raw_preview' => substr($raw, 0, 240)]);
                return null;
            }

            $plan = $this->validateAndRepair(RetrievalPlan::fromArray($data), $requestedKeys);
            Log::channel('retrieval')->info('[PLANNER] Merged pre-retrieval plan produced', [
                'guidelines' => $plan->guidelines,
                'query_type' => $plan->queryType,
                'language' => $plan->language,
            ]);
            return $plan;
        } catch (\Throwable $e) {
            Log::channel('retrieval')->warning('[PLANNER] LLM call failed; falling back to legacy chain', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function validateAndRepair(RetrievalPlan $plan, ?array $requestedKeys): RetrievalPlan
    {
        $valid = array_keys($this->guidelineRegistry());
        $guidelines = $requestedKeys ?: array_values(array_intersect($plan->guidelines, $valid));
        $guidelines = array_slice(array_values(array_unique(array_filter($guidelines, 'is_string'))), 0, 3);
        $scores = array_intersect_key($plan->guidelineScores, array_flip($guidelines));

        return RetrievalPlan::fromArray(array_merge($this->toContractArray($plan), [
            'guidelines' => $guidelines,
            'guideline_scores' => $scores,
        ]), $plan->usedFallback);
    }

    private function guidelineRegistry(): array
    {
        $registry = [];
        foreach (config('guidelines.categories', []) as $category) {
            foreach (($category['guidelines'] ?? []) as $key => $info) {
                $registry[$key] = $info['name'] ?? $key;
            }
        }
        return $registry;
    }

    private function buildPrompt(string $question, array $history, ?array $requestedKeys): string
    {
        $registry = $this->guidelineRegistry();
        $registryText = implode("\n", array_map(
            static fn (string $key, string $name) => "  - {$key}: {$name}",
            array_keys($registry),
            $registry,
        ));
        $historyText = empty($history) ? '(none)' : implode("\n", array_map(
            static fn ($entry) => '  - ' . (is_string($entry) ? $entry : json_encode($entry)),
            array_slice($history, -6),
        ));
        $constraint = $requestedKeys
            ? 'The caller pinned these guideline keys; use exactly: ' . implode(', ', $requestedKeys)
            : 'No guidelines were pinned; select the best matching keys.';

        return PlannerPrompt::INSTRUCTIONS . "\n\n=== VALID GUIDELINE KEYS ===\n{$registryText}\n\n=== CONVERSATION HISTORY (most recent last) ===\n{$historyText}\n\n=== ROUTING CONSTRAINT ===\n{$constraint}\n\n=== USER QUESTION ===\n{$question}\n\nReturn ONLY the JSON object.";
    }

    private function toContractArray(RetrievalPlan $plan): array
    {
        return [
            'language' => $plan->language, 'normalized_query' => $plan->normalizedQuery,
            'normalized_changed' => $plan->normalizedChanged, 'query_type' => $plan->queryType,
            'intent' => $plan->intent, 'guidelines' => $plan->guidelines,
            'guideline_scores' => $plan->guidelineScores, 'expansion_terms' => $plan->expansionTerms,
            'clinical_frame' => $plan->clinicalFrame, 'interpretation_terms' => $plan->interpretationTerms,
            'must_include_terms' => $plan->mustIncludeTerms,
            'graph' => ['core_concepts' => $plan->graphCoreConcepts, 'related_concepts' => $plan->graphRelatedConcepts, 'slots' => $plan->graphSlots],
        ];
    }

    private function parseJson(string $raw): ?array
    {
        $clean = trim((string) preg_replace('/```(?:json)?|```/i', '', $raw));
        if ($clean === '') return null;
        if (preg_match('/\{[\s\S]*\}/u', $clean, $match)) $clean = $match[0];
        $decoded = json_decode($clean, true);
        return is_array($decoded) ? $decoded : null;
    }
}
