<?php

namespace Tests\Feature;

use App\Services\CaseStateService;
use App\Services\PendingCaseStateService;
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
        $this->getJson('/api/v1/pending-case-state/chat-auth')->assertUnauthorized();
        $this->putJson('/api/v1/pending-case-state/chat-auth', [])->assertUnauthorized();
        $this->deleteJson('/api/v1/pending-case-state/chat-auth')->assertUnauthorized();
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

    public function test_pending_pre_result_round_trip_ttl_and_phi_allowlist(): void
    {
        $payload = [
            'proceed' => true,
            'soft_warn' => true,
            'clarification_questions' => ['Does MRN 123456 have tissue loss?'],
            'provisional_diagnosis' => 'CLTI for patient@example.com',
            'guidelines' => ['clti', 'antithrombotic_therapy'],
            'retrieval_query' => 'limb salvage MRN 7654321',
            'scope' => 'multi_guideline',
            'confirmation_message' => 'Confirm for patient@example.com',
            'question' => 'raw question must not persist',
            'history' => ['raw history must not persist'],
        ];

        $put = $this->putJson('/api/v1/pending-case-state/chat-pending', $payload, $this->headers);
        $put->assertOk()->assertJsonStructure([
            'proceed',
            'soft_warn',
            'clarification_questions',
            'provisional_diagnosis',
            'guidelines',
            'retrieval_query',
            'scope',
            'confirmation_message',
            'ts',
        ]);
        $this->assertSame([
            'proceed',
            'soft_warn',
            'clarification_questions',
            'provisional_diagnosis',
            'guidelines',
            'retrieval_query',
            'scope',
            'confirmation_message',
            'ts',
        ], array_keys($put->json()));
        $serialized = json_encode($put->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('123456', $serialized);
        $this->assertStringNotContainsString('7654321', $serialized);
        $this->assertStringNotContainsString('patient@example.com', $serialized);

        $this->getJson('/api/v1/pending-case-state/chat-pending', $this->headers)
            ->assertOk()
            ->assertJson($put->json());

        $this->travel(PendingCaseStateService::TTL_SECONDS + 1)->seconds();
        $this->getJson('/api/v1/pending-case-state/chat-pending', $this->headers)->assertNoContent();
    }

    public function test_delete_forgets_pending_pre_result(): void
    {
        Cache::store('redis')->put('pending:chat-pending-delete', [
            'proceed' => true,
            'soft_warn' => false,
            'clarification_questions' => [],
            'provisional_diagnosis' => 'AAA',
            'guidelines' => ['abdominal_aortic_aneurysm'],
            'retrieval_query' => 'AAA repair',
            'scope' => 'single_guideline',
            'confirmation_message' => 'Reply to confirm.',
            'ts' => now()->timestamp,
        ], PendingCaseStateService::TTL_SECONDS);

        $this->deleteJson('/api/v1/pending-case-state/chat-pending-delete', [], $this->headers)
            ->assertNoContent();
        $this->getJson('/api/v1/pending-case-state/chat-pending-delete', $this->headers)
            ->assertNoContent();
    }

    public function test_pending_endpoint_accepts_normalized_empty_optional_fields(): void
    {
        $response = $this->putJson('/api/v1/pending-case-state/chat-empty-optionals', [
            'proceed' => true,
            'soft_warn' => false,
            'clarification_questions' => [],
            'provisional_diagnosis' => '',
            'guidelines' => ['abdominal_aortic_aneurysm'],
            'retrieval_query' => 'AAA repair threshold',
            'scope' => 'knowledge_question',
            'confirmation_message' => '',
        ], $this->headers);

        $response->assertOk()
            ->assertJsonPath('clarification_questions', [])
            ->assertJsonPath('provisional_diagnosis', '')
            ->assertJsonPath('confirmation_message', '');
    }
}
