<?php

namespace App\Ai\Gate\Concerns;

use Laravel\Ai\Enums\Lab;

trait GateModelOptions
{
    /**
     * Config stage key per agent, used for both the model and the reasoning effort
     * so the two can never drift apart.
     */
    private const STAGE_KEYS = [
        \App\Ai\Gate\OrientAgent::class => 'orient',
        \App\Ai\Gate\PathwayAgent::class => 'pathway',
        \App\Ai\Gate\ProbeAgent::class => 'probe',
        \App\Ai\Gate\CriticAgent::class => 'critic',
        \App\Ai\Gate\KnowledgeAnswerAgent::class => 'knowledge',
    ];

    /**
     * Keep cloud development within the wall-clock budget without leaking an
     * OpenAI-only option to future local/OpenAI-compatible providers.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        $driver = $provider instanceof Lab ? $provider->value : $provider;
        if ($driver !== Lab::OpenAI->value) {
            return [];
        }

        $stage = self::STAGE_KEYS[static::class] ?? null;
        $model = $stage === null
            ? (string) config('gate-v2.model')
            : (string) config("gate-v2.stage_models.{$stage}", config('gate-v2.model'));

        if (! $this->modelAcceptsReasoningEffort($model)) {
            return [];
        }

        return ['reasoning' => ['effort' => $this->reasoningEffort($stage)]];
    }

    /**
     * A stage model outside the configured reasoning families ignores the effort
     * option entirely — Run 7 shipped `effort=low` against gpt-4.1 and it was inert.
     */
    private function modelAcceptsReasoningEffort(string $model): bool
    {
        foreach ((array) config('gate-v2.reasoning_model_prefixes', ['gpt-5']) as $prefix) {
            if ($prefix !== '' && str_starts_with($model, (string) $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Configured stage effort wins; the agent constant remains the default so an
     * unset config reproduces the previous behaviour exactly.
     */
    private function reasoningEffort(?string $stage): string
    {
        $configured = $stage === null ? null : config("gate-v2.stage_efforts.{$stage}");

        return is_string($configured) && $configured !== ''
            ? $configured
            : static::REASONING_EFFORT;
    }
}
