<?php

namespace App\Services;

use App\Contracts\LlmClient;
use Illuminate\Support\Facades\Http;

class AzureOpenAiLlmClient implements LlmClient
{
    protected ?string $endpoint;

    protected ?string $apiKey;

    protected ?string $deployment;

    protected ?string $apiVersion;

    public function __construct()
    {
        $this->endpoint = config('prism.providers.azure.endpoint');
        $this->apiKey = config('prism.providers.azure.api_key');
        $this->deployment = config('prism.providers.azure.deployment', 'gpt-5-chat');
        $this->apiVersion = config('prism.providers.azure.api_version', '2024-12-01-preview');
    }

    public function complete(string $prompt, int $maxTokens = 150, float $temperature = 0): string
    {
        if (! $this->configured()) {
            throw new \RuntimeException('Azure OpenAI is not configured.');
        }

        $url = rtrim($this->endpoint, '/')
            ."/openai/deployments/{$this->deployment}/chat/completions"
            ."?api-version={$this->apiVersion}";

        $payload = [
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

        if (config('prism.providers.azure.supports_temperature', true)) {
            $payload['temperature'] = $temperature;
        }

        $response = Http::timeout(10)
            ->withHeaders([
                'api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])
            ->post($url, $payload);

        if (! $response->successful()) {
            throw new \RuntimeException('Azure OpenAI request failed with status '.$response->status());
        }

        return (string) $response->json('choices.0.message.content', '');
    }

    protected function configured(): bool
    {
        return ! empty($this->endpoint) && ! empty($this->apiKey) && ! empty($this->deployment);
    }
}
