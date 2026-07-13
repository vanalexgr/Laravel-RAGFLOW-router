<?php

namespace Tests\Unit;

use App\Services\GuidelineRouterService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GuidelineRouterFailureTest extends TestCase
{
    public function test_connection_failure_uses_routing_fallback_without_throwing(): void
    {
        config()->set('prism.providers.azure.endpoint', 'https://azure.example.test');
        config()->set('prism.providers.azure.api_key', 'test-key');
        config()->set('prism.providers.azure.deployment', 'gpt-5-chat');
        Http::fake(fn () => throw new ConnectionException('refused'));

        $result = (new GuidelineRouterService)->selectAndExpand(
            'carotid stenosis management',
            3
        );

        $this->assertIsArray($result);
        $this->assertContains($result['routing_method'], [
            'llm',
            'fallback',
            'document_only',
        ]);
    }
}
