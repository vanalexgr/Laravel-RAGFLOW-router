<?php

namespace Tests\Unit\GateEval;

use App\Ai\Gate\OrientAgent;
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
}
