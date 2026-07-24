<?php

namespace App\Ai\Gate\Concerns;

use Laravel\Ai\Enums\Lab;

trait GateModelOptions
{
    /**
     * Keep cloud development within the wall-clock budget without leaking an
     * OpenAI-only option to future local/OpenAI-compatible providers.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        $driver = $provider instanceof Lab ? $provider->value : $provider;
        $model = match (static::class) {
            \App\Ai\Gate\OrientAgent::class => (string) config('gate-v2.stage_models.orient'),
            \App\Ai\Gate\PathwayAgent::class => (string) config('gate-v2.stage_models.pathway'),
            \App\Ai\Gate\ProbeAgent::class => (string) config('gate-v2.stage_models.probe'),
            \App\Ai\Gate\CriticAgent::class => (string) config('gate-v2.stage_models.critic'),
            \App\Ai\Gate\KnowledgeAnswerAgent::class => (string) config('gate-v2.stage_models.knowledge'),
            default => (string) config('gate-v2.model'),
        };

        return $driver === Lab::OpenAI->value && str_starts_with($model, 'gpt-5')
            ? ['reasoning' => ['effort' => static::REASONING_EFFORT]]
            : [];
    }
}
