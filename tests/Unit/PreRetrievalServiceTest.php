<?php

namespace Tests\Unit;

use App\Contracts\LlmClient;
use App\Services\PreRetrievalService;
use Tests\TestCase;

class PreRetrievalServiceTest extends TestCase
{
    public function test_knowledge_question_has_no_soft_warn_and_knowledge_scope(): void
    {
        $service = $this->makeServiceWithResponse(json_encode([
            'proceed' => true,
            'soft_warn' => false,
            'clarification_questions' => [],
            'provisional_diagnosis' => 'Elective abdominal aortic aneurysm repair threshold in fit patients.',
            'guidelines' => ['abdominal_aortic_aneurysm'],
            'retrieval_query' => 'abdominal aortic aneurysm elective repair threshold fit patient diameter',
            'scope' => 'knowledge_question',
            'confirmation_message' => "-> Understanding: Elective abdominal aortic aneurysm repair threshold in fit patients.\n-> Searching: Abdominal Aortic Aneurysm\n-> Query terms: abdominal aortic aneurysm elective repair threshold\nReply to confirm, or add details to refine the search.",
        ]));

        $result = $service->analyse('What is the threshold for AAA repair in fit patients?');

        $this->assertTrue($result->proceed);
        $this->assertFalse($result->softWarn);
        $this->assertSame('knowledge_question', $result->scope);
        $this->assertContains('abdominal_aortic_aneurysm', $result->guidelines);
    }

    public function test_patient_case_with_sufficient_context_has_no_clarifications(): void
    {
        $service = $this->makeServiceWithResponse(json_encode([
            'proceed' => true,
            'soft_warn' => false,
            'clarification_questions' => [],
            'provisional_diagnosis' => 'Symptomatic carotid stenosis with recent transient ischaemic attack.',
            'guidelines' => ['carotid_vertebral'],
            'retrieval_query' => 'symptomatic carotid stenosis transient ischaemic attack carotid endarterectomy timing nasect 80 percent',
            'scope' => 'single_guideline',
            'confirmation_message' => "-> Understanding: Symptomatic carotid stenosis with recent transient ischaemic attack.\n-> Searching: Carotid & Vertebral\n-> Query terms: symptomatic carotid stenosis TIA carotid endarterectomy timing\nReply to confirm, or add details to refine the search.",
        ]));

        $result = $service->analyse('75yo fit man, symptomatic 80% carotid stenosis, TIA 5 days ago');

        $this->assertTrue($result->proceed);
        $this->assertFalse($result->softWarn);
        $this->assertSame(['carotid_vertebral'], $result->guidelines);
        $this->assertEmpty($result->clarificationQuestions);
        $this->assertStringContainsString('symptomatic', $result->retrievalQuery);
        $this->assertStringContainsString("Clinical Query Checkpoint\n\n🩺 Understanding", $result->confirmationMessage);
        $this->assertStringContainsString("\n\n📚 Searching\nCarotid & Vertebral", $result->confirmationMessage);
        $this->assertStringContainsString("\n\n🏷️ Query Terms\n", $result->confirmationMessage);
        $this->assertStringContainsString('✅ Reply to confirm, or add details to refine the search.', $result->confirmationMessage);
    }

    public function test_patient_case_with_gaps_sets_soft_warn_and_questions(): void
    {
        $service = $this->makeServiceWithResponse(json_encode([
            'proceed' => true,
            'soft_warn' => true,
            'clarification_questions' => [
                'Is the stenosis symptomatic or asymptomatic?',
                'What is the stenosis degree by NASCET criteria?',
            ],
            'provisional_diagnosis' => 'Carotid stenosis requiring symptom and severity clarification.',
            'guidelines' => ['carotid_vertebral'],
            'retrieval_query' => 'carotid stenosis management symptomatic asymptomatic nasect severity',
            'scope' => 'single_guideline',
            'confirmation_message' => "-> Understanding: Carotid stenosis requiring symptom and severity clarification.\n-> Searching: Carotid & Vertebral\n-> Query terms: carotid stenosis management nasect severity\n-> To sharpen: Is the stenosis symptomatic or asymptomatic? / What is the stenosis degree by NASCET criteria?\nReply to confirm, or add details to refine the search.",
        ]));

        $result = $service->analyse('Patient with carotid stenosis');

        $this->assertTrue($result->proceed);
        $this->assertTrue($result->softWarn);
        $this->assertNotEmpty($result->clarificationQuestions);
        $this->assertStringContainsString("\n\n❓ To Sharpen\n- Is the stenosis symptomatic or asymptomatic?", $result->confirmationMessage);
    }

    public function test_soft_warn_confirmation_message_does_not_append_terminal_context_warning(): void
    {
        $service = $this->makeServiceWithResponse(json_encode([
            'proceed' => true,
            'soft_warn' => true,
            'clarification_questions' => [
                'What is the extent and location along the saphenous vein?',
            ],
            'provisional_diagnosis' => 'Possible superficial vein thrombosis of the saphenous system.',
            'guidelines' => ['venous_thrombosis'],
            'retrieval_query' => 'saphenous vein thrombosis superficial vein thrombosis lower limb',
            'scope' => 'single_guideline',
            'confirmation_message' => "-> Understanding: Possible superficial vein thrombosis of the saphenous system.\n-> Searching: Venous Thrombosis (DVT/PE)\n-> Query terms: saphenous vein thrombosis superficial vein thrombosis lower limb\n-> To sharpen: What is the extent and location along the saphenous vein?\nReply to confirm, or add details to refine the search.",
        ]));

        $result = $service->analyse('Patient with saphenous thrombosis');

        $this->assertTrue($result->softWarn);
        $this->assertStringNotContainsString(
            'The provided ESVS guideline context does not explicitly address this scenario.',
            $result->confirmationMessage
        );
    }

    public function test_saphenous_thrombosis_never_asks_if_it_is_superficial_or_deep(): void
    {
        $service = $this->makeServiceWithResponse(json_encode([
            'proceed' => true,
            'soft_warn' => true,
            'clarification_questions' => [
                'Is the thrombosis superficial or deep?',
                'What is the extent and location along the saphenous vein?',
                'Is there any associated deep vein thrombosis or pulmonary embolism?',
            ],
            'provisional_diagnosis' => 'Patient with thrombosis involving the saphenous vein of the lower limb.',
            'guidelines' => ['venous_thrombosis', 'chronic_venous_disease'],
            'retrieval_query' => 'saphenous vein thrombosis superficial vein thrombosis lower limb',
            'scope' => 'multi_guideline',
            'confirmation_message' => "-> Understanding: Patient with thrombosis involving the saphenous vein of the lower limb.\n-> Searching: Venous Thrombosis (DVT/PE), Chronic Venous Disease\n-> Query terms: saphenous vein thrombosis superficial lower limb\n-> To sharpen: Is the thrombosis superficial or deep? / What is the extent and location along the saphenous vein? / Is there any associated deep vein thrombosis or pulmonary embolism?\nReply to confirm, or add details to refine the search.",
        ]));

        $result = $service->analyse('Patient with saphenous thrombosis');

        $this->assertTrue($result->softWarn);
        $this->assertNotEmpty($result->clarificationQuestions);
        $this->assertSame(
            'What is the extent and location along the saphenous vein?',
            $result->clarificationQuestions[0]
        );
        $this->assertStringNotContainsString(
            'superficial or deep',
            strtolower(implode(' | ', $result->clarificationQuestions))
        );
        $this->assertStringContainsString(
            'What is the extent and location along the saphenous vein?',
            $result->confirmationMessage
        );
    }

    public function test_non_english_input_returns_english_retrieval_query_and_diagnosis(): void
    {
        $service = $this->makeServiceWithResponse(json_encode([
            'proceed' => true,
            'soft_warn' => false,
            'clarification_questions' => [],
            'provisional_diagnosis' => 'Superficial venous thrombosis management question.',
            'guidelines' => ['venous_thrombosis'],
            'retrieval_query' => 'superficial venous thrombosis saphenous vein thrombosis management anticoagulation',
            'scope' => 'knowledge_question',
            'confirmation_message' => "-> Understanding: Superficial venous thrombosis management question.\n-> Searching: Venous Thrombosis (DVT/PE)\n-> Query terms: superficial venous thrombosis saphenous vein anticoagulation\nReply to confirm, or add details to refine the search.",
        ]));

        $result = $service->analyse('Ποια είναι η αντιμετώπιση της επιπολής φλεβικής θρόμβωσης;');

        $this->assertSame('Superficial venous thrombosis management question.', $result->provisionalDiagnosis);
        $this->assertSame(
            'superficial venous thrombosis saphenous vein thrombosis management anticoagulation',
            $result->retrievalQuery
        );
        $this->assertSame(0, preg_match('/[\p{Greek}]/u', $result->retrievalQuery));
    }

    public function test_multi_guideline_case_selects_aortic_and_antithrombotic_guidelines(): void
    {
        $service = $this->makeServiceWithResponse(json_encode([
            'proceed' => true,
            'soft_warn' => false,
            'clarification_questions' => [],
            'provisional_diagnosis' => 'Post-EVAR antithrombotic management with atrial fibrillation.',
            'guidelines' => ['abdominal_aortic_aneurysm', 'antithrombotic_therapy'],
            'retrieval_query' => 'post evar antithrombotic therapy atrial fibrillation anticoagulation abdominal aortic aneurysm',
            'scope' => 'multi_guideline',
            'confirmation_message' => "-> Understanding: Post-EVAR antithrombotic management with atrial fibrillation.\n-> Searching: Abdominal Aortic Aneurysm, Antithrombotic Therapy\n-> Query terms: post EVAR antithrombotic therapy atrial fibrillation anticoagulation\nReply to confirm, or add details to refine the search.",
        ]));

        $result = $service->analyse('Antithrombotic therapy after EVAR in patient with AF');

        $this->assertSame('multi_guideline', $result->scope);
        $this->assertContains('abdominal_aortic_aneurysm', $result->guidelines);
        $this->assertContains('antithrombotic_therapy', $result->guidelines);
    }

    public function test_complex_juxtarenal_aneurysm_adds_thoracic_companion_guideline(): void
    {
        $service = $this->makeServiceWithResponse(json_encode([
            'proceed' => true,
            'soft_warn' => true,
            'clarification_questions' => [
                'What is the aneurysm diameter?',
                'Is there evidence of rupture or impending rupture?',
                'What is the patient\'s haemodynamic stability?',
            ],
            'provisional_diagnosis' => 'Symptomatic juxtarenal abdominal aortic aneurysm requiring urgent management decision.',
            'guidelines' => ['abdominal_aortic_aneurysm'],
            'retrieval_query' => 'symptomatic juxtarenal abdominal aortic aneurysm urgent management open repair versus endovascular repair',
            'scope' => 'single_guideline',
            'confirmation_message' => 'placeholder',
        ]));

        $result = $service->analyse('patient with juxtarenal aneyrysm symptomatic. What is the best management?');

        $this->assertSame(
            ['abdominal_aortic_aneurysm', 'descending_thoracic_aorta'],
            $result->guidelines
        );
        $this->assertStringContainsString(
            "📚 Searching\nAbdominal Aortic Aneurysm, Thoracic Aorta",
            $result->confirmationMessage
        );
    }

    public function test_non_a_non_b_dissection_is_normalized_for_understanding_guidelines_and_query_terms(): void
    {
        $service = $this->makeServiceWithResponse(json_encode([
            'proceed' => true,
            'soft_warn' => true,
            'clarification_questions' => [
                'What is the extent of the aortic dissection in relation to the arch and descending thoracic aorta?',
                'Is the carotid artery dissection affecting the common or internal carotid and is it ipsilateral to the stroke?',
                'What is the patient\'s current neurological status and time since stroke onset?',
            ],
            'provisional_diagnosis' => 'Acute aortic arch dissection above the left subclavian artery with extension into the carotid artery causing thrombus and ischemic stroke.',
            'guidelines' => ['aortic_arch', 'carotid_vertebral'],
            'retrieval_query' => 'aortic arch dissection left subclavian carotid thrombus ischemic stroke surgery',
            'scope' => 'multi_guideline',
            'confirmation_message' => 'placeholder',
        ]));

        $result = $service->analyse(
            'My patient has a dissection just above the left subclavian and the dissection is local and also dissected the carotid with thrombus inside and a stroke because of this. Should I operate?'
        );

        $this->assertStringContainsString('non-A non-B', $result->provisionalDiagnosis);
        $this->assertSame(
            ['descending_thoracic_aorta', 'aortic_arch', 'carotid_vertebral'],
            $result->guidelines
        );
        $this->assertStringContainsString('non a non b dissection', $result->retrievalQuery);
        $this->assertStringContainsString("📚 Searching\nThoracic Aorta, Aortic Arch, Carotid & Vertebral", $result->confirmationMessage);
        $this->assertStringContainsString("🏷️ Query Terms\nnon-A non-B dissection", $result->confirmationMessage);
        $this->assertStringNotContainsString(
            'The provided ESVS guideline context does not explicitly address this scenario.',
            $result->confirmationMessage
        );
    }

    public function test_requested_guidelines_are_merged_back_into_confirmation_message(): void
    {
        $service = $this->makeServiceWithResponse(json_encode([
            'proceed' => true,
            'soft_warn' => true,
            'clarification_questions' => [
                'What is the extent of the aortic dissection in relation to the arch and descending thoracic aorta?',
            ],
            'provisional_diagnosis' => 'Acute aortic arch dissection above the left subclavian artery with carotid extension.',
            'guidelines' => ['aortic_arch', 'carotid_vertebral'],
            'retrieval_query' => 'aortic arch dissection left subclavian carotid extension stroke',
            'scope' => 'multi_guideline',
            'confirmation_message' => 'placeholder',
        ]));

        $result = $service->analyse('Dissection just above the left subclavian with carotid extension and stroke');
        $result = $service->applyRequestedGuidelines($result, ['descending_thoracic_aorta', 'carotid_vertebral']);

        $this->assertSame(
            ['descending_thoracic_aorta', 'aortic_arch', 'carotid_vertebral'],
            $result->guidelines
        );
        $this->assertStringContainsString(
            "📚 Searching\nThoracic Aorta, Aortic Arch, Carotid & Vertebral",
            $result->confirmationMessage
        );
    }

    public function test_aorto_oesophageal_fistula_presentation_adds_missing_aortic_clarification_questions(): void
    {
        $service = $this->makeServiceWithResponse(json_encode([
            'proceed' => true,
            'soft_warn' => false,
            'clarification_questions' => [],
            'provisional_diagnosis' => 'Acute descending thoracic aortic aneurysm with aorto-esophageal fistula causing major haemorrhage managed by thoracic endovascular aortic repair.',
            'guidelines' => ['vascular_graft_infections', 'descending_thoracic_aorta'],
            'retrieval_query' => 'thoracic aorta',
            'scope' => 'multi_guideline',
            'confirmation_message' => 'placeholder',
        ]));

        $result = $service->analyse(
            'Thoracic aortic aneurysm in connection with the esophagous. The patient has haemorrhage and haematemesis. We treating the patient with tevar.'
        );

        $this->assertTrue($result->softWarn);
        $this->assertSame([
            'Is the aneurysm or repair distal to the left subclavian artery or involving the arch?',
            'Is there confirmed aorto-oesophageal fistula or graft/endograft infection on CT, endoscopy, or PET, or is this suspected clinically?',
            'Is the patient haemodynamically stable, or is there ongoing active bleeding or shock?',
        ], $result->clarificationQuestions);
        $this->assertStringContainsString("\n\n❓ To Sharpen\n- Is the aneurysm or repair distal to the left subclavian artery or involving the arch?", $result->confirmationMessage);
        $this->assertMatchesRegularExpression('/aorto-.*esophag.*fistula/i', $result->provisionalDiagnosis);
    }

    public function test_llm_failure_returns_safe_defaults(): void
    {
        $service = new PreRetrievalService(
            new class implements LlmClient
            {
                public function complete(string $prompt, int $maxTokens = 150, float $temperature = 0): string
                {
                    throw new \RuntimeException('Azure unavailable');
                }
            },
            $this->availableGuidelines()
        );

        $question = 'Patient with carotid stenosis';
        $result = $service->analyse($question);

        $this->assertTrue($result->proceed);
        $this->assertFalse($result->softWarn);
        $this->assertSame([], $result->clarificationQuestions);
        $this->assertSame($question, $result->retrievalQuery);
        $this->assertSame('single_guideline', $result->scope);
        $this->assertStringContainsString('Clinical Query Checkpoint', $result->confirmationMessage);
        $this->assertStringContainsString('Carotid & Vertebral', $result->confirmationMessage);
    }

    public function test_malformed_json_returns_safe_defaults_without_throwing(): void
    {
        $service = $this->makeServiceWithResponse('```json {"not-valid" ');

        $question = 'Patient with carotid stenosis';
        $result = $service->analyse($question);

        $this->assertTrue($result->proceed);
        $this->assertFalse($result->softWarn);
        $this->assertSame(['carotid_vertebral'], $result->guidelines);
        $this->assertSame($question, $result->retrievalQuery);
    }

    private function makeServiceWithResponse(string $response): PreRetrievalService
    {
        return new PreRetrievalService(
            new class($response) implements LlmClient
            {
                public function __construct(private readonly string $response)
                {
                }

                public function complete(string $prompt, int $maxTokens = 150, float $temperature = 0): string
                {
                    return $this->response;
                }
            },
            $this->availableGuidelines()
        );
    }

    private function availableGuidelines(): array
    {
        $guidelines = [];

        foreach (config('guidelines.categories', []) as $category) {
            foreach (($category['guidelines'] ?? []) as $key => $info) {
                $guidelines[$key] = $info['name'] ?? $key;
            }
        }

        return $guidelines;
    }
}
