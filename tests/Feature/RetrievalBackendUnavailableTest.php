<?php

namespace Tests\Feature;

use Tests\TestCase;

class RetrievalBackendUnavailableTest extends TestCase
{
    public function test_backend_failure_returns_retryable_503_instead_of_no_evidence_guidance(): void
    {
        $this->fakeExternalServices([
            'status' => 502,
            'message' => 'All RAGFlow retrieval branches failed',
            'errors' => [
                'narrative' => ['connection refused'],
                'citation' => ['connection refused'],
            ],
            'degraded' => true,
            'narrative' => ['chunks' => [], 'count' => 0],
            'citations' => ['chunks' => [], 'count' => 0],
        ]);

        $response = $this->postJson('/api/v1/vascular-consult', [
            'question' => 'What is the recommended diameter threshold for elective AAA repair?',
            'history' => [],
        ], ['X-API-Key' => 'test-key']);

        $response->assertStatus(503)
            ->assertJsonPath('retryable', true)
            ->assertJsonPath('error', 'Guideline retrieval backend unavailable — please retry');

        $this->assertStringNotContainsString(
            'no relevant ESVS context',
            (string) $response->getContent()
        );
    }
}
