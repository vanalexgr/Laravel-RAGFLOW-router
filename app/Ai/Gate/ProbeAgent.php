<?php

namespace App\Ai\Gate;

use App\Ai\Gate\Concerns\GateModelOptions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
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
#[Temperature(0)]
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
guidelines fall short, and must always know which parts are retrieved guideline text and which are
expert interpretation:
- guideline_grounded_answer: ONLY claims directly supported by supplied snippets. The corpus holds
  SEVERAL guideline families, not only ESVS — snippets may come from ESVS documents, from the Global
  Vascular Guidelines (GVG), or from others. NEVER attribute the retrieved material to ESVS
  collectively. Name the specific source shown in each snippet, and where claims come from different
  documents, attribute them separately rather than merging them under one banner.
- interpretive_frame: useful reasoning beyond the retrieved text. Do not write the banner; PHP adds it.
  Do not introduce drugs, doses, or numeric thresholds absent from snippets and patient facts.
- evidence_status: copy the supplied structured object exactly. Never collapse interaction_gap,
  partial_principles, or retrieval_uncertain.
- Write both frames as content for the supplied HOUSE_SECTIONS/response_mode. Do not add sections
  that the deterministic renderer owns.
- assumptions: the assumptions you make to proceed.
- confidence (0.0-1.0): calibrated probability your overall answer would NOT change if the unknowns
  were filled.
- If ISSUES are provided, fix every one of them in this pass.

COMPOSE THE DECISION IN THIS ORDER. The order is part of the safety contract:
1. baseline_pathway: state the standard ESVS pathway for the primary pathology before considering
   comorbidities. This must be grounded in the supplied evidence.
2. patient_deviations: list only patient factors that modify or conflict with that baseline.
3. actionable_plan: commit to a timing and pharmacotherapy regimen, then state what must not be
   done. Do not replace this synthesis with "MDT", "individualise", or "local protocol".
4. escalation_and_reassessment: name an observable trigger and the action it causes.

Use the exact literal EVIDENCE_ABSENT instead of inventing a timing, drug, dose, duration, harm, or
trigger not supported by the supplied evidence and patient facts. When EVIDENCE_ABSENT prevents a
committed plan, select the applicable typed deferral_justification; otherwise use NOT_DEFERRED.
Valid deferrals are limited to:
- GUIDELINE_MANDATED
- UNRESOLVABLE_CONTRAINDICATION
- MULTISPECIALTY_CONFLICT

For antithrombotic combinations, antithrombotic_combination_justification must state the explicit
indication for planned long-term anticoagulant plus antiplatelet therapy. Use NOT_APPLICABLE when no
such combination is planned. Address domain checklist items explicitly when they apply, including
urgency, anticoagulant interruption, bridging, perioperative antiplatelet therapy, and a restart
criterion for carotid patients taking a DOAC.

Return ONLY the structured object. No prose.
TXT;
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            // Baseline deliberately comes first: it conditions the remaining
            // generation on the guideline default before patient deviations.
            'baseline_pathway' => $schema->string()->required(),
            'patient_deviations' => $schema->array()->items($schema->string())->required(),
            'actionable_plan' => $schema->object([
                'timing' => $schema->string()->required(),
                'pharmacotherapy_regimen' => $schema->string()->required(),
                'what_not_to_do' => $schema->array()->items($schema->string())->required(),
                'deferral_justification' => $schema->string()->enum([
                    'NOT_DEFERRED',
                    'GUIDELINE_MANDATED',
                    'UNRESOLVABLE_CONTRAINDICATION',
                    'MULTISPECIALTY_CONFLICT',
                ])->required(),
                'antithrombotic_combination_justification' => $schema->string()->required(),
            ])->required(),
            'escalation_and_reassessment' => $schema->object([
                'trigger_event' => $schema->string()->required(),
                'action_on_trigger' => $schema->string()->required(),
            ])->required(),
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
