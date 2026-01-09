<?php

namespace App\Tools;

use App\Facades\RAGFlow;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Vizra\VizraADK\Tools\BaseTool;
use Vizra\VizraADK\Tools\ToolParameter;

class ConsultGuidelineTool extends BaseTool
{
    protected string $name = 'consult_guideline';

    protected string $description = 'Consult ESVS vascular surgery guidelines for a specific topic. Returns relevant recommendations and evidence-based guidance.';

    protected array $parameters = [];

    private const CHAT_CACHE_KEY = 'ragflow_guideline_chat_id';
    private const SESSION_CACHE_KEY = 'ragflow_guideline_session_id';
    private const CACHE_TTL = 3600;

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

        $query = "ESVS guidelines {$topic}";
        if (!empty($question)) {
            $query .= " - {$question}";
        }

        try {
            $chatId = $this->getOrCreateChat();
            
            if (!$chatId) {
                Log::info('RAGFlow: No chat assistant available, using reference guidelines');
                return $this->getReferenceGuidelines($topic);
            }

            $sessionId = $this->getOrCreateSession($chatId);
            
            if (!$sessionId) {
                Log::info('RAGFlow: Could not create session, using reference guidelines');
                return $this->getReferenceGuidelines($topic);
            }

            $response = RAGFlow::chat()->sessions($chatId)->sendMessage($sessionId, [
                'message' => $query,
                'stream' => false,
            ]);

            if (!empty($response['data']['answer'])) {
                return "RAGFlow Response:\n" . $response['data']['answer'];
            }

            if (isset($response['code']) && $response['code'] !== 0) {
                $errorMsg = $response['message'] ?? 'Unknown error';
                Log::warning('RAGFlow returned error: ' . $errorMsg);
                return $this->getReferenceGuidelines($topic) . "\n\n[RAGFlow Error: {$errorMsg}]";
            }

            Log::info('RAGFlow: No answer in response, using reference guidelines');
            return $this->getReferenceGuidelines($topic);

        } catch (\Exception $e) {
            Log::error('RAGFlow query failed: ' . $e->getMessage());
            return $this->getReferenceGuidelines($topic) . "\n\n[RAGFlow temporarily unavailable: " . $e->getMessage() . "]";
        }
    }

    protected function getOrCreateChat(): ?string
    {
        $cachedChatId = Cache::get(self::CHAT_CACHE_KEY);
        if ($cachedChatId) {
            return $cachedChatId;
        }

        try {
            $chats = RAGFlow::chat()->list(['page' => 1, 'page_size' => 10]);
            
            if (!empty($chats['data'])) {
                foreach ($chats['data'] as $chat) {
                    if (!empty($chat['id'])) {
                        $chatId = $chat['id'];
                        Cache::put(self::CHAT_CACHE_KEY, $chatId, self::CACHE_TTL);
                        Log::info('RAGFlow: Using existing chat assistant: ' . ($chat['name'] ?? $chatId));
                        return $chatId;
                    }
                }
            }

            Log::info('RAGFlow: No chat assistants found. Please create one in RAGFlow with your ESVS guidelines dataset.');
            return null;

        } catch (\Exception $e) {
            Log::error('RAGFlow chat list failed: ' . $e->getMessage());
            return null;
        }
    }

    protected function getOrCreateSession(string $chatId): ?string
    {
        $cacheKey = self::SESSION_CACHE_KEY . '_' . $chatId;
        $cachedSessionId = Cache::get($cacheKey);
        
        if ($cachedSessionId) {
            return $cachedSessionId;
        }

        try {
            $sessions = RAGFlow::chat()->sessions($chatId)->list(['page' => 1, 'page_size' => 1]);
            
            if (!empty($sessions['data']) && !empty($sessions['data'][0]['id'])) {
                $sessionId = $sessions['data'][0]['id'];
                Cache::put($cacheKey, $sessionId, self::CACHE_TTL);
                return $sessionId;
            }

            $newSession = RAGFlow::chat()->sessions($chatId)->create([
                'name' => 'Guideline Consultation - ' . date('Y-m-d H:i:s'),
            ]);

            if (!empty($newSession['data']['id'])) {
                $sessionId = $newSession['data']['id'];
                Cache::put($cacheKey, $sessionId, self::CACHE_TTL);
                Log::info('RAGFlow: Created new session: ' . $sessionId);
                return $sessionId;
            }

            Log::warning('RAGFlow: Session creation failed - ' . json_encode($newSession));
            return null;

        } catch (\Exception $e) {
            Log::error('RAGFlow session setup failed: ' . $e->getMessage());
            return null;
        }
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
