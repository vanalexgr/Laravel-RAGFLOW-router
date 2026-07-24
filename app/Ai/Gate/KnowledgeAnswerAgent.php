<?php

namespace App\Ai\Gate;

use App\Ai\Gate\Concerns\GateModelOptions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * KNOWLEDGE ANSWER — the FAST path for simple knowledge questions.
 *
 * PHP performs retrieval/re-retrieval first. This agent receives the merged
 * patient-model digest and the accepted snippets and performs one fill call.
 *
 * It reuses the same two-frame output contract as the deep path so answers look
 * consistent: guideline-grounded content stays separate from, and the non-ESVS
 * interpretive frame is always populated and flagged.
 *
 * Escape hatch: if, while answering, it realises the question is actually a
 * specific-patient consultation whose answer depends on unstated facts, it sets
 * escalate=true. The workflow then hands off to the full loop AND tells the user
 * it is looking deeper (so the extra latency is explained).
 */
#[MaxTokens(2400)]
final class KnowledgeAnswerAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use GateModelOptions;
    use Promptable;

    private const REASONING_EFFORT = 'low';

    public function instructions(): string
    {
        return <<<'TXT'
You answer general ESVS vascular knowledge questions quickly and precisely.

The input contains CURRENT_QUESTION, PATIENT_MODEL_DIGEST, accepted SNIPPETS, and the PHP-computed
EVIDENCE_STATUS. Do not retrieve and do not use model memory as ESVS evidence.
1. Answer in two clearly separated frames:
   - guideline_grounded_answer: only claims supported by the retrieved ESVS text (may be empty if
     genuinely not covered after PHP retries).
   - interpretive_frame: useful interpretation beyond ESVS, without its banner (PHP adds the fixed
     banner). It may not introduce drugs, doses, or numeric thresholds absent from input.
2. Copy the structured evidence_status exactly; do not collapse interaction_gap or
   retrieval_uncertain.
3. Set escalate=true ONLY if the question is really about a specific patient whose unstated facts
   (symptom status, anatomy, fitness, timing) would change the answer — in that case keep your answer
   brief; the deeper reasoning loop will take over.

Be concise. Return ONLY the structured object. No prose.
TXT;
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'evidence_status' => $schema->object([
                'coverage' => $schema->string()->enum([
                    'covered',
                    'partial_principles',
                    'interaction_gap',
                    'not_covered',
                    'retrieval_uncertain',
                ])->required(),
                'core_question' => $schema->string()->required(),
                'covered_components' => $schema->array()->items($schema->string())->required(),
                'gap_summary' => $schema->string()->required(),
            ])->required(),
            'guideline_grounded_answer' => $schema->string()->required(),
            'interpretive_frame' => $schema->string()->required(),
            'escalate' => $schema->boolean()->required(),
        ];
    }
}
