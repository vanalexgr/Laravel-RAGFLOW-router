<?php

namespace Tests\Unit;

use App\Services\GuidelineAssetService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GuidelineAssetServiceTest extends TestCase
{
    public function test_it_matches_explicit_figure_reference_by_label(): void
    {
        Storage::fake('public');

        $manifest = [
            'carotid_vertebral' => [
                [
                    'id' => 'carotid_vertebral_figure_2',
                    'kind' => 'figure',
                    'label' => 'Figure 2',
                    'caption' => 'Diagnostic algorithm.',
                    'path' => 'guideline_assets/carotid_vertebral/figures/figure-2.png',
                    'aliases' => ['Fig. 2'],
                ],
            ],
        ];

        $tmp = storage_path('app/guideline_assets/_test_manifest.json');
        @mkdir(dirname($tmp), 0777, true);
        file_put_contents($tmp, json_encode($manifest));
        config()->set('guideline_assets.manifest_path', $tmp);
        config()->set('guideline_assets.disk', 'public');

        $svc = app(GuidelineAssetService::class);

        $assets = $svc->findRelevantAssets(
            'Question',
            [
                [
                    'content' => 'For management, see Figure 2 for the diagnostic algorithm.',
                    'source_guideline' => 'Carotid & Vertebral',
                ],
            ],
            [],
            [
                'carotid_vertebral' => ['id' => 'x', 'name' => 'Carotid & Vertebral'],
            ]
        );

        $this->assertNotEmpty($assets);
        $this->assertSame('carotid_vertebral_figure_2', $assets[0]['id']);
        $this->assertSame('carotid_vertebral', $assets[0]['guideline_key']);
        $this->assertArrayHasKey('url', $assets[0]);
    }

    public function test_it_can_fallback_to_keyword_overlap_when_no_explicit_reference(): void
    {
        Storage::fake('public');

        $manifest = [
            'carotid_vertebral' => [
                [
                    'id' => 'algo_1',
                    'kind' => 'figure',
                    'label' => 'Algorithm 1',
                    'caption' => 'Treatment algorithm for symptomatic carotid stenosis.',
                    'keywords' => ['treatment algorithm', 'symptomatic carotid stenosis'],
                    'path' => 'guideline_assets/carotid_vertebral/figures/algo-1.png',
                ],
            ],
        ];

        $tmp = storage_path('app/guideline_assets/_test_manifest_kw.json');
        @mkdir(dirname($tmp), 0777, true);
        file_put_contents($tmp, json_encode($manifest));
        config()->set('guideline_assets.manifest_path', $tmp);
        config()->set('guideline_assets.disk', 'public');
        config()->set('guideline_assets.enable_keyword_fallback', true);

        $svc = app(GuidelineAssetService::class);

        $assets = $svc->findRelevantAssets(
            'symptomatic carotid stenosis treatment approach',
            [
                [
                    'content' => 'This section describes the treatment algorithm for symptomatic carotid stenosis.',
                    'source_guideline' => 'Carotid & Vertebral',
                ],
            ],
            [],
            [
                'carotid_vertebral' => ['id' => 'x', 'name' => 'Carotid & Vertebral'],
            ]
        );

        $this->assertNotEmpty($assets);
        $this->assertSame('algo_1', $assets[0]['id']);
    }
}

