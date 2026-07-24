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

        return $driver === Lab::OpenAI->value
            ? ['reasoning' => ['effort' => static::REASONING_EFFORT]]
            : [];
    }
}
