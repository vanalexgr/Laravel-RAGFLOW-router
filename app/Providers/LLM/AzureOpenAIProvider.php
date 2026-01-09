<?php

namespace App\Providers\LLM;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider as ProviderName;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use Prism\Prism\Providers\OpenAI\Concerns\ProcessRateLimits;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;

class AzureOpenAIProvider extends Provider
{
    use ProcessRateLimits;

    public function __construct(
        #[\SensitiveParameter] public readonly string $apiKey,
        public readonly string $endpoint,
        public readonly string $deployment,
        public readonly string $apiVersion,
    ) {}

    #[\Override]
    public function text(TextRequest $request): TextResponse
    {
        $handler = new AzureTextHandler($this->client(
            $request->clientOptions(),
            $request->clientRetry()
        ));

        return $handler->handle($request);
    }

    #[\Override]
    public function structured(StructuredRequest $request): StructuredResponse
    {
        $handler = new AzureStructuredHandler($this->client(
            $request->clientOptions(),
            $request->clientRetry()
        ));

        return $handler->handle($request);
    }

    #[\Override]
    public function stream(TextRequest $request): Generator
    {
        $handler = new AzureStreamHandler($this->client(
            $request->clientOptions(),
            $request->clientRetry()
        ));

        return $handler->handle($request);
    }

    public function handleRequestException(string $model, RequestException $e): never
    {
        match ($e->response->getStatusCode()) {
            429 => throw PrismRateLimitedException::make(
                rateLimits: $this->processRateLimits($e->response),
                retryAfter: (int) $e->response->header('retry-after')
            ),
            529 => throw PrismProviderOverloadedException::make(ProviderName::OpenAI),
            413 => throw PrismRequestTooLargeException::make(ProviderName::OpenAI),
            default => throw PrismException::providerRequestError($model, $e),
        };
    }

    protected function client(array $options = [], array $retry = []): PendingRequest
    {
        $baseUrl = rtrim($this->endpoint, '/') . '/openai/deployments/' . $this->deployment;

        return Http::withHeaders([
                'api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])
            ->withQueryParameters([
                'api-version' => $this->apiVersion,
            ])
            ->withOptions($options)
            ->when($retry !== [], fn ($client) => $client->retry(...$retry))
            ->baseUrl($baseUrl)
            ->throw();
    }
}
