<?php

namespace App\Tools;

use App\Facades\RAGFlow;
use Illuminate\Support\Facades\Log;
use Vizra\VizraADK\Contracts\ToolInterface;
use Vizra\VizraADK\Memory\AgentMemory;
use Vizra\VizraADK\System\AgentContext;

class ConsultGuidelineTool implements ToolInterface
{
    private const DATASET_IDS = [
        '4fff3622eb1b11f09021f2381272676b',
    ];

    public function definition(): array
    {
        return [
            'name' => 'consult_guideline',
            'description' => 'Consult ESVS vascular surgery guidelines for a specific topic. Queries the official guideline datasets directly and returns relevant recommendations.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'topic' => [
                        'type' => 'string',
                        'description' => 'The vascular surgery topic to search for (e.g., Carotid, Aortic, Trauma, Venous, Peripheral)',
                    ],
                    'question' => [
                        'type' => 'string',
                        'description' => 'Optional specific clinical question to answer',
                    ],
                ],
                'required' => ['topic'],
            ],
        ];
    }

    public function execute(array $arguments, AgentContext $context, AgentMemory $memory): string
    {
        $topic = $arguments['topic'] ?? '';
        $question = $arguments['question'] ?? '';

        if (empty($topic)) {
            return json_encode(['error' => 'A topic is required to consult the guidelines.']);
        }

        $query = "ESVS guidelines {$topic}";
        if (!empty($question)) {
            $query .= " - {$question}";
        }

        Log::info("ConsultGuidelineTool: Querying RAGFlow for: {$query}");

        try {
            $response = RAGFlow::datasets()->retrieve(self::DATASET_IDS, [
                'question' => $query,
                'top_k' => 10,
            ]);

            Log::info("ConsultGuidelineTool: RAGFlow returned " . count($response['data']['chunks'] ?? []) . " chunks");

            if (!empty($response['data']['chunks'])) {
                return $this->formatRetrievalResults($response['data']['chunks'], $topic);
            }

            if (isset($response['code']) && $response['code'] !== 0) {
                $errorMsg = $response['message'] ?? 'Unknown error';
                Log::warning('RAGFlow returned error: ' . $errorMsg);
                return $this->getReferenceGuidelines($topic) . "\n\n[RAGFlow Error: {$errorMsg}]";
            }

            Log::info('RAGFlow: No chunks returned for query: ' . $query);
            return $this->getReferenceGuidelines($topic) . "\n\n[No matching documents found in RAGFlow datasets]";

        } catch (\Exception $e) {
            Log::error('RAGFlow dataset query failed: ' . $e->getMessage());
            return $this->getReferenceGuidelines($topic) . "\n\n[RAGFlow temporarily unavailable: " . $e->getMessage() . "]";
        }
    }

    protected function formatRetrievalResults(array $chunks, string $topic): string
    {
        $output = "[SOURCE: RAGFlow Direct Dataset Retrieval - " . count($chunks) . " chunks from dataset 4fff3622eb1b11f09021f2381272676b]\n\n";
        $output .= "ESVS Guidelines - {$topic}\n";
        $output .= str_repeat("=", 60) . "\n\n";

        foreach ($chunks as $index => $chunk) {
            $num = $index + 1;
            $similarity = isset($chunk['similarity']) ? round($chunk['similarity'] * 100, 1) : 'N/A';
            $docName = $chunk['doc_name'] ?? $chunk['document_name'] ?? 'Unknown Document';
            $content = $chunk['content'] ?? $chunk['content_with_weight'] ?? '';

            if (empty($content)) {
                continue;
            }

            $output .= "--- Result {$num} (Relevance: {$similarity}%) ---\n";
            $output .= "Source: {$docName}\n\n";
            $output .= trim($content) . "\n\n";
        }

        if (strlen($output) < 100) {
            return $this->getReferenceGuidelines($topic) . "\n\n[RAGFlow returned empty content]";
        }

        return $output;
    }

    protected function getReferenceGuidelines(string $topic): string
    {
        $guidelines = [
            'carotid' => "ESVS Carotid Guidelines Summary (Reference):
- Rec 12: In symptomatic patients with carotid stenosis (>=50%), carotid endarterectomy (CEA) should be performed as soon as possible, ideally within 14 days of symptom onset.
- Rec 13: Carotid artery stenting (CAS) can be considered as an alternative to CEA in symptomatic patients at high surgical risk.
- Rec 17: In asymptomatic patients with 60-99% stenosis, CEA should be considered if life expectancy exceeds 5 years and perioperative stroke/death risk is <3%.
- Rec 23: All patients should receive optimal medical therapy including antiplatelets, statins, and risk factor modification.
Note: For more detailed guidance, please configure RAGFlow with your ESVS guideline documents.",
            
            'aortic' => "ESVS Aortic Guidelines Summary (Reference):
- Rec 8: Elective repair of abdominal aortic aneurysm (AAA) is recommended when diameter reaches 5.5cm in men or 5.0cm in women.
- Rec 15: EVAR should be considered as an alternative to open repair in patients with suitable anatomy.
- Rec 22: Annual surveillance imaging is recommended for AAA between 3.0-4.4cm, and every 6 months for 4.5-5.4cm.
- Rec 28: Emergency repair is indicated for ruptured or symptomatic AAA regardless of size.
Note: For more detailed guidance, please configure RAGFlow with your ESVS guideline documents.",
            
            'trauma' => "ESVS Vascular Trauma Guidelines Summary (Reference):
- Rec 5: Hard signs of vascular injury require immediate surgical exploration.
- Rec 12: CT angiography is the first-line imaging modality for suspected vascular trauma.
- Rec 18: Endovascular repair should be considered for suitable blunt thoracic aortic injuries.
- Rec 24: Damage control surgery principles apply to unstable patients with vascular trauma.
Note: For more detailed guidance, please configure RAGFlow with your ESVS guideline documents.",
            
            'venous' => "ESVS Chronic Venous Disease Guidelines Summary (Reference):
- Rec 10: Compression therapy is recommended as first-line treatment for venous leg ulcers.
- Rec 16: Superficial venous ablation should be considered for symptomatic varicose veins.
- Rec 23: Duplex ultrasound is the investigation of choice for chronic venous disease.
- Rec 31: Deep venous reconstruction may be considered for severe post-thrombotic syndrome.
Note: For more detailed guidance, please configure RAGFlow with your ESVS guideline documents.",
            
            'peripheral' => "ESVS Peripheral Arterial Disease Guidelines Summary (Reference):
- Rec 7: Ankle-brachial index (ABI) is recommended as first-line diagnostic test for PAD.
- Rec 14: Supervised exercise therapy should be offered to all claudicants as first-line treatment.
- Rec 21: Revascularization is indicated for critical limb ischemia to prevent limb loss.
- Rec 35: Endovascular-first approach may be considered for aortoiliac disease.
Note: For more detailed guidance, please configure RAGFlow with your ESVS guideline documents.",
        ];

        $topicLower = strtolower($topic);
        
        foreach ($guidelines as $key => $content) {
            if (str_contains($topicLower, $key)) {
                return $content;
            }
        }

        return "No specific ESVS guidelines found for topic: {$topic}. Available topics include: Carotid, Aortic, Trauma, Venous, and Peripheral arterial disease. Please specify one of these topics for detailed recommendations. Note: For comprehensive guidance, please configure RAGFlow with the relevant ESVS guideline documents.";
    }
}
