<?php

namespace Tests\Unit;

use App\Contracts\LlmClient;
use App\Services\ChangeDetectionService;
use App\ValueObjects\PreRetrievalResult;
use Tests\TestCase;

class ChangeDetectionServiceTest extends TestCase
{
    public function test_simple_confirmation_reuses_existing_retrieval(): void
    {
        $service = $this->makeServiceWithResponse(json_encode([
            'decision' => 'reuse',
            'reason' => 'simple confirmation',
            'enriched_query' => null,
        ]));

        $result = $service->detect('yes proceed', $this->originalResult());

        $this->assertSame('reuse', $result->decision);
        $this->assertNull($result->enrichedQuery);
    }

    public function test_fitness_clarification_reuses_existing_retrieval(): void
    {
        $service = $this->makeServiceWithResponse(json_encode([
            'decision' => 'reuse',
            'reason' => 'fitness detail only',
            'enriched_query' => null,
        ]));

        $result = $service->detect('patient is fit for surgery', $this->originalResult());

        $this->assertSame('reuse', $result->decision);
    }

    public function test_different_diagnosis_requires_requery(): void
    {
        $service = $this->makeServiceWithResponse(json_encode([
            'decision' => 'requery',
            'reason' => 'different diagnosis',
            'enriched_query' => 'type b aortic dissection thoracic aorta acute management',
            'updated_guidelines' => ['descending_thoracic_aorta'],
        ]));

        $result = $service->detect('actually this is a type B dissection not AAA', $this->originalResult());

        $this->assertSame('requery', $result->decision);
        $this->assertNotNull($result->enrichedQuery);
        $this->assertSame(['descending_thoracic_aorta'], $result->updatedGuidelines);
    }

    public function test_new_anatomical_territory_requires_requery(): void
    {
        $service = $this->makeServiceWithResponse(json_encode([
            'decision' => 'requery',
            'reason' => 'different anatomical territory',
            'enriched_query' => 'renal artery stenosis renal revascularisation chronic mesenteric renal guideline',
        ]));

        $result = $service->detect('sorry I meant the renal artery not the aorta', $this->originalResult());

        $this->assertSame('requery', $result->decision);
    }

    public function test_symptomatic_status_change_requires_requery(): void
    {
        $service = $this->makeServiceWithResponse(json_encode([
            'decision' => 'requery',
            'reason' => 'symptomatic status changed',
            'enriched_query' => 'symptomatic carotid stenosis transient ischaemic attack carotid endarterectomy timing',
        ]));

        $original = PreRetrievalResult::fromArray([
            'proceed' => true,
            'soft_warn' => false,
            'clarification_questions' => [],
            'provisional_diagnosis' => 'Asymptomatic carotid stenosis.',
            'guidelines' => ['carotid_vertebral'],
            'retrieval_query' => 'asymptomatic carotid stenosis surveillance intervention',
            'scope' => 'single_guideline',
            'confirmation_message' => 'Searching guidelines...',
        ]);

        $result = $service->detect('patient actually had a TIA last week', $original);

        $this->assertSame('requery', $result->decision);
    }

    public function test_added_fitness_info_reuses_existing_retrieval(): void
    {
        $service = $this->makeServiceWithResponse(json_encode([
            'decision' => 'reuse',
            'reason' => 'comorbidity detail only',
            'enriched_query' => null,
        ]));

        $result = $service->detect('patient has CKD stage 3 and COPD', $this->originalResult());

        $this->assertSame('reuse', $result->decision);
        $this->assertNull($result->enrichedQuery);
    }

    public function test_clti_clarification_forces_requery_without_waiting_for_llm(): void
    {
        $client = new class implements LlmClient
        {
            public bool $called = false;

            public function complete(string $prompt, int $maxTokens = 150, float $temperature = 0): string
            {
                $this->called = true;

                return json_encode([
                    'decision' => 'reuse',
                    'reason' => 'should not reach llm',
                    'enriched_query' => null,
                    'updated_guidelines' => null,
                ]);
            }
        };

        $service = new ChangeDetectionService($client);
        $original = PreRetrievalResult::fromArray([
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
        ]);

        $result = $service->detect('distal bypass with vein done for tissue loss and rest pain. None', $original);

        $this->assertSame('requery', $result->decision);
        $this->assertSame(['antithrombotic_therapy', 'clti'], $result->updatedGuidelines);
        $this->assertStringContainsString('chronic limb threatening ischemia', (string) $result->enrichedQuery);
        $this->assertFalse($client->called);
    }

    public function test_prompt_includes_original_clarification_questions_for_follow_up_answers(): void
    {
        $client = new class implements LlmClient
        {
            public string $prompt = '';

            public function complete(string $prompt, int $maxTokens = 150, float $temperature = 0): string
            {
                $this->prompt = $prompt;

                return json_encode([
                    'decision' => 'requery',
                    'reason' => 'clarification answered',
                    'enriched_query' => 'superficial saphenous vein thrombosis sapheno femoral junction lower limb',
                ]);
            }
        };

        $service = new ChangeDetectionService($client);
        $original = PreRetrievalResult::fromArray([
            'proceed' => true,
            'soft_warn' => true,
            'clarification_questions' => [
                'What is the extent and location along the saphenous vein?',
                'Is there any associated deep vein thrombosis or pulmonary embolism?',
            ],
            'provisional_diagnosis' => 'Patient with thrombosis involving the saphenous vein of the lower limb.',
            'guidelines' => ['venous_thrombosis', 'chronic_venous_disease'],
            'retrieval_query' => 'saphenous vein thrombosis superficial vein thrombosis lower limb',
            'scope' => 'multi_guideline',
            'confirmation_message' => 'Searching guidelines...',
        ]);

        $service->detect('Superficial, 4cm from SFJ', $original);

        $this->assertStringContainsString('Original clarification questions:', $client->prompt);
        $this->assertStringContainsString(
            'What is the extent and location along the saphenous vein?',
            $client->prompt
        );
        $this->assertStringContainsString(
            "Prefer 'reuse' when the original provisional diagnosis and guideline set",
            $client->prompt
        );
        $this->assertStringContainsString('updated_guidelines', $client->prompt);
    }

    private function makeServiceWithResponse(string $response): ChangeDetectionService
    {
        return new ChangeDetectionService(
            new class($response) implements LlmClient
            {
                public function __construct(private readonly string $response)
                {
                }

                public function complete(string $prompt, int $maxTokens = 150, float $temperature = 0): string
                {
                    return $this->response;
                }
            }
        );
    }

    private function originalResult(): PreRetrievalResult
    {
        return PreRetrievalResult::fromArray([
            'proceed' => true,
            'soft_warn' => false,
            'clarification_questions' => [],
            'provisional_diagnosis' => 'Abdominal aortic aneurysm assessment.',
            'guidelines' => ['abdominal_aortic_aneurysm'],
            'retrieval_query' => 'abdominal aortic aneurysm repair threshold fit patient',
            'scope' => 'single_guideline',
            'confirmation_message' => 'Searching guidelines...',
        ]);
    }
}
