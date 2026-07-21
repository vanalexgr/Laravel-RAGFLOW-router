<?php

namespace App\Services;

use App\Contracts\LlmClient;
use Illuminate\Support\Facades\Http;

class OpenAiLlmClient implements LlmClient
{
    protected ?string $baseUrl;

    protected ?string $apiKey;

    protected string $model;

    protected bool $supportsTemperature;

    protected int $timeout;

    protected ?string $reasoningEffort;

    public function __construct()
    {
        $this->baseUrl = config('services.openai.url') ?: config('prism.providers.openai.url');
        $this->apiKey = config('services.openai.api_key') ?: config('prism.providers.openai.api_key');
        $this->model = (string) config('services.openai.model', 'gpt-5-mini');
        $this->supportsTemperature = (bool) config('services.openai.supports_temperature', false);
        $this->timeout = (int) config('services.openai.timeout', 30);
        $effort = config('services.openai.reasoning_effort');
        $this->reasoningEffort = $effort !== null && $effort !== '' ? (string) $effort : null;
    }

    public function complete(string $prompt, int $maxTokens = 150, float $temperature = 0): string
    {
        if (! $this->configured()) {
            throw new \RuntimeException('OpenAI is not configured.');
        }

        $url = rtrim($this->baseUrl, '/').'/chat/completions';

        $payload = [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Return only the requested output. Do not add markdown fences.',
                ],
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
            'max_completion_tokens' => $maxTokens,
        ];

        if ($this->supportsTemperature) {
            $payload['temperature'] = $temperature;
        }

        if ($this->reasoningEffort !== null) {
            $payload['reasoning_effort'] = $this->reasoningEffort;
        }

        $response = Http::timeout($this->timeout)
            ->withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
                'Content-Type' => 'application/json',
            ])
            ->post($url, $payload);

        if (! $response->successful()) {
            throw new \RuntimeException('OpenAI request failed with status '.$response->status());
        }

        return (string) $response->json('choices.0.message.content', '');
    }

    protected function configured(): bool
    {
        return ! empty($this->baseUrl) && ! empty($this->apiKey) && ! empty($this->model);
    }
}
