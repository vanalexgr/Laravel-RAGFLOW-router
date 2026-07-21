<?php

namespace Tests\Feature;

use App\Services\CaseStateService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CaseStateEndpointTest extends TestCase
{
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.stores.redis', ['driver' => 'array', 'serialize' => false]);
        Cache::purge('redis');
        Cache::store('redis')->clear();
        $this->headers = ['X-API-Key' => (string) config('services.api.key')];
    }

    public function test_all_case_state_endpoints_require_api_authentication(): void
    {
        $this->getJson('/api/v1/case-state/chat-auth')->assertUnauthorized();
        $this->putJson('/api/v1/case-state/chat-auth', [])->assertUnauthorized();
        $this->deleteJson('/api/v1/case-state/chat-auth')->assertUnauthorized();
    }

    public function test_case_state_round_trip_ttl_and_field_allowlist(): void
    {
        $payload = [
            'provisional_diagnosis' => 'CLTI, MRN 7654321',
            'guidelines' => ['clti', 'antithrombotic_therapy'],
            'retrieval_query' => 'limb salvage patient@example.com',
            'question' => 'raw question must not persist',
            'history' => ['raw history must not persist'],
        ];

        $put = $this->putJson('/api/v1/case-state/chat-round-trip', $payload, $this->headers);
        $put->assertOk()->assertJsonStructure([
            'provisional_diagnosis', 'guidelines', 'retrieval_query', 'ts',
        ]);
        $this->assertSame(
            ['provisional_diagnosis', 'guidelines', 'retrieval_query', 'ts'],
            array_keys($put->json()),
        );
        $this->assertStringNotContainsString('7654321', $put->json('provisional_diagnosis'));
        $this->assertStringNotContainsString('patient@example.com', $put->json('retrieval_query'));

        $this->getJson('/api/v1/case-state/chat-round-trip', $this->headers)
            ->assertOk()
            ->assertJson($put->json());

        $this->travel(CaseStateService::TTL_SECONDS + 1)->seconds();
        $this->getJson('/api/v1/case-state/chat-round-trip', $this->headers)->assertNoContent();
    }

    public function test_delete_forgets_case_state(): void
    {
        Cache::store('redis')->put('casestate:chat-delete', [
            'provisional_diagnosis' => 'AAA',
            'guidelines' => ['abdominal_aortic_aneurysm'],
            'retrieval_query' => 'AAA repair',
            'ts' => now()->timestamp,
        ], CaseStateService::TTL_SECONDS);

        $this->deleteJson('/api/v1/case-state/chat-delete', [], $this->headers)->assertNoContent();
        $this->getJson('/api/v1/case-state/chat-delete', $this->headers)->assertNoContent();
    }
}
