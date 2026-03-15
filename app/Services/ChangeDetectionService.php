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

{$clarificationText}The reply may be answering one or more of the missing details above.

The user replied with:
  {$userReply}

Decide whether to reuse the existing retrieval result or run a new retrieval.
Return JSON with exactly 3 fields. No preamble, no explanation, only JSON.

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
PROMPT;
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
