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
                    config('ragflow.retrieval.authoritative_citation_document_scope'),
                ];

                return [
                    'retrieval_query' => $question,
                    'duration_ms' => 12,
                    'llm_citation_chunks' => [[
                        'text' => 'rec_id:22; class:IIa; level:C; guideline_name:ESVS 2024 Clinical Practice Guidelines on Abdominal Aorto-Iliac Artery Aneurysms; rec_text_verbatim:Recommendation text',
                        'similarity' => 82.5,
                        'guideline' => 'AAA',
                        'document_id' => '40a8b701ff8111f080ad32d89964721d',
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
        $this->assertSame([24, 16, 24, true], $retrieval->configDuringRetrieval);
        $this->assertSame(
            "[Recommendation 22 | Class IIa | Level C | abdominal_aortic_aneurysm]\nRecommendation text",
            $result['snippets'][0]['text'],
        );
        $this->assertStringNotContainsString('ESVS 2024 Clinical Practice Guidelines', $result['snippets'][0]['text']);
        $this->assertSame(82.5, $result['diagnostics']['max_similarity']);
        // Bucket labelling must survive into the snippet and the diagnostics, or
        // the downstream citation reservation has nothing to reserve on.
        $this->assertSame('citation', $result['snippets'][0]['bucket']);
        $this->assertTrue($result['snippets'][0]['provenance_verified']);
        $this->assertSame(1, $result['diagnostics']['citation_count']);
        $this->assertSame(0, $result['diagnostics']['narrative_available']);
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

    public function test_multi_query_unions_text_deduplicates_and_preserves_citation_quota(): void
    {
        config()->set('gate-v2.retrieval.citation_multi_query', true);
        config()->set('gate-v2.retrieval.citation_multi_query_max', 2);
        config()->set('gate-v2.retrieval.citation_share', 0.4);
        $retrieval = new class extends RetrievalService
        {
            public array $citationQuestions = [];

            public array $timeouts = [];

            public function retrieve(
                string $question,
                array $history = [],
                ?array $requestedKeys = null,
                ?string $citationQuestion = null,
            ): array {
                $this->citationQuestions[] = $citationQuestion;
                $this->timeouts[] = [
                    config('ragflow.request_timeout'),
                    config('ragflow.connect_timeout'),
                ];
                $texts = count($this->citationQuestions) === 1
                    ? ['duplicate recommendation', 'recommendation A', 'recommendation B']
                    : ['duplicate recommendation', 'recommendation C', 'recommendation D'];

                return [
                    'duration_ms' => 1,
                    'llm_citation_chunks' => array_map(
                        static fn (string $text): array => [
                            'text' => $text,
                            'similarity' => 0.8,
                            'document_id' => '31f83c34052911f18ceb32d89964721d',
                        ],
                        $texts,
                    ),
                    'llm_narrative_chunks' => array_map(
                        static fn (int $i): array => ['text' => "narrative {$i}", 'similarity' => 0.7],
                        range(1, 10),
                    ),
                ];
            }
        };

        $result = (new RetrieveEsvsSnippetsTool($retrieval))->retrieve(
            'clti',
            'narrative query',
            false,
            12,
            20,
            'legacy concatenated query',
            ['antithrombotic therapy after vein bypass', 'critical limb-threatening ischaemia'],
        );

        $this->assertSame(
            ['antithrombotic therapy after vein bypass', 'critical limb-threatening ischaemia'],
            $retrieval->citationQuestions,
        );
        $this->assertSame([[15, 3], [15, 3]], $retrieval->timeouts);
        $this->assertSame(5, $result['diagnostics']['citation_available']);
        $this->assertSame(4, $result['diagnostics']['citation_count']);
        $this->assertCount(10, $result['snippets']);
        $this->assertCount(1, array_filter(
            $result['snippets'],
            static fn (array $snippet): bool => str_contains($snippet['text'], 'duplicate recommendation'),
        ));
    }

    public function test_disabled_flag_uses_only_the_legacy_single_query(): void
    {
        config()->set('gate-v2.retrieval.citation_multi_query', false);
        $retrieval = new class extends RetrievalService
        {
            public array $citationQuestions = [];

            public function retrieve(
                string $question,
                array $history = [],
                ?array $requestedKeys = null,
                ?string $citationQuestion = null,
            ): array {
                $this->citationQuestions[] = $citationQuestion;

                return ['duration_ms' => 1, 'llm_citation_chunks' => [], 'llm_narrative_chunks' => []];
            }
        };

        (new RetrieveEsvsSnippetsTool($retrieval))->retrieve(
            'clti',
            'narrative query',
            false,
            12,
            20,
            'legacy concatenated query',
            ['core one', 'core two'],
        );

        $this->assertSame(['legacy concatenated query'], $retrieval->citationQuestions);
    }

    public function test_cross_guideline_document_is_discarded_and_counted_before_stamping(): void
    {
        $retrieval = new class extends RetrievalService
        {
            public function retrieve(
                string $question,
                array $history = [],
                ?array $requestedKeys = null,
                ?string $citationQuestion = null,
            ): array {
                return [
                    'duration_ms' => 1,
                    'llm_citation_chunks' => [[
                        'text' => 'rec_id:39; rec_text_verbatim:Antithrombotic recommendation',
                        'document_id' => '40795f9affad11f0a4d332d89964721d',
                    ]],
                    'llm_narrative_chunks' => [],
                ];
            }
        };

        $result = (new RetrieveEsvsSnippetsTool($retrieval))->retrieve('clti', 'q');

        $this->assertSame([], $result['snippets']);
        $this->assertSame(1, $result['diagnostics']['provenance_mismatch']);
        $this->assertSame(0, $result['diagnostics']['provenance_unverifiable']);
        $this->assertStringNotContainsString(
            'clti',
            implode("\n", array_column($result['snippets'], 'text')),
        );
    }

    public function test_unlabelled_citation_is_kept_and_counted_as_unverifiable(): void
    {
        $retrieval = new class extends RetrievalService
        {
            public function retrieve(
                string $question,
                array $history = [],
                ?array $requestedKeys = null,
                ?string $citationQuestion = null,
            ): array {
                return [
                    'duration_ms' => 1,
                    'llm_citation_chunks' => [['text' => 'Recommendation with no document identity']],
                    'llm_narrative_chunks' => [],
                ];
            }
        };

        $result = (new RetrieveEsvsSnippetsTool($retrieval))->retrieve('clti', 'q');

        $this->assertCount(1, $result['snippets']);
        $this->assertSame(
            'Recommendation with no document identity',
            $result['snippets'][0]['text'],
        );
        $this->assertFalse($result['snippets'][0]['provenance_verified']);
        $this->assertSame(0, $result['diagnostics']['provenance_mismatch']);
        $this->assertSame(1, $result['diagnostics']['provenance_unverifiable']);
        $this->assertTrue($result['diagnostics']['provenance_guard_degraded']);
    }

    public function test_one_unlabelled_citation_is_kept_alongside_a_verified_match(): void
    {
        $retrieval = new class extends RetrievalService
        {
            public function retrieve(
                string $question,
                array $history = [],
                ?array $requestedKeys = null,
                ?string $citationQuestion = null,
            ): array {
                return [
                    'duration_ms' => 1,
                    'llm_citation_chunks' => [
                        [
                            'text' => 'rec_id:1; rec_text_verbatim:Verified CLTI recommendation',
                            'document_id' => '31f83c34052911f18ceb32d89964721d',
                        ],
                        ['text' => 'Recommendation with no document identity'],
                    ],
                    'llm_narrative_chunks' => [],
                ];
            }
        };

        $result = (new RetrieveEsvsSnippetsTool($retrieval))->retrieve('clti', 'q');

        $this->assertCount(2, $result['snippets']);
        $this->assertSame([true, false], array_column($result['snippets'], 'provenance_verified'));
        $this->assertSame(0, $result['diagnostics']['provenance_mismatch']);
        $this->assertSame(1, $result['diagnostics']['provenance_unverifiable']);
        $this->assertFalse($result['diagnostics']['provenance_guard_degraded']);
    }

    public function test_all_unlabelled_citations_degrade_guard_to_pass_through(): void
    {
        $retrieval = new class extends RetrievalService
        {
            public function retrieve(
                string $question,
                array $history = [],
                ?array $requestedKeys = null,
                ?string $citationQuestion = null,
            ): array {
                return [
                    'duration_ms' => 1,
                    'llm_citation_chunks' => [
                        ['text' => 'First unlabelled recommendation'],
                        ['text' => 'Second unlabelled recommendation'],
                    ],
                    'llm_narrative_chunks' => [],
                ];
            }
        };

        $result = (new RetrieveEsvsSnippetsTool($retrieval))->retrieve('clti', 'q');

        $this->assertCount(2, $result['snippets']);
        $this->assertSame(2, $result['diagnostics']['citation_available']);
        $this->assertSame(0, $result['diagnostics']['provenance_mismatch']);
        $this->assertSame(2, $result['diagnostics']['provenance_unverifiable']);
        $this->assertTrue($result['diagnostics']['provenance_guard_degraded']);
    }

    public function test_legacy_raw_filter_behavior_is_unchanged_when_authoritative_flag_is_off(): void
    {
        config()->set('ragflow.retrieval.authoritative_citation_document_scope', false);
        $retrieval = new class extends RetrievalService
        {
            public function filterForTest(array $chunks): array
            {
                return $this->filterRawChunksToSelectedGuidelines(
                    $chunks,
                    ['clti'],
                    ['Chronic Limb-Threatening Ischemia'],
                    'citation',
                );
            }
        };
        $unlabelled = ['content' => 'Recommendation with sparse metadata'];

        $this->assertSame([$unlabelled], $retrieval->filterForTest([$unlabelled]));
    }

    public function test_authoritative_raw_filter_rejects_mismatch_but_keeps_missing_id(): void
    {
        config()->set('ragflow.retrieval.authoritative_citation_document_scope', true);
        $retrieval = new class extends RetrievalService
        {
            public function filterForTest(array $chunks): array
            {
                return $this->filterRawChunksToSelectedGuidelines(
                    $chunks,
                    ['clti'],
                    ['Chronic Limb-Threatening Ischemia'],
                    'citation',
                );
            }
        };
        $matching = [
            'content' => 'CLTI recommendation',
            'document_id' => '31f83c34052911f18ceb32d89964721d',
        ];
        $wrong = [
            'content' => 'Antithrombotic recommendation',
            'document_id' => '40795f9affad11f0a4d332d89964721d',
        ];
        $unlabelled = ['content' => 'Recommendation with sparse metadata'];

        $this->assertSame(
            [$matching, $unlabelled],
            $retrieval->filterForTest([$matching, $wrong, $unlabelled]),
        );
    }

    public function test_citation_formatter_propagates_canonical_document_id(): void
    {
        $retrieval = new class extends RetrievalService
        {
            public function formatForTest(array $chunks): array
            {
                return $this->formatChunks($chunks, 'citation');
            }
        };

        $formatted = $retrieval->formatForTest([[
            'content' => 'recommendation_text:Use treatment',
            'doc_id' => '31f83c34052911f18ceb32d89964721d',
        ]]);

        $this->assertSame(
            '31f83c34052911f18ceb32d89964721d',
            $formatted[0]['document_id'],
        );
    }
}
