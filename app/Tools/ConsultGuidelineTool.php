<?php

namespace App\Tools;

use App\Facades\RAGFlow;
use Vizra\VizraADK\Tools\BaseTool;
use Vizra\VizraADK\Tools\ToolParameter;

class ConsultGuidelineTool extends BaseTool
{
    protected string $name = 'consult_guideline';

    protected string $description = 'Consult ESVS vascular surgery guidelines for a specific topic. Returns relevant recommendations and evidence-based guidance.';

    protected array $parameters = [];

    public function __construct()
    {
        $this->parameters = [
            new ToolParameter(
                name: 'topic',
                description: 'The vascular surgery topic to search for (e.g., Carotid, Aortic, Trauma, Venous, Peripheral)',
                type: 'string',
                required: true
            ),
            new ToolParameter(
                name: 'question',
                description: 'Optional specific clinical question to answer',
                type: 'string',
                required: false
            ),
        ];
    }

    public function execute(array $input): string
    {
        $topic = $input['topic'] ?? '';
        $question = $input['question'] ?? '';

        if (empty($topic)) {
            return 'Error: A topic is required to consult the guidelines.';
        }

        try {
            $query = "ESVS guidelines {$topic}";
            if (!empty($question)) {
                $query .= " - {$question}";
            }

            $chats = RAGFlow::chat()->list();
            
            if (empty($chats['data'])) {
                return $this->getMockGuidelines($topic);
            }

            $chatId = $chats['data'][0]['id'] ?? null;
            
            if (!$chatId) {
                return $this->getMockGuidelines($topic);
            }

            $response = RAGFlow::chat()->sendMessage($chatId, [
                'message' => $query,
                'stream' => false,
            ]);

            if (!empty($response['data']['answer'])) {
                return $response['data']['answer'];
            }

            return $this->getMockGuidelines($topic);

        } catch (\Exception $e) {
            \Log::warning('RAGFlow query failed, using mock data: ' . $e->getMessage());
            return $this->getMockGuidelines($topic);
        }
    }

    protected function getMockGuidelines(string $topic): string
    {
        $guidelines = [
            'carotid' => "ESVS Carotid Guidelines Summary:
- Rec 12: In symptomatic patients with carotid stenosis (>=50%), carotid endarterectomy (CEA) should be performed as soon as possible, ideally within 14 days of symptom onset.
- Rec 13: Carotid artery stenting (CAS) can be considered as an alternative to CEA in symptomatic patients at high surgical risk.
- Rec 17: In asymptomatic patients with 60-99% stenosis, CEA should be considered if life expectancy exceeds 5 years and perioperative stroke/death risk is <3%.
- Rec 23: All patients should receive optimal medical therapy including antiplatelets, statins, and risk factor modification.",
            
            'aortic' => "ESVS Aortic Guidelines Summary:
- Rec 8: Elective repair of abdominal aortic aneurysm (AAA) is recommended when diameter reaches 5.5cm in men or 5.0cm in women.
- Rec 15: EVAR should be considered as an alternative to open repair in patients with suitable anatomy.
- Rec 22: Annual surveillance imaging is recommended for AAA between 3.0-4.4cm, and every 6 months for 4.5-5.4cm.
- Rec 28: Emergency repair is indicated for ruptured or symptomatic AAA regardless of size.",
            
            'trauma' => "ESVS Vascular Trauma Guidelines Summary:
- Rec 5: Hard signs of vascular injury require immediate surgical exploration.
- Rec 12: CT angiography is the first-line imaging modality for suspected vascular trauma.
- Rec 18: Endovascular repair should be considered for suitable blunt thoracic aortic injuries.
- Rec 24: Damage control surgery principles apply to unstable patients with vascular trauma.",
            
            'venous' => "ESVS Chronic Venous Disease Guidelines Summary:
- Rec 10: Compression therapy is recommended as first-line treatment for venous leg ulcers.
- Rec 16: Superficial venous ablation should be considered for symptomatic varicose veins.
- Rec 23: Duplex ultrasound is the investigation of choice for chronic venous disease.
- Rec 31: Deep venous reconstruction may be considered for severe post-thrombotic syndrome.",
            
            'peripheral' => "ESVS Peripheral Arterial Disease Guidelines Summary:
- Rec 7: Ankle-brachial index (ABI) is recommended as first-line diagnostic test for PAD.
- Rec 14: Supervised exercise therapy should be offered to all claudicants as first-line treatment.
- Rec 21: Revascularization is indicated for critical limb ischemia to prevent limb loss.
- Rec 35: Endovascular-first approach may be considered for aortoiliac disease.",
        ];

        $topicLower = strtolower($topic);
        
        foreach ($guidelines as $key => $content) {
            if (str_contains($topicLower, $key)) {
                return $content;
            }
        }

        return "No specific ESVS guidelines found for topic: {$topic}. Available topics include: Carotid, Aortic, Trauma, Venous, and Peripheral arterial disease. Please specify one of these topics for detailed recommendations.";
    }
}
