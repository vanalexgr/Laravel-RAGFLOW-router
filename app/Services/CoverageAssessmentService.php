<?php

namespace App\Services;

use App\Contracts\LlmClient;
use App\ValueObjects\GapAssessment;
use Illuminate\Support\Facades\Log;

class CoverageAssessmentService
{
    // Maximum chars of chunk text sent to LLM — keeps tokens manageable
    private const CHUNK_PREVIEW_CHARS = 180;
    private const MAX_CHUNKS_FOR_ASSESSMENT = 12;

    public function __construct(private readonly LlmClient $llm) {}

    /**
     * Assess whether retrieved chunks cover the clinical facets of the query.
     *
     * Skipped (returns noGap) when:
     * - query_type is knowledge_only
     * - no chunks provided
     * - fewer than 2 facets to evaluate
     */
    public function assess(
        string $question,
        array  $llmChunks,
        array  $facets,
        string $queryType = 'complex_case',
    ): GapAssessment {
        if ($queryType === 'knowledge_only' || empty($llmChunks) || count($facets) < 2) {
            return GapAssessment::noGap();
        }

        try {
            $prompt = $this->buildPrompt($question, $llmChunks, $facets);
            $raw    = $this->llm->complete($prompt, maxTokens: 700, temperature: 0);
            $data   = $this->parseJson($raw);

            if ($data === null) {
                Log::channel('retrieval')->warning('[COVERAGE ASSESSMENT] JSON parse failed — returning no-gap', [
                    'question_preview' => substr($question, 0, 120),
                    'raw_preview'      => substr($raw, 0, 200),
                ]);
                return GapAssessment::noGap();
            }

            return GapAssessment::fromArray($data);
        } catch (\Throwable $e) {
            Log::channel('retrieval')->warning('[COVERAGE ASSESSMENT] LLM call failed — returning no-gap', [
                'question_preview' => substr($question, 0, 120),
                'error'            => $e->getMessage(),
            ]);
            return GapAssessment::noGap();
        }
    }

    private function buildPrompt(string $question, array $chunks, array $facets): string
    {
        $facetList   = implode("\n", array_map(fn($f) => "- {$f}", array_slice($facets, 0, 8)));
        $chunksSummary = $this->summariseChunks($chunks);

        return <<<PROMPT
You are evaluating ESVS vascular guideline coverage for a clinical query.

Query: {$question}

Clinical facets to evaluate:
{$facetList}

Retrieved guideline chunks:
{$chunksSummary}

STEP 1 — Identify the core clinical question:
What is the user ACTUALLY asking? Express this as a short phrase (e.g., "treatment sequence: AAA vs CLTI",
"endovascular vs open repair", "anticoagulation duration", "management of ICA aneurysm").
This may differ from the individual conditions listed as facets.

COMORBIDITY RULE: Distinguish between a comorbidity that CHANGES THE SURGICAL INDICATION vs one that only AFFECTS PERIOPERATIVE MANAGEMENT.
- If the comorbidity (e.g., APS, AF, diabetes, renal failure) does NOT change whether to bypass vs amputate, open vs endovascular, or when to intervene — the primary vascular decision is NOT the gap.
- In that case, frame the core question around the actual gap: "perioperative management of [comorbidity] during [intervention]" — NOT "[intervention] in a patient with [comorbidity]".
- Example: for CLTI + APS, the bypass vs amputation decision is governed by CLTI criteria (WIfI, GLASS, conduit availability) — APS does not change this. Core question = "perioperative anticoagulation in APS during infrainguinal bypass".

STEP 2 — Assess core question coverage:
Does any retrieved chunk provide direct guidance on the CORE QUESTION itself (not just the underlying conditions)?
Example: if the question is about sequencing AAA and CLTI, check whether any chunk addresses the sequence —
NOT whether AAA and CLTI are individually covered in their own guidelines.

STEP 3 — Assess individual facet coverage.

Return ONLY valid JSON. No preamble, no explanation, only JSON.

{
  "core_question": "short phrase describing what the user is actually asking",
  "core_question_covered": "direct|partial|none",
  "facet_coverage": [
    {"facet": "facet name", "coverage": "direct|partial|none", "evidence": "brief quote or null"}
  ],
  "gap_summary": "One sentence describing what the guidelines do NOT address, or null if fully covered."
}

coverage rules:
- "direct"  = chunk contains a specific recommendation or explicit guidance on this facet/question
- "partial" = chunk touches the topic but does not provide actionable guidance
- "none"    = no chunk addresses this facet/question at all

IMPORTANT: Individual conditions being covered does NOT mean their interaction or sequencing is covered.

MULTI-CONDITION RULE: When the query involves 3 or more conditions (e.g., AAA + CLTI + sepsis, or AAA + CLTI + anticoagulation),
the core question is almost certainly about interaction, priority, or sequencing — NOT about the individual conditions.
Be highly skeptical of marking core_question_covered as "direct" in these cases.
A chunk that covers AAA repair in isolation does NOT cover "how to sequence AAA repair vs CLTI vs active sepsis".
PROMPT;
    }

    private function summariseChunks(array $chunks): string
    {
        $lines = [];
        foreach (array_slice($chunks, 0, self::MAX_CHUNKS_FOR_ASSESSMENT) as $i => $chunk) {
            $text   = (string) ($chunk['text'] ?? $chunk['content'] ?? '');
            $source = (string) ($chunk['source_guideline'] ?? $chunk['guideline'] ?? '');
            $preview = substr(trim($text), 0, self::CHUNK_PREVIEW_CHARS);
            $label  = $source ? "[{$source}]" : "[chunk " . ($i + 1) . "]";
            $lines[] = "{$label} {$preview}";
        }

        return implode("\n", $lines);
    }

    private function parseJson(string $raw): ?array
    {
        $clean = preg_replace('/```json|```/', '', $raw);
        $data  = json_decode(trim($clean), true);

        if (is_array($data)) {
            return $data;
        }

        // Partial recovery: extract key fields from truncated JSON.
        // This handles cases where maxTokens cuts the response mid-array
        // but the critical core_question_covered field was already written.
        $recovered = [];
        if (preg_match('/"core_question"\s*:\s*"([^"]+)"/u', $raw, $m)) {
            $recovered['core_question'] = $m[1];
        }
        if (preg_match('/"core_question_covered"\s*:\s*"(direct|partial|none)"/u', $raw, $m)) {
            $recovered['core_question_covered'] = $m[1];
        }

        if (!empty($recovered)) {
            $recovered['facet_coverage'] = [];
            if (!isset($recovered['gap_summary']) && isset($recovered['core_question'])) {
                $recovered['gap_summary'] = 'ESVS provides no direct guidance on: ' . $recovered['core_question'];
            }
            Log::channel('retrieval')->info('[COVERAGE ASSESSMENT] Partial JSON recovery — core fields extracted', [
                'core_question'         => $recovered['core_question'] ?? null,
                'core_question_covered' => $recovered['core_question_covered'] ?? null,
            ]);
            return $recovered;
        }

        return null;
    }
}
