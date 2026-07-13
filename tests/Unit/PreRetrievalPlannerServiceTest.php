<?php

namespace Tests\Unit;

use App\Contracts\LlmClient;
use App\Services\PreRetrievalPlannerService;
use Tests\TestCase;

class PreRetrievalPlannerServiceTest extends TestCase
{
    public function test_it_normalizes_the_planner_contract_and_drops_unknown_guidelines(): void
    {
        $service = new PreRetrievalPlannerService(new class implements LlmClient {
            public function complete(string $prompt, int $maxTokens = 150, float $temperature = 0): string
            {
                return '```json {"language":"el","normalized_query":"carotid stenosis","normalized_changed":true,"query_type":"single_case","intent":"management","guidelines":["carotid_vertebral","not_real"],"guideline_scores":{"carotid_vertebral":0.9,"not_real":1},"expansion_terms":["CEA",5],"clinical_frame":"framing","interpretation_terms":["symptomatic"],"must_include_terms":[],"graph":{"core_concepts":["carotid stenosis"],"related_concepts":[],"slots":{"anatomy":["carotid"],"imaging":"not-an-array"}}} ```';
            }
        });

        $plan = $service->plan('question');

        $this->assertNotNull($plan);
        $this->assertSame(['carotid_vertebral'], $plan->guidelines);
        $this->assertSame(['carotid_vertebral' => 0.9], $plan->guidelineScores);
        $this->assertSame(['CEA'], $plan->expansionTerms);
        $this->assertSame(['carotid'], $plan->graphSlots['anatomy']);
        $this->assertSame([], $plan->graphSlots['imaging']);
    }

    public function test_it_honours_explicit_guideline_keys_and_falls_back_on_invalid_json(): void
    {
        $valid = new PreRetrievalPlannerService(new class implements LlmClient {
            public function complete(string $prompt, int $maxTokens = 150, float $temperature = 0): string
            {
                return '{"guidelines":["carotid_vertebral"],"query_type":"knowledge"}';
            }
        });
        $this->assertSame(['venous_thrombosis'], $valid->plan('question', [], ['venous_thrombosis'])->guidelines);

        $invalid = new PreRetrievalPlannerService(new class implements LlmClient {
            public function complete(string $prompt, int $maxTokens = 150, float $temperature = 0): string
            {
                return 'not json';
            }
        });
        $this->assertNull($invalid->plan('question'));
    }
}
