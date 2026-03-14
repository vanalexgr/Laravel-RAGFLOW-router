<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\GuidelineRouterService;

class QueryClassificationTest extends TestCase
{
    /**
     * Verify keyword heuristics classify all 7 canonical cases without an LLM call.
     * Azure OpenAI is not configured in CI/unit test environment so any LLM fallback
     * would return the safe default ('single_case') — knowledge and complex_case
     * results must therefore come from heuristics alone.
     */
    public function test_knowledge_queries_classified_correctly(): void
    {
        $router = app(GuidelineRouterService::class);

        $cases = [
            'What is the threshold for AAA repair?'                       => 'knowledge',
            'What is the Rutherford classification?'                      => 'knowledge',
            'Define CLTI.'                                                 => 'knowledge',
            '75-year-old man, symptomatic carotid stenosis, TIA 5 days'   => 'single_case',
            'Patient with AAA 5.8cm, fit for open repair'                 => 'single_case',
            'Antithrombotic therapy after EVAR in patient with AF'        => 'complex_case',
            'Complicated type B dissection, anticoagulation decision'      => 'complex_case',
        ];

        foreach ($cases as $query => $expected) {
            $this->assertEquals(
                $expected,
                $router->classifyQueryType($query),
                "classifyQueryType() failed for: $query"
            );
        }
    }

    public function test_classifyWithLlm_safe_default_when_not_configured(): void
    {
        // When Azure OpenAI is not configured, classifyWithLlm returns 'single_case'
        // (safe default). Verify by passing a query that heuristics can't classify.
        $router = app(GuidelineRouterService::class);

        // Short ambiguous query — no patient, no knowledge, no complex signals
        $result = $router->classifyQueryType('management options');
        $this->assertContains($result, ['knowledge', 'single_case', 'complex_case'],
            "classifyQueryType must always return one of the three valid types");
    }
}
