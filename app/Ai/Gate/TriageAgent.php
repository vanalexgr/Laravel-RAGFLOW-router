<?php

namespace App\Ai\Gate;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * TRIAGE — the front door. One cheap, fast classification call that decides how
 * much machinery a question deserves (the blog's "routing" pattern).
 *
 * Most questions are simple knowledge lookups — definitions, thresholds,
 * population-level guideline facts — and must be answered FAST on the lean path
 * (single retrieve + answer, no orient, no parallel fan-out, no critic loop).
 * Only genuine patient-case consultations, where missing facts change
 * management, earn the full agentic loop.
 *
 * Marked #[UseCheapestModel]: triage must add negligible latency to the common
 * (knowledge) case. On ISI this is a small local model.
 *
 * This is NOT a keyword whitelist — it is a general judgement about whether the
 * question is about a specific patient whose unknowns could change the answer.
 */
#[UseCheapestModel]
final class TriageAgent implements Agent, HasStructuredOutput
{
    public function instructions(): string
    {
        return <<<'TXT'
You are triaging a vascular question to decide how much reasoning it needs. Classify into:

- "knowledge": a general/definitional/threshold/population-level question with a stable answer that
  does NOT depend on unstated details of a specific patient. Examples: "What diameter triggers AAA
  repair?", "Define a juxtarenal aneurysm", "What is the ESVS recommendation for asymptomatic
  carotid stenosis?". These go on a FAST single-pass path.

- "case": a consultation about a SPECIFIC patient where missing clinical facts (symptom status,
  anatomy, fitness, timing, measurements) could change the recommendation. These earn the full
  reasoning loop.

Decide by ONE test: would the correct answer change depending on unstated facts about a specific
patient? If yes -> "case". If the answer is a stable guideline fact -> "knowledge". When genuinely
unsure, prefer "case" (better to reason than to answer a patient question too shallowly).

Return ONLY the structured object. No prose.
TXT;
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'mode' => $schema->string()->enum(['knowledge', 'case'])->required(),
            'reason' => $schema->string()->required(),
        ];
    }
}
