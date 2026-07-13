<?php

namespace Tests;

use App\Facades\RAGFlow;
use App\Services\RAGFlow\DatasetResource;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Mockery;

abstract class TestCase extends BaseTestCase
{
    protected function fakeExternalServices(?array $ragflowEnvelope = null): void
    {
        config()->set('prism.providers.azure.endpoint', 'https://azure.example.test');
        config()->set('prism.providers.azure.api_key', 'test-key');
        config()->set('prism.providers.azure.deployment', 'gpt-5-chat');
        config()->set('ragflow.use_bridge', true);

        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'selected' => ['abdominal_aortic_aneurysm'],
                        ]),
                    ],
                ]],
            ]),
        ]);

        $ragflowEnvelope ??= [
            'status' => 200,
            'degraded' => false,
            'narrative' => [
                'chunks' => [[
                    'id' => 'narrative-1',
                    'content' => 'Elective abdominal aortic aneurysm repair is considered at the guideline diameter threshold.',
                    'similarity' => 0.91,
                    '_source_guideline' => 'Abdominal Aortic Aneurysm',
                    '_source_dataset_id' => '7fb152c6ffbd11f0b2af32d89964721d',
                ]],
                'count' => 1,
            ],
            'citations' => [
                'chunks' => [[
                    'id' => 'citation-1',
                    'content' => 'rec_id:abdominal_aortic_aneurysm_R001; guideline_name:Abdominal Aortic Aneurysm; class:I; level:B; recommendation_text:Elective repair should be considered at the recommended threshold.',
                    'similarity' => 0.93,
                    '_source_guideline' => 'ESVS Recommendations',
                ]],
                'count' => 1,
            ],
            'errors' => ['narrative' => [], 'citation' => []],
        ];

        $datasets = Mockery::mock(DatasetResource::class);
        $datasets->shouldReceive('retrieveDual')->zeroOrMoreTimes()->andReturn($ragflowEnvelope);
        RAGFlow::shouldReceive('datasets')->zeroOrMoreTimes()->andReturn($datasets);
    }
}
