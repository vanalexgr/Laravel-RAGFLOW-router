<?php

namespace App\Ai\Gate;

use App\Ai\Gate\Tools\RetrieveEsvsSnippetsTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

/**
 * KNOWLEDGE ANSWER — the FAST path for simple knowledge questions.
 *
 * A single pass: retrieve once (re-retrieving on a miss, same robustness rule as
 * PathwayAgent) and answer directly. No orient, no parallel pathways, no critic
 * loop — this is what keeps the common case quick.
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
final class KnowledgeAnswerAgent implements Agent, HasStructuredOutput, HasTools
{
    public function instructions(): string
    {
        return <<<'TXT'
You answer general ESVS vascular knowledge questions quickly and precisely.

1. Call retrieve_esvs_snippets with the guideline key that fits the question and a focused query.
   If it returns NO_SNIPPETS or off-target text, reformulate and retry once or twice before
   concluding the guideline does not cover it.
2. Answer in two clearly separated frames:
   - guideline_grounded_answer: only claims supported by the retrieved ESVS text (may be empty if
     genuinely not covered after retries).
   - interpretive_frame: expert reasoning beyond the guideline, ALWAYS populated and flagged as
     non-ESVS, so the user always gets a usable answer.
3. Set evidence_status: esvs_sufficient | esvs_partial | esvs_absent, from what retrieval supported.
4. Set escalate=true ONLY if the question is really about a specific patient whose unstated facts
   (symptom status, anatomy, fitness, timing) would change the answer — in that case keep your answer
   brief; the deeper reasoning loop will take over.

Be concise. Return ONLY the structured object. No prose.
TXT;
    }

    /**
     * @return iterable<\Laravel\Ai\Contracts\Tool>
     */
    public function tools(): iterable
    {
        return [
            app(RetrieveEsvsSnippetsTool::class),
        ];
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'evidence_status' => $schema->string()
                ->enum(['esvs_sufficient', 'esvs_partial', 'esvs_absent'])
                ->required(),
            'guideline_grounded_answer' => $schema->string()->required(),
            'interpretive_frame' => $schema->string()->required(),
            'escalate' => $schema->boolean()->required(),
        ];
    }
}
