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
 * CRITIC — the general evaluator of the evaluator-optimizer loop.
 *
 * This is deliberately NOT a set of case-specific guards (no "AAA != thoracic"
 * rule). It evaluates the ENTIRE gate result against a small set of
 * case-agnostic invariants that hold for every vascular case, and — when an
 * invariant fails — tells the loop WHICH stage to redo and WHY. The loop then
 * re-runs from that stage with the issues injected, and re-evaluates, until the
 * Critic approves or the iteration cap is hit. Quality comes from the loop, not
 * from hardcoded special cases.
 *
 * It is given the full candidate: the accumulated patient_model, the routed
 * guidelines, grounded pathways, capped source snippet digests, prior question
 * lifecycle, unknowns/questions, both answer frames, and logging confidence.
 *
 * Capable model (NOT #[UseCheapestModel]) — a weak critic approves bad output.
 */
#[MaxTokens(4000)]
final class CriticAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use GateModelOptions;
    use Promptable;

    private const REASONING_EFFORT = 'low';

    public function instructions(): string
    {
        return <<<'TXT'
You are a vascular surgery attending doing a rigorous review of a colleague's pre-answer work on a
case. You do NOT re-answer the case. The input includes capped SOURCE_SNIPPET_DIGESTS so grounding
must be checked against actual text, not pathway summaries. Judge the WHOLE candidate against these invariants, which
apply to EVERY case — reason from first principles, never from memorized case types:

1. STATE COMPLETENESS — the patient_model must reflect EVERY clinically material fact stated across
   ALL turns of the case, with later turns correctly updating earlier ones (e.g. a finding that
   changes the anatomy must overwrite the earlier assumption, not sit alongside it). Flag any stated
   fact that is missing, stale, or contradicted.  -> revise_stage: "orient_route"

2. ROUTING VALIDITY — every routed guideline must be the correct source for THIS case's dominant
   problem, and no routed guideline may be outside the case's scope. Decide this by comparing the
   case's anatomy/problem to each guideline's remit — for any guideline, not a fixed list. A pathway
   grounded in a guideline that does not fit the case is a routing failure.  -> revise_stage: "orient_route"

3. RETRIEVAL SUFFICIENCY — a coverage verdict of "not_covered" or "partial" is only acceptable if the
   pathway's queries_tried shows a GENUINE re-retrieval effort (multiple, meaningfully reformulated
   queries), not a single failed query. If retrieval was thin, off-target, or given up too early,
   demand re-retrieval with better queries before any "ESVS does not cover this" conclusion stands.
   -> revise_stage: "ground"

4. GROUNDING — every pathway and grounded-frame claim must be entailed by SOURCE_SNIPPET_DIGESTS,
   not by a model-written guideline_basis or memory. Flag unsupported claims. -> revise_stage: "ground"

5. FRAME INTEGRITY — the answer's two frames must be clean and complete:
   - guideline_grounded_answer may contain ONLY claims supported by retrieved ESVS text; any expert
     extrapolation belongs in interpretive_frame. Flag leakage of non-guideline claims into the
     grounded frame, or ESVS claims presented without basis.
   - interpretive_frame must ALWAYS give the user a usable answer, and must read as non-ESVS
     interpretation — especially when evidence_status is "esvs_absent" (the user must never be left
     with nothing just because the guideline is silent).
   - structured evidence_status must preserve interaction gaps, partial principles, credible
     not-covered findings, and retrieval uncertainty. -> revise_stage: "probe"

6. QUESTION VALUE — at most 2 questions; each must target a fact genuinely absent from the
   patient_model AND be decision-changing (its answer flips the first-line choice between grounded
   pathways). Reject redundant, already-answered, or nice-to-know questions. If no HIGH-impact unknown
   exists, questions must be empty. A question previously answered or declined in OPEN_QUESTIONS is
   always invalid and must never be re-asked. -> revise_stage: "probe"

7. CALIBRATION — confidence is logging-only but should describe the evidence honestly. Never use a
   scalar threshold to recommend ask/proceed. -> revise_stage: "probe"

Approve ONLY when ALL invariants hold. Otherwise list each violation as a concrete, actionable issue
tagged with the invariant it breaks and the exact fix required, and set revise_stage to the EARLIEST
failing stage (orient_route before ground before probe), because fixing an earlier stage may resolve
later ones. Give an overall score 0.0-1.0.

Return ONLY the structured object. No prose.
TXT;
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'approved' => $schema->boolean()->required(),
            'score' => $schema->number()->required(),
            'candidate_summary' => $schema->string()->required(),
            'revise_stage' => $schema->string()
                ->enum(['none', 'orient_route', 'ground', 'probe'])
                ->required(),
            'issues' => $schema->array()->items(
                $schema->object([
                    'invariant' => $schema->string()
                        ->enum([
                            'state_completeness',
                            'routing_validity',
                            'retrieval_sufficiency',
                            'grounding',
                            'frame_integrity',
                            'question_value',
                            'calibration',
                        ])
                        ->required(),
                    'problem' => $schema->string()->required(),
                    'fix' => $schema->string()->required(),
                ])
            )->required(),
        ];
    }
}
