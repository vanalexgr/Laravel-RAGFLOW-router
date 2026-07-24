<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Integration tests: these POST to the real /api/v1/vascular-consult, which
 * reaches the RAGFlow bridge and Azure OpenAI. They are grouped 'external' so
 * CI can skip them — a host without those services cannot run them, and a red
 * CI that everyone learns to ignore is worse than no CI. Run them on a host
 * with access: ./vendor/bin/phpunit --group external
 */
#[Group('external')]
class LeanRetrievalTest extends TestCase
{
    private string $apiKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->apiKey = config('services.api.key', '');
    }

    /**
     * API response must include query_type field on every request.
     */
    public function test_api_response_includes_query_type(): void
    {
        $response = $this->postJson('/api/v1/vascular-consult', [
            'question' => 'What is the recommended diameter threshold for elective AAA repair?',
            'history' => [],
        ], ['X-API-Key' => $this->apiKey]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['query_type']);
        $this->assertContains(
            $response->json('query_type'),
            ['knowledge', 'single_case', 'complex_case'],
            'query_type must be one of the three valid classifier values'
        );
    }

    /**
     * A clear knowledge question must return query_type = 'knowledge'.
     */
    public function test_knowledge_query_returns_knowledge_type(): void
    {
        $response = $this->postJson('/api/v1/vascular-consult', [
            'question' => 'What is the recommended diameter threshold for elective AAA repair?',
            'history' => [],
        ], ['X-API-Key' => $this->apiKey]);

        $response->assertStatus(200);
        $this->assertEquals('knowledge', $response->json('query_type'),
            'Clear knowledge question must be classified as knowledge');
    }

    /**
     * A patient case with sufficient context must return single_case or complex_case
     * (not knowledge).
     */
    public function test_patient_case_not_classified_as_knowledge(): void
    {
        $response = $this->postJson('/api/v1/vascular-consult', [
            'question' => '75-year-old fit man, symptomatic 80% carotid stenosis, TIA 5 days ago. Recommended intervention?',
            'history' => [],
        ], ['X-API-Key' => $this->apiKey]);

        $response->assertStatus(200);
        $this->assertNotEquals('knowledge', $response->json('query_type'),
            'Patient case must not be classified as knowledge');
    }
}
