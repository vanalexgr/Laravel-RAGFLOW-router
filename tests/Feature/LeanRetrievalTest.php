<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\Http;

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
            'history'  => [],
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
            'history'  => [],
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
            'history'  => [],
        ], ['X-API-Key' => $this->apiKey]);

        $response->assertStatus(200);
        $this->assertNotEquals('knowledge', $response->json('query_type'),
            'Patient case must not be classified as knowledge');
    }
}
