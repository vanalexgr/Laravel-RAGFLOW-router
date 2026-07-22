<?php

namespace Tests\Unit;

use App\Services\PendingCaseStateService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PendingCaseStateServiceTest extends TestCase
{
    private PendingCaseStateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.stores.redis', ['driver' => 'array', 'serialize' => false]);
        Cache::purge('redis');
        Cache::store('redis')->clear();
        $this->service = app(PendingCaseStateService::class);
    }

    public function test_pending_pre_result_round_trips_only_scrubbed_fields(): void
    {
        $record = $this->service->put('chat-pending-unit', [
            'proceed' => true,
            'soft_warn' => true,
            'clarification_questions' => ['Does MRN 123456 have rest pain?'],
            'provisional_diagnosis' => 'CLTI for patient@example.com',
            'guidelines' => ['clti', 'antithrombotic_therapy', 'patient_john'],
            'retrieval_query' => 'limb salvage MRN 7654321',
            'scope' => 'patient_john',
            'confirmation_message' => 'Confirm for patient@example.com',
            'question' => 'raw question must never be stored',
            'history' => ['raw history must never be stored'],
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
        ], array_keys($record));
        $serialized = json_encode($record, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('123456', $serialized);
        $this->assertStringNotContainsString('7654321', $serialized);
        $this->assertStringNotContainsString('patient@example.com', $serialized);
        $this->assertStringNotContainsString('patient_john', $serialized);
        $this->assertSame('single_guideline', $record['scope']);
        $this->assertSame(['clti', 'antithrombotic_therapy'], $record['guidelines']);
        $this->assertSame($record, $this->service->get('chat-pending-unit'));

        $this->service->forget('chat-pending-unit');
        $this->assertNull($this->service->get('chat-pending-unit'));
    }

    public function test_pending_pre_result_expires_after_five_minutes(): void
    {
        $this->service->put('chat-pending-expiry', $this->validPreResult());

        $this->assertNotNull($this->service->get('chat-pending-expiry'));
        $this->travel(PendingCaseStateService::TTL_SECONDS + 1)->seconds();
        $this->assertNull($this->service->get('chat-pending-expiry'));
    }

    private function validPreResult(): array
    {
        return [
            'proceed' => true,
            'soft_warn' => false,
            'clarification_questions' => ['Is rest pain present?'],
            'provisional_diagnosis' => 'Peripheral arterial disease',
            'guidelines' => ['clti'],
            'retrieval_query' => 'peripheral arterial disease management',
            'scope' => 'single_guideline',
            'confirmation_message' => 'Reply to confirm.',
        ];
    }
}
