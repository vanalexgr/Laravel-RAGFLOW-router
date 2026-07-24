<?php

namespace Tests\Unit\GateEval;

use App\Ai\Gate\CriticAgent;
use App\Ai\Gate\KnowledgeAnswerAgent;
use App\Ai\Gate\OrientAgent;
use App\Ai\Gate\PathwayAgent;
use App\Ai\Gate\ProbeAgent;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GateAgentSchemaTest extends TestCase
{
    /**
     * @param  class-string  $agentClass
     */
    #[DataProvider('agents')]
    public function test_agent_builds_a_structured_schema(string $agentClass): void
    {
        $agent = $agentClass === PathwayAgent::class
            ? new PathwayAgent('abdominal_aortic_aneurysm')
            : new $agentClass;

        $schema = $agent->schema(new JsonSchemaTypeFactory);

        $this->assertNotEmpty($schema);
        $this->assertContainsOnlyInstancesOf(\Illuminate\JsonSchema\Types\Type::class, $schema);
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function agents(): array
    {
        return [
            'orient' => [OrientAgent::class],
            'pathway' => [PathwayAgent::class],
            'probe' => [ProbeAgent::class],
            'critic' => [CriticAgent::class],
            'knowledge' => [KnowledgeAnswerAgent::class],
        ];
    }
}
