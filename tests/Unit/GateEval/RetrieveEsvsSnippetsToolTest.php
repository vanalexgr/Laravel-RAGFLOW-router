<?php

namespace Tests\Unit\GateEval;

use App\Ai\Gate\Tools\RetrieveEsvsSnippetsTool;
use App\Services\RetrievalService;
use Tests\TestCase;

class RetrieveEsvsSnippetsToolTest extends TestCase
{
    public function test_structured_retrieval_uses_requested_guideline_and_llm_tier(): void
    {
        $retrieval = new class extends RetrievalService
        {
            public array $requested = [];

            public array $configDuringRetrieval = [];

            public function retrieve(
                string $question,
                array $history = [],
                ?array $requestedKeys = null,
                ?string $citationQuestion = null,
            ): array
            {
                $this->requested = $requestedKeys ?? [];
                $this->configDuringRetrieval = [
                    config('ragflow.retrieval.top_k'),
                    config('ragflow.retrieval.citation_top_k'),
                    config('ragflow.single_case.top_k'),
                ];

                return [
                    'retrieval_query' => $question,
                    'duration_ms' => 12,
                    'llm_citation_chunks' => [[
                        'text' => 'rec_id:22; class:IIa; level:C; guideline_name:ESVS 2024 Clinical Practice Guidelines on Abdominal Aorto-Iliac Artery Aneurysms; rec_text_verbatim:Recommendation text',
                        'similarity' => 82.5,
                        'guideline' => 'AAA',
                    ]],
                    'llm_narrative_chunks' => [],
                ];
            }
        };

        $result = (new RetrieveEsvsSnippetsTool($retrieval))->retrieve(
            'abdominal_aortic_aneurysm',
            'focused query',
            false,
            24,
        );

        $this->assertSame(['abdominal_aortic_aneurysm'], $retrieval->requested);
        $this->assertSame([24, 16, 24], $retrieval->configDuringRetrieval);
        $this->assertSame(
            "[Recommendation 22 | Class IIa | Level C | abdominal_aortic_aneurysm]\nRecommendation text",
            $result['snippets'][0]['text'],
        );
        $this->assertStringNotContainsString('ESVS 2024 Clinical Practice Guidelines', $result['snippets'][0]['text']);
        $this->assertSame(82.5, $result['diagnostics']['max_similarity']);
    }

    public function test_gate_timeout_is_scoped_to_the_retrieval_client_and_restored_afterward(): void
    {
        config()->set('ragflow.request_timeout', 30);
        config()->set('ragflow.connect_timeout', 3);
        $retrieval = new class extends RetrievalService
        {
            public array $timeouts = [];

            public function retrieve(
                string $question,
                array $history = [],
                ?array $requestedKeys = null,
                ?string $citationQuestion = null,
            ): array
            {
                $this->timeouts = [config('ragflow.request_timeout'), config('ragflow.connect_timeout')];

                return ['duration_ms' => 1, 'llm_citation_chunks' => [], 'llm_narrative_chunks' => []];
            }
        };

        (new RetrieveEsvsSnippetsTool($retrieval))->retrieve('abdominal_aortic_aneurysm', 'q', false, 12, 7);

        $this->assertSame([7, 3], $retrieval->timeouts);
        $this->assertSame(30, config('ragflow.request_timeout'));
        $this->assertSame(3, config('ragflow.connect_timeout'));
    }
}
