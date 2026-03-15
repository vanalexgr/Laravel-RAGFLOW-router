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
        $this->endpoint = config('prism.providers.azure.endpoint') ?: env('AZURE_OPENAI_ENDPOINT');
        $this->apiKey = config('prism.providers.azure.api_key') ?: env('AZURE_OPENAI_API_KEY');
        $this->deployment = config('prism.providers.azure.deployment') ?: env('AZURE_OPENAI_DEPLOYMENT', 'gpt-5-chat');
        $this->apiVersion = config('prism.providers.azure.api_version') ?: env('AZURE_OPENAI_VERSION', '2024-12-01-preview');
    }

    public function complete(string $prompt, int $maxTokens = 150, float $temperature = 0): string
    {
        if (!$this->configured()) {
            throw new \RuntimeException('Azure OpenAI is not configured.');
        }

        $url = rtrim($this->endpoint, '/')
            . "/openai/deployments/{$this->deployment}/chat/completions"
            . "?api-version={$this->apiVersion}";

        $response = Http::timeout(10)
            ->withHeaders([
                'api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])
            ->post($url, [
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
                'max_tokens' => $maxTokens,
                'temperature' => $temperature,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Azure OpenAI request failed with status ' . $response->status());
        }

        return (string) $response->json('choices.0.message.content', '');
    }

    protected function configured(): bool
    {
        return !empty($this->endpoint) && !empty($this->apiKey) && !empty($this->deployment);
    }
}
