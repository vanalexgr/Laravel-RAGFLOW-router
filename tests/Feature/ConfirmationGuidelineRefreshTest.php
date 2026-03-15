<?php

namespace Tests\Feature;

use App\Services\ChangeDetectionService;
use App\Services\GuidelineAssetService;
use App\Services\GuidelineRouterService;
use App\Services\PreRetrievalService;
use App\Services\RetrievalService;
use App\ValueObjects\ChangeDetectionResult;
use Mockery;
use Tests\TestCase;

class ConfirmationGuidelineRefreshTest extends TestCase
{
    public function test_confirmation_requery_promotes_clti_when_clarification_adds_tissue_loss_and_rest_pain(): void
    {
        $this->withoutMiddleware();

        $retrieval = Mockery::mock(RetrievalService::class);
        $retrieval->shouldReceive('retrieve')
            ->once()
            ->withArgs(function (string $question, array $history, ?array $requestedKeys): bool {
                return $question === 'antithrombotic therapy after distal bypass with vein for tissue loss and rest pain'
                    && $requestedKeys === ['antithrombotic_therapy', 'clti'];
            })
            ->andReturn([
                'question' => 'antithrombotic therapy after distal bypass with vein for tissue loss and rest pain',
                'narrative_chunks' => [],
                'citation_chunks' => [
                    [
                        'text' => 'Antithrombotic therapy after lower limb bypass in CLTI should balance bleeding and limb risk.',
                        'recommendation_id' => '2',
                        'class' => 'I',
                        'level' => 'C',
                        'guideline' => 'Antithrombotics',
                    ],
                ],
                'llm_citation_chunks' => [
                    [
                        'text' => 'Antithrombotic therapy after lower limb bypass in CLTI should balance bleeding and limb risk.',
                        'recommendation_id' => '2',
                        'class' => 'I',
                        'level' => 'C',
                        'guideline' => 'Antithrombotics',
                    ],
                ],
                'llm_narrative_chunks' => [],
                'ui_citation_chunks' => [
                    [
                        'text' => 'Antithrombotic therapy after lower limb bypass in CLTI should balance bleeding and limb risk.',
                        'recommendation_id' => '2',
                        'class' => 'I',
                        'level' => 'C',
                        'guideline' => 'Antithrombotics',
                    ],
                ],
                'ui_narrative_chunks' => [],
                'selected_guidelines' => [
                    ['key' => 'antithrombotic_therapy', 'name' => 'Antithrombotics'],
                    ['key' => 'clti', 'name' => 'Chronic Limb-Threatening Ischemia'],
                ],
                'query_normalization' => [],
                'intent_profile' => ['intent' => 'management'],
                'query_type' => 'complex_case',
            ]);

        $assets = Mockery::mock(GuidelineAssetService::class);
        $assets->shouldReceive('findRelevantAssets')
            ->once()
            ->andReturn([]);

        $changeDetection = Mockery::mock(ChangeDetectionService::class);
        $changeDetection->shouldReceive('detect')
            ->once()
            ->andReturn(new ChangeDetectionResult(
                decision: 'requery',
                reason: 'symptomatic status changed to clti',
                enrichedQuery: 'antithrombotic therapy after distal bypass with vein for tissue loss and rest pain',
                updatedGuidelines: []
            ));

        $this->app->instance(RetrievalService::class, $retrieval);
        $this->app->instance(GuidelineAssetService::class, $assets);
        $this->app->instance(ChangeDetectionService::class, $changeDetection);
        $this->app->instance(GuidelineRouterService::class, Mockery::mock(GuidelineRouterService::class));
        $this->app->instance(PreRetrievalService::class, Mockery::mock(PreRetrievalService::class));

        $response = $this->postJson('/api/v1/vascular-consult', [
            'question' => 'distal bypass with vein done for tissue loss and rest pain. None',
            'history' => [
                'What is the recommended antithrombotic therapy after lower limb revascularization for peripheral arterial disease?',
                "Clinical Query Checkpoint\n\n🩺 Understanding\nPost lower limb revascularization in peripheral arterial disease requiring guidance on optimal antithrombotic therapy.\n\n📚 Searching\nAntithrombotic Therapy, Asymptomatic PAD\n\n🏷️ Query Terms\nantithrombotic therapy after lower limb revascularization for peripheral arterial disease surgical or endovascular management\n\n❓ To Sharpen\n- Was the revascularization surgical bypass or endovascular intervention?\n- Is the patient symptomatic or asymptomatic after the procedure?\n- Are there any contraindications to antithrombotic therapy?\n\n✅ Reply to confirm, or add details to refine the search.",
            ],
            'confirmation_mode' => true,
            'pre_retrieval_result' => [
                'proceed' => true,
                'soft_warn' => true,
                'clarification_questions' => [
                    'Was the revascularization surgical bypass or endovascular intervention?',
                    'Is the patient symptomatic or asymptomatic after the procedure?',
                    'Are there any contraindications to antithrombotic therapy?',
                ],
                'provisional_diagnosis' => 'Post lower limb revascularization in peripheral arterial disease requiring guidance on optimal antithrombotic therapy.',
                'guidelines' => ['antithrombotic_therapy', 'asymptomatic_pad'],
                'retrieval_query' => 'antithrombotic therapy after lower limb revascularization for peripheral arterial disease surgical or endovascular management',
                'scope' => 'multi_guideline',
                'confirmation_message' => 'Clinical Query Checkpoint',
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('reused', false)
            ->assertJsonPath('retrieval_payload.selected_guidelines.0.key', 'antithrombotic_therapy')
            ->assertJsonPath('retrieval_payload.selected_guidelines.1.key', 'clti');
    }
}
