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

            public function retrieve(string $question, array $history = [], ?array $requestedKeys = null): array
            {
                $this->requested = $requestedKeys ?? [];

                return [
                    'retrieval_query' => $question,
                    'duration_ms' => 12,
                    'llm_citation_chunks' => [[
                        'text' => 'Recommendation text',
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
        );

        $this->assertSame(['abdominal_aortic_aneurysm'], $retrieval->requested);
        $this->assertSame('Recommendation text', $result['snippets'][0]['text']);
        $this->assertSame(82.5, $result['diagnostics']['max_similarity']);
    }
}
