<?php

namespace Tests\Unit;

use App\Services\AzureOpenAiLlmClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AzureOpenAiLlmClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('prism.providers.azure.endpoint', 'https://azure.example.test');
        config()->set('prism.providers.azure.api_key', 'test-key');
        config()->set('prism.providers.azure.deployment', 'gpt-5-chat');
        Http::fake([
            '*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]]),
        ]);
    }

    public function test_sends_temperature_when_supported(): void
    {
        config()->set('prism.providers.azure.supports_temperature', true);

        (new AzureOpenAiLlmClient)->complete('prompt', temperature: 0);

        Http::assertSent(fn (Request $request): bool => array_key_exists('temperature', $request->data())
            && $request->data()['temperature'] === 0.0
        );
    }

    public function test_omits_temperature_when_unsupported(): void
    {
        config()->set('prism.providers.azure.supports_temperature', false);

        (new AzureOpenAiLlmClient)->complete('prompt', temperature: 0.7);

        Http::assertSent(fn (Request $request): bool => ! array_key_exists('temperature', $request->data())
        );
    }
}
