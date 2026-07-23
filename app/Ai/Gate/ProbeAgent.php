<?php

namespace App\Ai\Gate;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * PROBE — stage 3, the generator half of the evaluator-optimizer loop.
 *
 * Consumes the patient model (OrientAgent) and the grounded pathway sets
 * (parallel PathwayAgents) and does the value-of-information reasoning:
 *   - which unknowns actually discriminate between live pathways,
 *   - ranked by branch impact,
 *   - the 1-2 questions a consultant would truly ask,
 *   - a best-effort provisional answer with stated assumptions,
 *   - a calibrated confidence that the answer would not change once filled.
 *
 * On the second and later loop iterations the workflow appends the CriticAgent's
 * issues to the prompt so this pass REFINES rather than regenerates from scratch.
 * The deterministic proceed/ask decision is applied outside the agent (so the
 * 0.70 threshold stays testable and auditable), matching the original prototype.
 */
final class ProbeAgent implements Agent, HasStructuredOutput
{
    public function instructions(): string
    {
        return <<<'TXT'
You are a senior consultant vascular surgeon deciding whether you can answer this case now,
or whether one or two questions would change your management.

You are given: the patient model, the grounded decision pathways (with their guideline basis and
discriminating variables), and — on refinement passes — a list of ISSUES from a reviewer to fix.

Reason like a consultant on a ward round, not a junior filling an intake form:
- List the unknowns that are genuinely absent from the case AND that discriminate between pathways.
- branch_impact is HIGH only if the unknown flips the first-line decision between pathways;
  MEDIUM if it changes technique/timing detail; LOW if merely nice-to-know.
- questions: at most 2, drawn ONLY from the HIGHEST branch_impact unknowns, phrased as a consultant
  would ask. If no HIGH-impact unknown exists, questions MUST be empty.
- Never ask about a fact already present in the patient model.

ANSWER IN TWO CLEARLY SEPARATED FRAMES — the user must ALWAYS get a usable answer, even when the
guidelines fall short, and must always know which parts are ESVS and which are expert interpretation:
- guideline_grounded_answer: ONLY claims supported by the retrieved ESVS pathways/guideline_basis.
  If the pathways report coverage "not_covered" for everything, this may be empty.
- interpretive_frame: your best expert reasoning that goes BEYOND the guideline text — physiology,
  extrapolation, analogous recommendations, standard practice. This is the non-ESVS frame and must
  read as such. It must ALWAYS be populated with a usable answer, especially when evidence is thin,
  so the user is never left without guidance.
- evidence_status: "esvs_sufficient" (guidelines answer the question), "esvs_partial" (guidelines
  cover some of it), or "esvs_absent" (guidelines do not address it — answer lives in the
  interpretive frame). Base this on the pathways' coverage verdicts, NOT on a single failed query.
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
            'evidence_status' => $schema->string()
                ->enum(['esvs_sufficient', 'esvs_partial', 'esvs_absent'])
                ->required(),
            'guideline_grounded_answer' => $schema->string()->required(),
            'interpretive_frame' => $schema->string()->required(),
            'assumptions' => $schema->array()->items($schema->string())->required(),
            'can_answer_now' => $schema->boolean()->required(),
            'confidence' => $schema->number()->required(),
        ];
    }
}
