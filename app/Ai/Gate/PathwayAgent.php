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
 * PATHWAY — one structured relevance assessment after each PHP retrieval.
 *
 * The workflow owns retries and calls this agent sequentially. The model never
 * invokes retrieval itself and therefore cannot hide attempts or omit merged
 * patient facts from a query.
 */
#[MaxTokens(2000)]
final class PathwayAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use GateModelOptions;
    use Promptable;

    private const REASONING_EFFORT = 'low';

    public function __construct(
        private readonly string $guidelineKey,
    ) {}

    public function instructions(): string
    {
        return <<<TXT
You assess one PHP retrieval attempt for ESVS guideline "{$this->guidelineKey}".
Input contains only PATIENT_MODEL, CURRENT_QUESTION, QUERY, ATTEMPT, and SNIPPETS.

- Decide whether the snippets are relevant to the decision. If not, propose one materially better
  query grounded in the same patient model. Do not retrieve or use memory.
- coverage is covered, partial, not_covered, or retrieval_uncertain. Before the final attempt, a miss
  is retrieval_uncertain, never not_covered. not_covered is valid only when the input says FINAL_ATTEMPT
  and the supplied retrieval diagnostics support a credible corpus gap.
- Enumerate pathways ONLY from supplied snippets. For each pathway give:
- pathway: the management option (e.g. "carotid endarterectomy", "best medical therapy").
- guideline_basis: a specific supplied recommendation/threshold.
- discriminating_variables: the case variables whose value selects for or against this pathway.

Return ONLY the structured object. No prose.
TXT;
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'guideline_key' => $schema->string()->required(),
            'relevant' => $schema->boolean()->required(),
            'better_query' => $schema->string()->required(),
            'coverage' => $schema->string()
                ->enum(['covered', 'partial', 'not_covered', 'retrieval_uncertain'])
                ->required(),
            'covered_components' => $schema->array()->items($schema->string())->required(),
            'interaction_gap' => $schema->boolean()->required(),
            'pathways' => $schema->array()->items(
                $schema->object([
                    'pathway' => $schema->string()->required(),
                    'guideline_basis' => $schema->string()->required(),
                    'discriminating_variables' => $schema->array()->items($schema->string())->required(),
                ])
            )->required(),
        ];
    }
}
