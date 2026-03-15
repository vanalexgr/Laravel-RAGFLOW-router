<?php

namespace App\Contracts;

interface LlmClient
{
    public function complete(string $prompt, int $maxTokens = 150, float $temperature = 0): string;
}
