<?php

namespace App\Ai\Gate;

use App\Ai\Gate\Concerns\GateModelOptions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * ORIENT — the only model stage allowed to read raw conversation text.
 *
 * It absorbs the former TriageAgent and emits a delta-merged patient model.
 * Downstream stages receive only this model, the current question, and retrieved
 * snippets, which prevents stale facts in raw history from leaking back in.
 */
#[UseCheapestModel]
#[MaxTokens(2600)]
final class OrientAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use GateModelOptions;
    use Promptable;

    private const REASONING_EFFORT = 'low';

    public function instructions(): string
    {
        return <<<'TXT'
You are the ORIENT stage for an ESVS vascular assistant. Frame and route; do not answer.

The input contains CURRENT_TURN verbatim, PRIOR_STATE, deterministic TURN_SIGNALS, and the canonical
GUIDELINE_REFERENCE. Apply these rules:
1. Classify mode using the full taxonomy. Deterministic blocked modes cannot be loosened.
2. Decide same_case against PRIOR_STATE. An explicit new patient/case starts a new case. Otherwise,
   a short answer or follow-up normally updates the active case.
3. Delta-merge every explicit fact. Later corrections overwrite earlier values. Preserve unrelated
   findings in other_findings. Never infer an unstated value; use "unknown".
4. Record changed_fields and per-field provenance with the current turn index and verbatim source.
5. Update open_questions: mark answered or declined questions; never silently drop them.
6. Rank at most two candidate guideline keys, selected only from GUIDELINE_REFERENCE. The supplied
   deterministic routing priors are constraints/signals, not optional suggestions.
7. Do not add antithrombotic_therapy unless the turn asks an explicit medication, anticoagulation,
   antiplatelet, bleeding-risk, or perioperative antithrombotic decision.

Return ONLY the structured object. No prose.
TXT;
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'mode' => $schema->string()->enum([
                'knowledge',
                'case_new',
                'case_followup_substantive',
                'case_followup_vague',
                'gate_reply',
                'capabilities',
                'out_of_scope',
                'model_meta',
                'prompt_injection',
            ])->required(),
            'same_case' => $schema->boolean()->required(),
            'new_case_reason' => $schema->string()->required(),
            'response_mode' => $schema->string()
                ->enum(['management', 'knowledge', 'surveillance', 'diagnostic', 'case'])
                ->required(),
            'patient_model' => $schema->object([
                'demographics' => $schema->string()->required(),
                'lesion' => $schema->string()->required(),
                'other_findings' => $schema->array()->items($schema->string())->required(),
                'symptom_status' => $schema->string()->required(),
                'timing' => $schema->string()->required(),
                'fitness' => $schema->string()->required(),
                'imaging' => $schema->string()->required(),
                'comorbidities' => $schema->array()->items($schema->string())->required(),
                'medications' => $schema->array()->items($schema->string())->required(),
                'prior_interventions' => $schema->array()->items($schema->string())->required(),
            ])->required(),
            'changed_fields' => $schema->array()->items($schema->string())->required(),
            'provenance' => $schema->array()->items(
                $schema->object([
                    'field' => $schema->string()->required(),
                    'turn_index' => $schema->integer()->required(),
                    'verbatim_source' => $schema->string()->required(),
                ])
            )->required(),
            'open_questions' => $schema->array()->items(
                $schema->object([
                    'question' => $schema->string()->required(),
                    'status' => $schema->string()->enum(['pending', 'answered', 'declined'])->required(),
                    'answer' => $schema->string()->required(),
                ])
            )->required(),
            'differential' => $schema->array()->items($schema->string())->required(),
            'candidate_guidelines' => $schema->array()->items($schema->string())->required(),
        ];
    }
}
