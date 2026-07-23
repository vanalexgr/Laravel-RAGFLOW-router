<?php

namespace Tests\Unit\GateEval;

use App\GateEval\ScenarioRepository;
use Tests\TestCase;

class ScenarioRepositoryTest extends TestCase
{
    public function test_all_gate_v2_scenarios_validate(): void
    {
        $scenarios = app(ScenarioRepository::class)->load();

        $this->assertCount(22, $scenarios);
        $this->assertCount(15, array_filter(
            $scenarios,
            fn (array $scenario): bool => in_array('non_regression_15', $scenario['tags'], true)
        ));
    }
}
