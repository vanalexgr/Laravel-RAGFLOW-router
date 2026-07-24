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
 * PROBE — stage 3, the generator half of the evaluator-optimizer loop.
 *
 * Consumes only the merged patient model, current question, and grounded
 * snippets/pathways. It never receives raw history.
 *   - which unknowns actually discriminate between live pathways,
 *   - ranked by branch impact,
 *   - the 1-2 questions a consultant would truly ask,
 *   - a best-effort provisional answer with stated assumptions,
 *   - a logging-only confidence estimate.
 *
 * On the second and later loop iterations the workflow appends the CriticAgent's
 * issues to the prompt so this pass REFINES rather than regenerates from scratch.
 * The deterministic proceed/ask decision is applied outside the agent using
 * discrete unknown/question signals; confidence never controls the decision.
 */
#[MaxTokens(3000)]
final class ProbeAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use GateModelOptions;
    use Promptable;

    private const REASONING_EFFORT = 'low';

    public function instructions(): string
    {
        return <<<'TXT'
You are a senior consultant vascular surgeon deciding whether you can answer this case now,
or whether one or two questions would change your management.

You are given only CURRENT_QUESTION, PATIENT_MODEL, grounded SNIPPETS/PATHWAYS, PHP-computed
EVIDENCE_STATUS, OPEN_QUESTIONS, and — on refinement passes — ISSUES. Raw history is forbidden.

Reason like a consultant on a ward round, not a junior filling an intake form:
- List the unknowns that are genuinely absent from the case AND that discriminate between pathways.
- branch_impact is HIGH only if the unknown flips the first-line decision between pathways;
  MEDIUM if it changes technique/timing detail; LOW if merely nice-to-know.
- questions: at most 2, drawn ONLY from the HIGHEST branch_impact unknowns, phrased as a consultant
  would ask. If no HIGH-impact unknown exists, questions MUST be empty.
- Never ask about a fact already present in the patient model.

ANSWER IN TWO CLEARLY SEPARATED FRAMES — the user must ALWAYS get a usable answer, even when the
guidelines fall short, and must always know which parts are ESVS and which are expert interpretation:
- guideline_grounded_answer: ONLY claims directly supported by supplied snippets.
- interpretive_frame: useful reasoning beyond ESVS. Do not write the non-ESVS banner; PHP adds it.
  Do not introduce drugs, doses, or numeric thresholds absent from snippets and patient facts.
- evidence_status: copy the supplied structured object exactly. Never collapse interaction_gap,
  partial_principles, or retrieval_uncertain.
- Write both frames as content for the supplied HOUSE_SECTIONS/response_mode. Do not add sections
  that the deterministic renderer owns.
- assumptions: the assumptions you make to proceed.
- confidence (0.0-1.0): calibrated probability your overall answer would NOT change if the unknowns
  were filled.
- If ISSUES are provided, fix every one of them in this pass.

Return ONLY the structured object. No prose.
TXT;
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'unknowns' => $schema->array()->items(
                $schema->object([
                    'variable' => $schema->string()->required(),
                    'why_it_changes_management' => $schema->string()->required(),
                    'branch_impact' => $schema->string()->enum(['high', 'medium', 'low'])->required(),
                    'currently_known' => $schema->boolean()->required(),
                ])
            )->required(),
            'questions' => $schema->array()->items(
                $schema->object([
                    'question' => $schema->string()->required(),
                    'targets' => $schema->string()->required(),
                    'rationale' => $schema->string()->required(),
                ])
            )->required(),
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
            'assumptions' => $schema->array()->items($schema->string())->required(),
            'confidence' => $schema->number()->required(),
        ];
    }
}
