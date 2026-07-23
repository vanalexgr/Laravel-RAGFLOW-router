<?php

namespace App\Ai\Gate;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * ORIENT — stage 1 of the agentic clarification gate.
 *
 * Cheap, fast classification pass. Reads the raw case (+ prior turns) and
 * produces a structured patient model, a short differential, and the set of
 * candidate ESVS guideline keys the case could route to. It does NOT ground in
 * retrieved text and does NOT ask questions — it only frames the problem so the
 * parallel PathwayAgents know which guidelines to pull.
 *
 * Marked #[UseCheapestModel] (the blog's "routing" optimisation): framing a case
 * is a classification task, not a reasoning task, so it runs on the cheapest
 * configured model. On ISI this maps to a small local model.
 *
 * Input (passed by the workflow at prompt() time): a JSON blob of
 *   { "case": string, "history": string[], "guideline_keys": {key: name} }
 * Output: structured array accessible via array offsets on the response.
 */
#[UseCheapestModel]
final class OrientAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'TXT'
You are a senior vascular surgeon triaging a case before any guideline evidence is retrieved.
Your ONLY job here is to FRAME the case, not to answer it and not to ask questions.

Given the CASE and any PRIOR CONVERSATION:
1. Build a structured patient model from what is EXPLICITLY stated. Use "unknown" for anything not stated.
   Never infer a value that was not given (e.g. do not assume "symptomatic" from the mere mention of stroke).
2. Give a short differential — the most likely clinical framings, most likely first.
3. Select the candidate ESVS guideline keys this case could route to, chosen ONLY from the
   guideline_keys provided in the input. Include a key only if the case plausibly touches it.
   Do NOT add antithrombotic/anticoagulation guidelines unless the case actually asks about
   anticoagulation or antithrombotic decisions.

Return ONLY the structured object. No prose.
TXT;
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'patient_model' => $schema->object([
                'demographics' => $schema->string()->required(),
                'lesion' => $schema->string()->required(),
                'symptom_status' => $schema->string()->required(),
                'timing' => $schema->string()->required(),
                'fitness' => $schema->string()->required(),
                'imaging' => $schema->string()->required(),
                'comorbidities' => $schema->array()->items($schema->string())->required(),
                'medications' => $schema->array()->items($schema->string())->required(),
                'prior_interventions' => $schema->array()->items($schema->string())->required(),
            ])->required(),
            'differential' => $schema->array()->items($schema->string())->required(),
            'candidate_guidelines' => $schema->array()->items($schema->string())->required(),
        ];
    }
}
