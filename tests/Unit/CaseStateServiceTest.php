<?php

namespace Tests\Unit;

use App\Services\CaseStateService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CaseStateServiceTest extends TestCase
{
    private CaseStateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.stores.redis', ['driver' => 'array', 'serialize' => false]);
        Cache::purge('redis');
        Cache::store('redis')->clear();
        $this->service = app(CaseStateService::class);
    }

    public function test_put_get_and_forget_use_only_compact_scrubbed_fields(): void
    {
        $record = $this->service->put('chat-unit', [
            'provisional_diagnosis' => 'AAA, MRN 123456',
            'guidelines' => ['abdominal_aortic_aneurysm'],
            'retrieval_query' => 'aneurysm patient@example.com',
            'question' => 'must never be stored',
            'history' => ['must never be stored'],
        ]);

        $this->assertSame(
            ['provisional_diagnosis', 'guidelines', 'retrieval_query', 'ts'],
            array_keys($record),
        );
        $this->assertStringNotContainsString('123456', $record['provisional_diagnosis']);
        $this->assertStringNotContainsString('patient@example.com', $record['retrieval_query']);
        $this->assertSame($record, $this->service->get('chat-unit'));

        $this->service->forget('chat-unit');
        $this->assertNull($this->service->get('chat-unit'));
    }

    public function test_record_expires_after_fifteen_minutes(): void
    {
        $this->service->put('chat-expiry', [
            'provisional_diagnosis' => 'Carotid stenosis',
            'guidelines' => ['carotid_vertebral'],
            'retrieval_query' => 'symptomatic carotid stenosis',
        ]);

        $this->assertNotNull($this->service->get('chat-expiry'));
        $this->travel(CaseStateService::TTL_SECONDS + 1)->seconds();
        $this->assertNull($this->service->get('chat-expiry'));
    }
}
