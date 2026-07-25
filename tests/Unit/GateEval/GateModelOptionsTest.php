<?php

namespace Tests\Unit\GateEval;

use App\Ai\Gate\OrientAgent;
use App\Ai\Gate\ProbeAgent;
use Laravel\Ai\Enums\Lab;
use Tests\TestCase;

class GateModelOptionsTest extends TestCase
{
    public function test_non_reasoning_stage_model_does_not_receive_gpt5_options(): void
    {
        config()->set('gate-v2.stage_models.orient', 'gpt-4.1-mini');

        $this->assertSame([], (new OrientAgent)->providerOptions(Lab::OpenAI));
    }

    public function test_gpt5_stage_model_retains_low_reasoning_option(): void
    {
        config()->set('gate-v2.stage_models.orient', 'gpt-5-mini');

        $this->assertSame(
            ['reasoning' => ['effort' => 'low']],
            (new OrientAgent)->providerOptions(Lab::OpenAI),
        );
    }

    public function test_configured_stage_effort_overrides_the_agent_constant(): void
    {
        config()->set('gate-v2.stage_models.probe', 'gpt-5');
        config()->set('gate-v2.stage_efforts.probe', 'high');

        $this->assertSame(
            ['reasoning' => ['effort' => 'high']],
            (new ProbeAgent)->providerOptions(Lab::OpenAI),
        );
    }

    public function test_effort_is_dropped_for_a_model_outside_the_reasoning_prefixes(): void
    {
        config()->set('gate-v2.stage_models.probe', 'gpt-4.1');
        config()->set('gate-v2.stage_efforts.probe', 'high');

        $this->assertSame([], (new ProbeAgent)->providerOptions(Lab::OpenAI));
    }

    public function test_a_newly_adopted_reasoning_family_can_be_declared(): void
    {
        config()->set('gate-v2.reasoning_model_prefixes', ['gpt-5', 'o3']);
        config()->set('gate-v2.stage_models.probe', 'o3-mini');
        config()->set('gate-v2.stage_efforts.probe', 'medium');

        $this->assertSame(
            ['reasoning' => ['effort' => 'medium']],
            (new ProbeAgent)->providerOptions(Lab::OpenAI),
        );
    }
}
