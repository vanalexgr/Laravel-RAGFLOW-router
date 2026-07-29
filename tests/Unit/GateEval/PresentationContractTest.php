<?php

namespace Tests\Unit\GateEval;

use App\Ai\Gate\EvidenceStatusService;
use App\Ai\Gate\GateDecisionTail;
use App\Ai\Gate\GateWorkflowService;
use App\Ai\Gate\Grounding\GatePathwayWorker;
use App\Ai\Gate\Guard\PreOrientGuardService;
use App\Ai\Gate\Presentation\GateAssetPresenter;
use App\Ai\Gate\Presentation\GateCitationBuilder;
use App\Ai\Gate\Progress\RedisGateProgress;
use App\Ai\Gate\Routing\OrientRoutingPriorService;
use App\Ai\Gate\Tools\RetrieveEsvsSnippetsTool;
use App\Http\Controllers\ToolController;
use App\Services\ChangeDetectionService;
use App\Services\CoverageAssessmentService;
use App\Services\GuidelineAssetService;
use App\Services\PHIScrubberService;
use App\Services\PreRetrievalService;
use App\Services\RetrievalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

class PresentationContractTest extends TestCase
{
    public function test_prompt_numbers_and_emitted_citation_ids_are_identical(): void
    {
        $builder = new GateCitationBuilder;
        $numbered = $builder->numberForPrompt([
            'carotid' => [[
                'bucket' => 'citation',
                'text' => 'Recommendation text.',
                'source' => 'ESVS 2023 Carotid',
                'metadata' => ['recommendation_id' => '17'],
            ]],
            'aaa' => [[
                'bucket' => 'narrative',
                'text' => 'Narrative text.',
                'source' => 'ESVS 2024 AAA',
                'metadata' => [],
            ]],
        ]);

        $promptIds = [];
        foreach ($numbered as $snippets) {
            $promptIds = array_merge($promptIds, array_column($snippets, 'citation_id'));
        }

        $this->assertSame(['1', '2'], $promptIds);
        $this->assertSame($promptIds, array_column($builder->build($numbered), 'id'));
    }

    public function test_citations_preserve_bucket_provenance_and_canonical_metadata_verbatim(): void
    {
        $citations = (new GateCitationBuilder)->build([
            'carotid' => [
                [
                    'bucket' => 'citation',
                    'text' => "[Recommendation 17 | Class unparsed | Level C | carotid]\nVerbatim recommendation.",
                    'source' => 'ESVS 2023 Carotid',
                    'metadata' => [
                        'recommendation_id' => '17',
                        'recommendation_class' => 'unparsed',
                        'evidence_level' => 'C',
                        'guideline' => 'ESVS 2023 Carotid',
                    ],
                ],
                [
                    // Recommendation-shaped metadata in prose must not be
                    // promoted into a recommendation citation.
                    'bucket' => 'narrative',
                    'text' => 'Narrative discussion of procedural risk.',
                    'source' => 'ESVS 2023 Carotid',
                    'metadata' => [
                        'recommendation_id' => 'hallucinated-shape',
                        'recommendation_class' => 'III',
                        'evidence_level' => 'A',
                    ],
                ],
            ],
        ]);

        $recommendations = array_values(array_filter(
            $citations,
            static fn (array $citation): bool => $citation['kind'] === 'recommendation',
        ));

        $this->assertCount(1, $recommendations);
        $this->assertSame('17', $recommendations[0]['metadata']['recommendation_id']);
        $this->assertSame('unparsed', $recommendations[0]['metadata']['class']);
        $this->assertSame('C', $recommendations[0]['metadata']['level']);
        $this->assertStringContainsString('Verbatim recommendation.', $recommendations[0]['document']);
    }

    public function test_narrative_snippet_yields_a_narrative_citation(): void
    {
        $citation = (new GateCitationBuilder)->build([
            'aaa' => [[
                'bucket' => 'narrative',
                'text' => 'Narrative source excerpt.',
                'source' => 'ESVS 2024 AAA',
                'metadata' => ['guideline' => 'ESVS 2024 AAA'],
            ]],
        ])[0];

        $this->assertSame('narrative', $citation['kind']);
        $this->assertSame('Narrative source excerpt.', $citation['document']);
        $this->assertSame([
            'guideline' => 'ESVS 2024 AAA',
            'recommendation_id' => null,
            'class' => null,
            'level' => null,
        ], $citation['metadata']);
    }

    public function test_tail_keeps_only_resolved_markers_and_citations_one_to_one(): void
    {
        $builder = new GateCitationBuilder;
        $numbered = $builder->numberForPrompt([
            'carotid' => [[
                'bucket' => 'citation',
                'text' => 'Recommendation text.',
                'source' => 'ESVS 2023 Carotid',
                'metadata' => [
                    'recommendation_id' => '17',
                    'recommendation_class' => 'IIa',
                    'evidence_level' => 'B',
                ],
            ]],
            'aaa' => [[
                'bucket' => 'narrative',
                'text' => 'Narrative text.',
                'source' => 'ESVS 2024 AAA',
                'metadata' => [],
            ]],
        ]);

        $result = (new GateDecisionTail)->finalize([
            'unknowns' => [],
            'questions' => [],
            'guideline_grounded_answer' => 'Grounded recommendation [1].',
            'interpretive_frame' => 'Context from narrative [2].',
        ], [], [], $builder->build($numbered));

        preg_match_all('/\[(\d+)\]/', $result['answer_markdown'], $markers);
        $markerIds = array_values(array_unique($markers[1]));

        $this->assertSame(['1', '2'], $markerIds);
        $this->assertSame($markerIds, array_column($result['citations'], 'id'));
    }

    public function test_evidence_used_lists_exactly_cited_sources_with_canonical_strength(): void
    {
        $builder = new GateCitationBuilder;
        $numbered = $builder->numberForPrompt([
            'carotid' => [
                [
                    'bucket' => 'citation',
                    'text' => 'Cited recommendation.',
                    'source' => 'ESVS 2023 Carotid',
                    'metadata' => [
                        'recommendation_id' => '17',
                        'recommendation_class' => 'Iia',
                        'evidence_level' => 'b',
                    ],
                ],
                [
                    'bucket' => 'citation',
                    'text' => 'Uncited recommendation.',
                    'source' => 'ESVS 2023 Carotid',
                    'metadata' => [
                        'recommendation_id' => '18',
                        'recommendation_class' => 'I',
                        'evidence_level' => 'A',
                    ],
                ],
                [
                    'bucket' => 'narrative',
                    'text' => 'Cited narrative.',
                    'source' => 'ESVS 2023 Carotid',
                    'metadata' => [],
                ],
            ],
        ]);

        $result = (new GateDecisionTail)->finalize([
            'unknowns' => [],
            'questions' => [],
            'guideline_grounded_answer' => 'Use the recommendation [1] with context [3].',
            'interpretive_frame' => '',
        ], [], [], $builder->build($numbered));

        $this->assertStringContainsString(
            '- [1] **Recommendation 17** — Class IIa; Level B; ESVS 2023 Carotid',
            $result['answer_markdown'],
        );
        $this->assertStringContainsString(
            '- [3] _Narrative source_ — ESVS 2023 Carotid',
            $result['answer_markdown'],
        );
        $this->assertStringNotContainsString('Recommendation 18', $result['answer_markdown']);
        $this->assertSame(['1', '3'], array_column($result['citations'], 'id'));
    }

    public function test_unparsed_class_and_level_survive_in_evidence_used(): void
    {
        $builder = new GateCitationBuilder;
        $numbered = $builder->numberForPrompt([
            'clti' => [[
                'bucket' => 'citation',
                'text' => 'Recommendation text.',
                'source' => 'GVG CLTI',
                'metadata' => [
                    'recommendation_id' => '4.1',
                    'recommendation_class' => 'unparsed',
                    'evidence_level' => 'unparsed',
                ],
            ]],
        ]);

        $result = (new GateDecisionTail)->finalize([
            'unknowns' => [],
            'questions' => [],
            'guideline_grounded_answer' => 'Grounded [1].',
            'interpretive_frame' => '',
        ], [], [], $builder->build($numbered));

        $this->assertStringContainsString(
            'Recommendation 4.1** — Class unparsed; Level unparsed; GVG CLTI',
            $result['answer_markdown'],
        );
    }

    public function test_progress_endpoint_returns_emissions_in_order_and_flips_done(): void
    {
        $redis = new class
        {
            public array $values = [];
            public array $lists = [];

            public function del(string ...$keys): int
            {
                foreach ($keys as $key) {
                    unset($this->values[$key], $this->lists[$key]);
                }

                return count($keys);
            }

            public function setex(string $key, int $ttl, string $value): bool
            {
                $this->values[$key] = $value;

                return true;
            }

            public function rpush(string $key, string $value): int
            {
                $this->lists[$key][] = $value;

                return count($this->lists[$key]);
            }

            public function expire(string $key, int $ttl): bool
            {
                return true;
            }

            public function lrange(string $key, int $start, int $stop): array
            {
                return $this->lists[$key] ?? [];
            }

            public function get(string $key): ?string
            {
                return $this->values[$key] ?? null;
            }
        };
        Redis::swap($redis);

        $scrubber = new PHIScrubberService;
        $channel = new RedisGateProgress('request-123', $scrubber, 300);
        $channel->emit('orient', 'Framing the case…');
        $channel->emit('retrieve', 'Searching carotid guidance…', ['guideline' => 'carotid']);

        $before = $this->controller()->gateProgress('request-123')->getData(true);
        $this->assertFalse($before['done']);
        $this->assertSame(
            ['Framing the case…', 'Searching carotid guidance…'],
            array_column($before['progress'], 'message'),
        );

        $channel->complete();
        $after = $this->controller()->gateProgress('request-123')->getData(true);
        $this->assertTrue($after['done']);
        $this->assertSame(['orient', 'retrieve'], array_column($after['progress'], 'stage'));
    }

    public function test_request_without_request_id_works_without_a_progress_channel(): void
    {
        Redis::swap(new class
        {
            public function __call(string $method, array $arguments): never
            {
                throw new \RuntimeException('Redis progress must not be used without request_id.');
            }
        });

        $assetService = Mockery::mock(GuidelineAssetService::class);
        $assetService->shouldNotReceive('findRelevantAssets');
        $response = $this->controller()->clinicalGate(
            Request::create('/api/v1/clinical-gate', 'POST', [
                'question' => 'How do I configure Docker?',
            ]),
            $this->workflow(),
            new GateAssetPresenter($assetService),
            new PHIScrubberService,
        )->getData(true);

        $this->assertSame([], $response['progress']);
        $this->assertSame([], $response['citations']);
        $this->assertSame([], $response['assets']);
        $this->assertSame([], $response['degradation']);
        $this->assertArrayHasKey('answer_markdown', $response);
    }

    private function controller(): ToolController
    {
        return new ToolController(
            Mockery::mock(RetrievalService::class),
            Mockery::mock(GuidelineAssetService::class),
            Mockery::mock(PreRetrievalService::class),
            Mockery::mock(ChangeDetectionService::class),
            Mockery::mock(CoverageAssessmentService::class),
        );
    }

    private function workflow(): GateWorkflowService
    {
        $retrieval = new class extends RetrievalService
        {
            public function retrieve(
                string $question,
                array $history = [],
                ?array $requestedKeys = null,
                ?string $citationQuestion = null,
            ): array {
                throw new \RuntimeException('Guarded request must not retrieve.');
            }
        };

        return new GateWorkflowService(
            new PreOrientGuardService,
            new OrientRoutingPriorService,
            new GatePathwayWorker(new RetrieveEsvsSnippetsTool($retrieval)),
            new EvidenceStatusService,
            new GateDecisionTail,
            new PHIScrubberService,
        );
    }
}
