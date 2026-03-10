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

    public function test_it_limits_fallback_assets_to_guidelines_that_contributed_evidence(): void
    {
        Storage::fake('public');

        $manifest = [
            'carotid_vertebral' => [
                [
                    'id' => 'carotid_algo',
                    'kind' => 'figure',
                    'label' => 'Algorithm 1',
                    'caption' => 'Treatment algorithm for symptomatic carotid stenosis.',
                    'keywords' => ['symptomatic carotid stenosis', 'treatment algorithm'],
                    'path' => 'guideline_assets/carotid_vertebral/figures/algo-1.png',
                ],
            ],
            'abdominal_aortic_aneurysm' => [
                [
                    'id' => 'aaa_algo',
                    'kind' => 'figure',
                    'label' => 'Algorithm 1',
                    'caption' => 'Treatment algorithm for symptomatic carotid stenosis.',
                    'keywords' => ['symptomatic carotid stenosis', 'treatment algorithm'],
                    'path' => 'guideline_assets/abdominal_aortic_aneurysm/figures/algo-1.png',
                ],
            ],
        ];

        $tmp = storage_path('app/guideline_assets/_test_manifest_scope.json');
        @mkdir(dirname($tmp), 0777, true);
        file_put_contents($tmp, json_encode($manifest));
        config()->set('guideline_assets.manifest_path', $tmp);
        config()->set('guideline_assets.disk', 'public');
        config()->set('guideline_assets.enable_keyword_fallback', true);

        $svc = app(GuidelineAssetService::class);

        $assets = $svc->findRelevantAssets(
            'What is the treatment algorithm for symptomatic carotid stenosis?',
            [
                [
                    'content' => 'This section describes treatment for symptomatic carotid stenosis.',
                    'source_guideline' => 'Carotid & Vertebral',
                ],
            ],
            [],
            [
                'carotid_vertebral' => ['id' => 'x', 'name' => 'Carotid & Vertebral'],
                'abdominal_aortic_aneurysm' => ['id' => 'y', 'name' => 'Abdominal Aortic Aneurysm'],
            ]
        );

        $this->assertNotEmpty($assets);
        $this->assertSame('carotid_algo', $assets[0]['id']);
        $this->assertSame('carotid_vertebral', $assets[0]['guideline_key']);
        $this->assertCount(1, $assets);
    }

    public function test_it_prefers_management_flowcharts_for_treatment_questions(): void
    {
        Storage::fake('public');

        $manifest = [
            'carotid_vertebral' => [
                [
                    'id' => 'table_outcomes',
                    'kind' => 'table',
                    'subtype' => 'table',
                    'label' => 'Table 26',
                    'caption' => 'Comparison of RCT Outcomes for Carotid Endarterectomy (CEA)',
                    'description' => 'Outcome table for carotid endarterectomy trials.',
                    'keywords' => ['carotid endarterectomy', 'trial outcomes', 'stroke prevention'],
                    'path' => 'guideline_assets/carotid_vertebral/tables/table-26.png',
                ],
                [
                    'id' => 'table_stenosis',
                    'kind' => 'table',
                    'subtype' => 'table',
                    'label' => 'Table 53',
                    'caption' => 'Stroke Risk and Treatment Outcomes by Stenosis Severity',
                    'description' => 'Outcome table across degrees of carotid stenosis.',
                    'keywords' => ['asymptomatic carotid stenosis', 'stroke risk', 'treatment outcomes'],
                    'path' => 'guideline_assets/carotid_vertebral/tables/table-53.png',
                ],
                [
                    'id' => 'figure_management',
                    'kind' => 'figure',
                    'subtype' => 'flowchart',
                    'label' => 'Figure 4',
                    'caption' => 'Management of Carotid Stenosis in Average Risk Patients',
                    'description' => 'Flowchart covering asymptomatic and symptomatic carotid stenosis management with CEA and CAS recommendations.',
                    'keywords' => ['carotid stenosis', 'asymptomatic', 'symptomatic', 'CEA', 'CAS'],
                    'path' => 'guideline_assets/carotid_vertebral/figures/figure-4.png',
                    'aliases' => ['Algorithm 4'],
                ],
            ],
        ];

        $tmp = storage_path('app/guideline_assets/_test_manifest_management_priority.json');
        @mkdir(dirname($tmp), 0777, true);
        file_put_contents($tmp, json_encode($manifest));
        config()->set('guideline_assets.manifest_path', $tmp);
        config()->set('guideline_assets.disk', 'public');
        config()->set('guideline_assets.enable_keyword_fallback', true);
        config()->set('guideline_assets.max_assets', 3);

        $svc = app(GuidelineAssetService::class);

        $assets = $svc->findRelevantAssets(
            'How should I treat 90% asymptomatic ICA stenosis?',
            [
                [
                    'content' => 'Management decisions in asymptomatic carotid stenosis should consider stenosis severity and carotid intervention criteria.',
                    'source_guideline' => 'Carotid & Vertebral',
                ],
            ],
            [],
            [
                'carotid_vertebral' => ['id' => 'x', 'name' => 'Carotid & Vertebral'],
            ]
        );

        $this->assertNotEmpty($assets);
        $this->assertSame('figure_management', $assets[0]['id']);
    }

    public function test_it_filters_explicit_table_references_that_do_not_fit_management_intent(): void
    {
        Storage::fake('public');

        $manifest = [
            'carotid_vertebral' => [
                [
                    'id' => 'table_imaging',
                    'kind' => 'table',
                    'subtype' => 'table',
                    'label' => 'Table 9',
                    'caption' => 'Comparison of Imaging Modalities for Carotid Artery Disease',
                    'description' => 'Imaging table for carotid disease workup.',
                    'keywords' => ['carotid artery disease', 'imaging modalities', 'CTA', 'DUS'],
                    'path' => 'guideline_assets/carotid_vertebral/tables/table-9.png',
                ],
                [
                    'id' => 'figure_management',
                    'kind' => 'figure',
                    'subtype' => 'flowchart',
                    'label' => 'Figure 4',
                    'caption' => 'Management of Carotid Stenosis in Average Risk Patients',
                    'description' => 'Flowchart covering asymptomatic carotid stenosis management with carotid intervention recommendations.',
                    'keywords' => ['carotid stenosis', 'asymptomatic', 'CEA', 'CAS'],
                    'path' => 'guideline_assets/carotid_vertebral/figures/figure-4.png',
                    'aliases' => ['Algorithm 4'],
                ],
            ],
        ];

        $tmp = storage_path('app/guideline_assets/_test_manifest_explicit_management_filter.json');
        @mkdir(dirname($tmp), 0777, true);
        file_put_contents($tmp, json_encode($manifest));
        config()->set('guideline_assets.manifest_path', $tmp);
        config()->set('guideline_assets.disk', 'public');
        config()->set('guideline_assets.enable_keyword_fallback', true);
        config()->set('guideline_assets.max_assets', 3);

        $svc = app(GuidelineAssetService::class);

        $assets = $svc->findRelevantAssets(
            'How should I treat 90% asymptomatic ICA stenosis?',
            [
                [
                    'content' => 'Table 9 details imaging modalities for carotid artery disease and diagnostic workup.',
                    'source_guideline' => 'Carotid & Vertebral',
                ],
                [
                    'content' => 'Average-risk patients with asymptomatic carotid stenosis should be considered for carotid intervention based on stenosis severity.',
                    'source_guideline' => 'Carotid & Vertebral',
                ],
            ],
            [],
            [
                'carotid_vertebral' => ['id' => 'x', 'name' => 'Carotid & Vertebral'],
            ]
        );

        $this->assertNotEmpty($assets);
        $this->assertSame('figure_management', $assets[0]['id']);
        $this->assertNotSame('table_imaging', $assets[0]['id']);
    }
}
