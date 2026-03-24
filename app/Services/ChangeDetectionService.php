<?php

namespace App\Services;

use App\Contracts\LlmClient;
use App\ValueObjects\ChangeDetectionResult;
use App\ValueObjects\PreRetrievalResult;
use Illuminate\Support\Facades\Log;

class ChangeDetectionService
{
    public function __construct(
        private readonly LlmClient $llm,
    ) {
    }

    public function detect(string $userReply, PreRetrievalResult $original): ChangeDetectionResult
    {
        $userReply = trim($userReply);
        if ($userReply === '') {
            return ChangeDetectionResult::fromArray(['decision' => 'reuse', 'reason' => 'empty reply']);
        }

        $deterministic = $this->deterministicGuidelineShift($userReply, $original);
        if ($deterministic !== null) {
            return $deterministic;
        }

        try {
            $prompt = $this->buildPrompt(
                $userReply,
                $original->retrievalQuery,
                $original->provisionalDiagnosis,
                $original->retrievalQuery,
                $original->clarificationQuestions
            );

            $raw = $this->llm->complete($prompt, maxTokens: 150, temperature: 0);
            $data = $this->parseJson($raw);
            if ($data === null) {
                Log::channel('retrieval')->warning('[CHANGE DETECTION] JSON parse failed, defaulting to reuse', [
                    'reply_preview' => substr($userReply, 0, 120),
                    'raw_preview' => substr($raw, 0, 240),
                ]);

                return ChangeDetectionResult::fromArray([
                    'decision' => 'reuse',
                    'reason' => 'parse failure',
                ]);
            }

            return ChangeDetectionResult::fromArray($data);
        } catch (\Throwable $e) {
            Log::channel('retrieval')->warning('[CHANGE DETECTION] LLM call failed, defaulting to reuse', [
                'reply_preview' => substr($userReply, 0, 120),
                'error' => $e->getMessage(),
            ]);

            return ChangeDetectionResult::fromArray([
                'decision' => 'reuse',
                'reason' => 'llm failure',
            ]);
        }
    }

    protected function buildPrompt(
        string $userReply,
        string $originalQuery,
        string $provisionalDiagnosis,
        string $originalRetrievalQuery,
        array $clarificationQuestions = []
    ): string {
        $availableGuidelines = $this->availableGuidelinesForPrompt();
        $clarificationText = '';
        $clarificationQuestions = array_values(array_filter(array_map(
            fn($question) => is_string($question) ? trim($question) : '',
            $clarificationQuestions
        )));

        if (!empty($clarificationQuestions)) {
            $clarificationText = "Original clarification questions:\n  - "
                . implode("\n  - ", $clarificationQuestions) . "\n\n";
        }

        return <<<PROMPT
A clinical guideline retrieval was run for this provisional diagnosis:
  {$provisionalDiagnosis}

Original question:
  {$originalQuery}

Original retrieval query used:
  {$originalRetrievalQuery}

Available guideline keys for updated_guidelines:
{$availableGuidelines}

{$clarificationText}The reply may be answering one or more of the missing details above.

The user replied with:
  {$userReply}

Decide whether to reuse the existing retrieval result or run a new retrieval.
Return JSON with exactly 4 fields. No preamble, no explanation, only JSON.

    decision: 'reuse' if the user reply is a simple confirmation, primarily answers
      the original clarification questions, adds timing/severity/stability/
      measurement details within the same diagnosis and anatomical territory, adds
      only fitness/procedural details, or contains no new clinical information.
      Prefer 'reuse' when the original provisional diagnosis and guideline set
      remain appropriate and the existing retrieval can still support answer
      synthesis using the clarified details.
      'requery' ONLY if the reply introduces: a different anatomical territory,
      a different acuity (acute vs chronic), a different symptomatic status that
      changes guideline selection, a new diagnosis, or additional guidelines
      clearly needed.

reason: one short phrase explaining the decision (for logging only).

enriched_query: if decision is 'requery', write a new expanded English retrieval
  query incorporating both the original question and the new information.
  If decision is 'reuse', return null.

updated_guidelines: if decision is 'requery' and the clarification changes which
  guideline set should be searched, return an array of 1-3 guideline keys from
  the available list above. Prefer anatomy/pathology-based selection, not
  keywords alone. If the original guideline set remains appropriate, return null.
PROMPT;
    }

    protected function availableGuidelinesForPrompt(): string
    {
        $lines = [];
        foreach (config('guidelines.categories', []) as $category) {
            foreach (($category['guidelines'] ?? []) as $key => $info) {
                $lines[] = "- {$key}: " . ($info['name'] ?? $key);
            }
        }

        return implode("\n", $lines);
    }

    protected function deterministicGuidelineShift(string $userReply, PreRetrievalResult $original): ?ChangeDetectionResult
    {
        // This method only serves one purpose: upgrade asymptomatic_pad → clti when
        // CLTI signals appear in the reply. If clti is already in the guideline set
        // (and asymptomatic_pad is not), there is nothing to upgrade — fall through
        // to the LLM prompt which handles confirmatory clarifications correctly.
        $hasCltiAlready    = in_array('clti', $original->guidelines, true);
        $hasPadToUpgrade   = in_array('asymptomatic_pad', $original->guidelines, true);

        if ($hasCltiAlready && !$hasPadToUpgrade) {
            return null;
        }

        $combined = implode(' ', array_filter([
            $userReply,
            $original->provisionalDiagnosis,
            $original->retrievalQuery,
        ]));

        $hasPadContext = $hasPadToUpgrade
            || $hasCltiAlready
            || (bool) preg_match('/\b(peripheral arterial disease|pad|lower limb revasculari[sz]ation|bypass|endovascular)\b/i', $combined);

        $hasCltiSignals = (bool) preg_match(
            '/\b(clti|chronic limb threatening ischa?emia|rest pain|tissue loss|gangrene|ulcer|wifi|limb threatening)\b/i',
            $combined
        );

        if (!$hasPadContext || !$hasCltiSignals) {
            return null;
        }

        $updatedGuidelines = array_values(array_filter($original->guidelines, fn(string $guideline): bool => $guideline !== 'asymptomatic_pad'));
        if (!in_array('clti', $updatedGuidelines, true)) {
            $updatedGuidelines[] = 'clti';
        }

        if (
            (bool) preg_match('/\b(antithrombotic|antiplatelet|anticoag|aspirin|clopidogrel|rivaroxaban|doac|warfarin)\b/i', $combined)
            && !in_array('antithrombotic_therapy', $updatedGuidelines, true)
        ) {
            array_unshift($updatedGuidelines, 'antithrombotic_therapy');
        }

        $updatedGuidelines = array_slice(array_values(array_unique($updatedGuidelines)), 0, 3);

        $enrichedQuery = $this->appendUniqueTerms($original->retrievalQuery, [
            preg_match('/\bdistal\s+bypass\b/i', $userReply) ? 'distal bypass' : null,
            preg_match('/\bvein\b/i', $userReply) ? 'vein bypass' : null,
            'chronic limb threatening ischemia',
            preg_match('/\brest pain\b/i', $combined) ? 'rest pain' : null,
            preg_match('/\btissue loss\b/i', $combined) ? 'tissue loss' : null,
        ]);

        return ChangeDetectionResult::fromArray([
            'decision' => 'requery',
            'reason' => 'clti clarification changes guideline selection',
            'enriched_query' => $enrichedQuery,
            'updated_guidelines' => $updatedGuidelines,
        ]);
    }

    protected function appendUniqueTerms(string $query, array $terms): string
    {
        $base = trim($query);
        $normalized = strtolower($base);

        foreach ($terms as $term) {
            if (!is_string($term) || trim($term) === '') {
                continue;
            }

            $term = trim($term);
            if ($term === '') {
                continue;
            }

            if (!str_contains($normalized, strtolower($term))) {
                $base = trim($base . ' ' . $term);
                $normalized = strtolower($base);
            }
        }

        return $base;
    }

    protected function parseJson(string $raw): ?array
    {
        $clean = trim((string) preg_replace('/```(?:json)?|```/i', '', $raw));
        if ($clean === '') {
            return null;
        }

        if (preg_match('/\{[\s\S]*\}/u', $clean, $matches)) {
            $clean = $matches[0];
        }

        $decoded = json_decode($clean, true);

        return is_array($decoded) ? $decoded : null;
    }
}
