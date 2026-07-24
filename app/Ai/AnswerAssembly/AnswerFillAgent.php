<?php

namespace App\Ai\AnswerAssembly;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

#[MaxTokens(3000)]
final class AnswerFillAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'TXT'
Fill a deterministic vascular answer skeleton using ONLY the supplied planner output, retrieved
evidence, evidence_status, patient facts, and explicitly enabled audited snippets.

- Do not change section order or write markdown headings; PHP owns structure.
- guideline_grounded_answer may contain only claims directly supported by retrieved evidence.
- interpretive_frame must be useful but must not introduce drugs, doses, numeric thresholds, or
  procedures absent from the supplied inputs.
- Never turn retrieval_uncertain into "no guidance".
- For partial_principles, describe supplied general principles without claiming a total gap.
- Keep every field concise. Return only the structured object.
TXT;
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'direct_answer' => $schema->string()->required(),
            'guideline_grounded_answer' => $schema->string()->required(),
            'interpretive_frame' => $schema->string()->required(),
            'practical_points' => $schema->array()->items($schema->string())->required(),
            'evidence_used' => $schema->array()->items($schema->string())->required(),
            'questions' => $schema->array()->items($schema->string())->required(),
        ];
    }
}
