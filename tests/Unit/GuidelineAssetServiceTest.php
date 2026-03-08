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

    public function test_it_prioritizes_question_specific_assets_over_generic_context(): void
    {
        Storage::fake('public');

        $manifest = [
            'abdominal_aortic_aneurysm' => [
                [
                    'id' => 'fig_followup_generic',
                    'kind' => 'figure',
                    'label' => 'Figure 39',
                    'caption' => 'Follow-up Algorithm After Standard EVAR',
                    'keywords' => ['EVAR', 'follow-up', 'endoleak', 'CTA'],
                    'path' => 'guideline_assets/abdominal_aortic_aneurysm/figures/fig_p070_039.png',
                ],
                [
                    'id' => 'fig_iliac_specific',
                    'kind' => 'figure',
                    'label' => 'Figure 46',
                    'caption' => 'Isolated Iliac Artery Aneurysm Classification Diagram',
                    'keywords' => ['iliac artery', 'aneurysm', 'classification'],
                    'path' => 'guideline_assets/abdominal_aortic_aneurysm/figures/fig_p082_046.png',
                ],
            ],
        ];

        $tmp = storage_path('app/guideline_assets/_test_manifest_iliac_priority.json');
        @mkdir(dirname($tmp), 0777, true);
        file_put_contents($tmp, json_encode($manifest));
        config()->set('guideline_assets.manifest_path', $tmp);
        config()->set('guideline_assets.disk', 'public');
        config()->set('guideline_assets.enable_keyword_fallback', true);
        config()->set('guideline_assets.max_assets', 3);

        $svc = app(GuidelineAssetService::class);

        $assets = $svc->findRelevantAssets(
            'What is the treatment and surveillance strategy for isolated iliac artery aneurysm?',
            [
                [
                    'content' => 'Post-EVAR follow-up includes CTA and surveillance for endoleak according to standard algorithm.',
                    'source_guideline' => 'Abdominal Aortic Aneurysm',
                ],
            ],
            [],
            [
                'abdominal_aortic_aneurysm' => ['id' => 'x', 'name' => 'Abdominal Aortic Aneurysm'],
            ],
            ['abdominal_aortic_aneurysm']
        );

        $this->assertNotEmpty($assets);
        $this->assertSame('fig_iliac_specific', $assets[0]['id']);
        $this->assertCount(1, $assets);
    }
}
