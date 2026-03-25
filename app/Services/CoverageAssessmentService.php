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

CONTRAINDICATION RULE: A guideline that EXCLUDES an option due to a contraindication IS direct guidance — it answers the core question by exclusion.
Example: if ESVS states that CDT/thrombectomy is contraindicated in patients with recent major surgery or high bleeding risk, this DIRECTLY answers "what to do in DVT after recent surgery" — the answer is "anticoagulation alone, intervention contraindicated".
Do NOT mark core_question_covered as "none" or "partial" when the guidelines clearly address the scenario by contraindication.
A gap only exists when ESVS provides NO guidance at all on the scenario — not when it provides clear guidance that excludes one option.

CONSERVATIVE DEFAULT RULE: For single-condition, canonical clinical presentations, default to core_question_covered="direct".
Canonical examples: proximal DVT → anticoagulate; symptomatic carotid stenosis → CEA/CAS; AAA above threshold → repair; CLTI → revascularise per WIfI/GLASS criteria.
If retrieved chunks contain clear treatment recommendations for the primary condition, mark core_question_covered="direct" — even if not every sub-choice (specific DOAC agent, exact duration months) is explicitly stated in the chunk.
Reserve "partial" ONLY for: multi-condition interactions not addressed, unusual patient factors genuinely absent from guidelines, or scenarios where chunks touch the topic but provide no actionable direction.
Do NOT mark "partial" simply because you would prefer more specific sub-detail than what is retrieved.

LOW-INDICATION RULE: A guideline that RESTRICTS intervention to selected patients IS direct guidance — not a gap.
Example: "CEA is not routinely recommended in asymptomatic carotid stenosis; consider only in selected patients with high-risk features and operative risk <3%" — this IS direct guidance answering "what to do in asymptomatic carotid disease". The answer is "BMT first, selective CEA".
Do NOT mark "partial" for nuanced, selective-indication recommendations. A guideline saying "do X only when [criteria]" is as direct as one saying "always do X".
Mark core_question_covered="direct" when the retrieved chunks clearly state what to do (or not do) in the presented scenario, even if the answer is "restrict to selected cases".

BROAD COVERAGE RULE: A guideline that addresses the broader category covers specific sub-scenarios within it, even if not enumerated separately. Do NOT declare core_question_covered="none" or "partial" because the guideline doesn't address every sub-detail. Examples: "infrainguinal bypass" covers vein and prosthetic conduits; "lower limb revascularisation" covers both endovascular and surgical. Only declare "none" when the guideline genuinely provides NO usable direction.

NEGATIVE INDICATION RULE: If ESVS defines a positive indication for a treatment (e.g., "anticoagulate when VTE is confirmed", "repair when AAA >55mm") and the patient does NOT meet that indication, this is NOT a gap — it is direct guidance by exclusion. Mark core_question_covered="direct". The answer is "not indicated" and this is a COMPACT case. Do NOT declare "none" or "partial" simply because the patient fails to meet the criteria for an intervention. Examples: venous compression without thrombosis → anticoagulation not indicated (full guidance); AAA 40mm → repair not indicated (full guidance).

FACET QUALITY RULE: Do NOT create facets for patient demographics or characteristics (age, "elderly patient", sex, obesity, functional status, renal function, frailty). These are patient modifiers, not clinical questions. Facets must represent clinical decisions or treatment questions only (e.g., "anticoagulation duration", "CEA vs CAS", "revascularisation vs amputation", "surveillance interval"). A demographic characteristic cannot be "covered" or "not covered" by guidelines — omit it entirely from facet_coverage.
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
