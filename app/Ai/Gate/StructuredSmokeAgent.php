<?php

namespace App\Ai\Gate;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Minimal provider smoke test. This is intentionally independent of the gate
 * prompts so SDK/provider failures can be isolated from workflow behaviour.
 */
final class StructuredSmokeAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return 'Extract the requested fields. Return only the structured object.';
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum(['ready'])->required(),
            'items' => $schema->array()->items($schema->string())->required(),
        ];
    }
}
