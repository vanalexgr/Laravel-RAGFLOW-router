<?php

namespace Tests\Unit;

use App\Services\BridgeRerankService;
use App\Services\GuidelineAssetService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GuidelineAssetServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('guideline_assets.rerank.enabled', false);
    }

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

    public function test_it_avoids_thoracic_trauma_assets_for_carotid_trauma_management(): void
    {
        Storage::fake('public');

        $manifest = [
            'vascular_trauma' => [
                [
                    'id' => 'tbl_carotid_injury',
                    'kind' => 'table',
                    'subtype' => 'table',
                    'label' => 'Table 27',
                    'caption' => 'Management of Carotid and Vertebral Artery Injuries by ESVS Grade',
                    'description' => 'Recommended treatment options for blunt carotid and vertebral artery injury, including Grade 3 pseudoaneurysm.',
                    'keywords' => ['carotid artery injury', 'vertebral artery injury', 'pseudoaneurysm', 'ESVS grade'],
                    'path' => 'guideline_assets/vascular_trauma/tables/tbl_p017_027.png',
                ],
                [
                    'id' => 'tbl_btai_grade',
                    'kind' => 'table',
                    'subtype' => 'table',
                    'label' => 'Table 48',
                    'caption' => 'Management of Blunt Thoracic Aortic Injury by Grade',
                    'description' => 'Suggested management strategies for blunt thoracic aortic injury.',
                    'keywords' => ['BTAI', 'thoracic aortic injury', 'aortic trauma'],
                    'path' => 'guideline_assets/vascular_trauma/tables/tbl_p024_048.png',
                ],
                [
                    'id' => 'fig_btai_followup',
                    'kind' => 'figure',
                    'subtype' => 'flowchart',
                    'label' => 'Figure 4',
                    'caption' => 'Follow-Up Imaging Protocol for BTAI',
                    'description' => 'Imaging surveillance after blunt thoracic aortic injury repair.',
                    'keywords' => ['BTAI', 'thoracic aortic injury', 'follow up imaging'],
                    'path' => 'guideline_assets/vascular_trauma/figures/fig_p027_004.png',
                ],
            ],
            'carotid_vertebral' => [
                [
                    'id' => 'fig_carotid_stenting',
                    'kind' => 'figure',
                    'subtype' => 'diagram',
                    'label' => 'Figure 27',
                    'caption' => 'Stent Deployment in the Carotid Artery',
                    'description' => 'Illustration of carotid artery stent deployment across a lesion.',
                    'keywords' => ['carotid artery stenting', 'stent deployment', 'carotid artery'],
                    'path' => 'guideline_assets/carotid_vertebral/figures/fig_p085_027.png',
                ],
            ],
        ];

        $tmp = storage_path('app/guideline_assets/_test_manifest_carotid_trauma_priority.json');
        @mkdir(dirname($tmp), 0777, true);
        file_put_contents($tmp, json_encode($manifest));
        config()->set('guideline_assets.manifest_path', $tmp);
        config()->set('guideline_assets.disk', 'public');
        config()->set('guideline_assets.enable_keyword_fallback', true);
        config()->set('guideline_assets.max_assets', 3);

        $svc = app(GuidelineAssetService::class);

        $assets = $svc->findRelevantAssets(
            'Child with grade 3 blunt carotid injury and pseudoaneurysm. What is the management strategy and would you consider stenting?',
            [
                [
                    'content' => 'Blunt carotid artery injury with pseudoaneurysm may require antithrombotic therapy and selective intervention.',
                    'source_guideline' => 'Vascular Trauma',
                ],
                [
                    'content' => 'Carotid artery stenting can be considered in selected cases of carotid injury.',
                    'source_guideline' => 'Carotid & Vertebral',
                ],
            ],
            [],
            [
                'vascular_trauma' => ['id' => 'x', 'name' => 'Vascular Trauma'],
                'carotid_vertebral' => ['id' => 'y', 'name' => 'Carotid & Vertebral'],
            ]
        );

        $this->assertNotEmpty($assets);
        $assetIds = array_column($assets, 'id');

        $this->assertSame('tbl_carotid_injury', $assetIds[0]);
        $this->assertContains('fig_carotid_stenting', $assetIds);
        $this->assertNotContains('tbl_btai_grade', $assetIds);
        $this->assertNotContains('fig_btai_followup', $assetIds);
    }

    public function test_it_uses_retrieved_context_to_keep_follow_up_assets_in_the_same_territory(): void
    {
        Storage::fake('public');

        $manifest = [
            'vascular_trauma' => [
                [
                    'id' => 'tbl_carotid_injury',
                    'kind' => 'table',
                    'subtype' => 'table',
                    'label' => 'Table 27',
                    'caption' => 'Management of Carotid and Vertebral Artery Injuries by ESVS Grade',
                    'description' => 'Recommended treatment options for blunt carotid and vertebral artery injury, including pseudoaneurysm.',
                    'keywords' => ['carotid artery injury', 'vertebral artery injury', 'pseudoaneurysm', 'ESVS grade'],
                    'path' => 'guideline_assets/vascular_trauma/tables/tbl_p017_027.png',
                ],
                [
                    'id' => 'fig_btai_followup',
                    'kind' => 'figure',
                    'subtype' => 'flowchart',
                    'label' => 'Figure 4',
                    'caption' => 'Follow-Up Imaging Protocol for BTAI',
                    'description' => 'Imaging surveillance after blunt thoracic aortic injury repair.',
                    'keywords' => ['BTAI', 'thoracic aortic injury', 'follow up imaging'],
                    'path' => 'guideline_assets/vascular_trauma/figures/fig_p027_004.png',
                ],
            ],
            'carotid_vertebral' => [
                [
                    'id' => 'fig_carotid_stenting',
                    'kind' => 'figure',
                    'subtype' => 'diagram',
                    'label' => 'Figure 27',
                    'caption' => 'Stent Deployment in the Carotid Artery',
                    'description' => 'Illustration of carotid artery stent deployment for carotid artery lesions.',
                    'keywords' => ['carotid artery stenting', 'stent deployment', 'carotid artery'],
                    'path' => 'guideline_assets/carotid_vertebral/figures/fig_p085_027.png',
                ],
            ],
        ];

        $tmp = storage_path('app/guideline_assets/_test_manifest_followup_context_priority.json');
        @mkdir(dirname($tmp), 0777, true);
        file_put_contents($tmp, json_encode($manifest));
        config()->set('guideline_assets.manifest_path', $tmp);
        config()->set('guideline_assets.disk', 'public');
        config()->set('guideline_assets.enable_keyword_fallback', true);
        config()->set('guideline_assets.max_assets', 3);

        $svc = app(GuidelineAssetService::class);

        $assets = $svc->findRelevantAssets(
            'Would you consider stenting given that this is a child?',
            [
                [
                    'content' => 'This patient has a blunt carotid artery injury that developed a pseudoaneurysm on follow-up imaging.',
                    'source_guideline' => 'Vascular Trauma',
                ],
                [
                    'content' => 'Selective carotid artery stenting may be considered for carotid pseudoaneurysm in carefully chosen cases.',
                    'source_guideline' => 'Carotid & Vertebral',
                ],
            ],
            [],
            [
                'vascular_trauma' => ['id' => 'x', 'name' => 'Vascular Trauma'],
                'carotid_vertebral' => ['id' => 'y', 'name' => 'Carotid & Vertebral'],
            ]
        );

        $this->assertNotEmpty($assets);
        $assetIds = array_column($assets, 'id');

        $this->assertSame('fig_carotid_stenting', $assetIds[0]);
        $this->assertContains('tbl_carotid_injury', $assetIds);
        $this->assertNotContains('fig_btai_followup', $assetIds);
    }

    public function test_it_prefers_management_algorithms_over_imaging_workflows_for_definitive_vgei_treatment_questions(): void
    {
        Storage::fake('public');

        $manifest = [
            'vascular_graft_infections' => [
                [
                    'id' => 'fig_imaging_workflow',
                    'kind' => 'figure',
                    'subtype' => 'flowchart',
                    'label' => 'Figure 3',
                    'caption' => 'Imaging Workflow for Suspected VGEI',
                    'description' => 'Diagnostic imaging pathway for suspected vascular graft or endograft infection.',
                    'keywords' => ['VGEI', 'imaging', 'CTA', 'PET', 'workflow'],
                    'path' => 'guideline_assets/vascular_graft_infections/figures/fig_p012_003.png',
                ],
                [
                    'id' => 'fig_thoracic_management',
                    'kind' => 'figure',
                    'subtype' => 'flowchart',
                    'label' => 'Figure 6',
                    'caption' => 'Algorithm for Thoracic Aortic Graft/Endograft Infection Management',
                    'description' => 'Decision-making algorithm for thoracic aortic graft infection including acute bleeding, fistula, explantation, and reconstruction.',
                    'keywords' => ['thoracic aortic graft', 'endograft infection', 'fistula management', 'graft explantation', 'reconstruction'],
                    'path' => 'guideline_assets/vascular_graft_infections/figures/fig_p019_006.png',
                ],
                [
                    'id' => 'fig_aortic_management',
                    'kind' => 'figure',
                    'subtype' => 'flowchart',
                    'label' => 'Figure 7',
                    'caption' => 'Algorithm for Managing Aortic Vascular Graft/Endograft Infection',
                    'description' => 'Treatment algorithm covering acute bleeding, operative fitness, graft explantation, and vascular reconstruction.',
                    'keywords' => ['aortic graft infection', 'treatment algorithm', 'graft explantation', 'vascular reconstruction'],
                    'path' => 'guideline_assets/vascular_graft_infections/figures/fig_p030_007.png',
                ],
            ],
        ];

        $tmp = storage_path('app/guideline_assets/_test_manifest_vgei_management_priority.json');
        @mkdir(dirname($tmp), 0777, true);
        file_put_contents($tmp, json_encode($manifest));
        config()->set('guideline_assets.manifest_path', $tmp);
        config()->set('guideline_assets.disk', 'public');
        config()->set('guideline_assets.enable_keyword_fallback', true);
        config()->set('guideline_assets.max_assets', 2);

        $svc = app(GuidelineAssetService::class);

        $assets = $svc->findRelevantAssets(
            'What is the definite treatment after TEVAR is in place for aorto-oesophageal fistula with infected thoracic endograft?',
            [
                [
                    'content' => 'For patients with aorto-oesophageal fistula complicating thoracic graft or endograft infection, explantation of infected material, repair of the oesophagus, and coverage with viable tissue is recommended as definitive treatment.',
                    'source_guideline' => 'Management of Vascular Graft and Endograft Infections',
                ],
                [
                    'content' => 'In active bleeding, initial TEVAR may be used as a bridge to definitive treatment before graft explantation and reconstruction.',
                    'source_guideline' => 'Management of Vascular Graft and Endograft Infections',
                ],
                [
                    'content' => 'CTA and PET imaging may support diagnostic workup for suspected graft infection.',
                    'source_guideline' => 'Management of Vascular Graft and Endograft Infections',
                ],
            ],
            [],
            [
                'vascular_graft_infections' => ['id' => 'x', 'name' => 'Management of Vascular Graft and Endograft Infections'],
            ]
        );

        $this->assertCount(2, $assets);
        $assetIds = array_column($assets, 'id');
        $this->assertSame('fig_thoracic_management', $assetIds[0]);
        $this->assertSame(['fig_thoracic_management', 'fig_aortic_management'], $assetIds);
        $this->assertNotContains('fig_imaging_workflow', $assetIds);
    }

    public function test_it_can_use_reranker_to_promote_complex_aaa_assets_from_the_shortlist(): void
    {
        Storage::fake('public');

        $manifest = [
            'abdominal_aortic_aneurysm' => [
                [
                    'id' => 'fig_compartment',
                    'kind' => 'figure',
                    'subtype' => 'flowchart',
                    'label' => 'Figure 30',
                    'caption' => 'Management Algorithm for Abdominal Compartment Syndrome in AAA Repair',
                    'keywords' => ['aaa repair', 'abdominal compartment syndrome', 'management algorithm'],
                    'path' => 'guideline_assets/abdominal_aortic_aneurysm/figures/fig_p055_030.png',
                ],
                [
                    'id' => 'fig_repair_methods',
                    'kind' => 'figure',
                    'subtype' => 'diagram',
                    'label' => 'Figure 56',
                    'caption' => 'Abdominal Aortic Aneurysm Repair Methods',
                    'keywords' => ['aaa repair', 'open repair', 'endovascular repair'],
                    'path' => 'guideline_assets/abdominal_aortic_aneurysm/figures/fig_p096_056.png',
                ],
                [
                    'id' => 'fig_complex_aaa',
                    'kind' => 'figure',
                    'subtype' => 'diagram',
                    'label' => 'Figure 41',
                    'caption' => 'Anatomical Classification of Complex AAAs',
                    'keywords' => ['juxtarenal aneurysm', 'pararenal aneurysm', 'complex aaa', 'classification'],
                    'path' => 'guideline_assets/abdominal_aortic_aneurysm/figures/fig_p074_041.png',
                ],
            ],
        ];

        $tmp = storage_path('app/guideline_assets/_test_manifest_asset_rerank.json');
        @mkdir(dirname($tmp), 0777, true);
        file_put_contents($tmp, json_encode($manifest));
        config()->set('guideline_assets.manifest_path', $tmp);
        config()->set('guideline_assets.disk', 'public');
        config()->set('guideline_assets.enable_keyword_fallback', true);
        config()->set('guideline_assets.max_assets', 3);
        config()->set('guideline_assets.rerank.enabled', true);
        config()->set('guideline_assets.rerank.candidate_pool', 3);

        $fakeReranker = new class extends BridgeRerankService
        {
            public bool $called = false;

            public function __construct()
            {
            }

            public function rerankDocuments(
                string $query,
                array $documents,
                int $topN,
                string $label,
                ?array $configOverride = null
            ): array {
                $this->called = true;

                return [
                    ['index' => 2, 'score' => 0.99],
                    ['index' => 1, 'score' => 0.71],
                    ['index' => 0, 'score' => 0.35],
                ];
            }
        };

        $this->app->instance(BridgeRerankService::class, $fakeReranker);
        $svc = app(GuidelineAssetService::class);

        $assets = $svc->findRelevantAssets(
            'Symptomatic juxtarenal abdominal aortic aneurysm 6 cm impending rupture stable urgent management open repair versus endovascular repair',
            [
                [
                    'content' => 'For patients with a ruptured complex abdominal aortic aneurysm or who are urgent for any other reason, open repair or endovascular repair should be considered.',
                    'source_guideline' => 'Abdominal Aortic Aneurysm',
                ],
                [
                    'content' => 'Juxtarenal and pararenal anatomy defines complex AAA repair planning.',
                    'source_guideline' => 'Abdominal Aortic Aneurysm',
                ],
            ],
            [],
            [
                'abdominal_aortic_aneurysm' => ['id' => 'x', 'name' => 'Abdominal Aortic Aneurysm'],
            ],
            ['abdominal_aortic_aneurysm']
        );

        $this->assertTrue($fakeReranker->called);
        $this->assertCount(3, $assets);
        $this->assertSame('fig_complex_aaa', $assets[0]['id']);
    }
}
