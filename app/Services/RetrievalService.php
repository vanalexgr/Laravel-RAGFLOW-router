<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use App\Services\GuidelineRouterService;
use App\Services\PHIScrubberService;
use App\Services\BridgeRerankService;

class RetrievalService
{
    /**
     * Core retrieval pipeline: PHI Scrub -> Route -> Dual Retrieve.
     *
     * @param string $question
     * @param array $history
     * @param array|null $requestedKeys
     * @return array
     */
    public function retrieve(string $question, array $history = [], ?array $requestedKeys = null): array
    {
        $startTime = microtime(true);
        $log = Log::channel('retrieval');
        $correlationId = substr(uniqid(), -8);

        // 1. PHI Scrubbing
        $phiScrubber = new PHIScrubberService();
        $scrubResult = $phiScrubber->scrub($question);
        $scrubbedQuestion = $scrubResult['scrubbed_text'];

        $log->info('=== RETRIEVAL SERVICE ===', [
            'correlation_id' => $correlationId,
            'question_preview' => substr($scrubbedQuestion, 0, 50),
            'has_history' => !empty($history),
        ]);

        // 2. Routing
        $guidelineScores = [];
        $routingMethod = 'manual';

        if (empty($requestedKeys)) {
            $router = new GuidelineRouterService();
            // Use context-aware routing
            $llmResult = $router->routeWithContext($scrubbedQuestion, $history, 3);

            $selectedKeys = $llmResult['selected'];
            $guidelineScores = $llmResult['scores'] ?? [];
            $routingMethod = $llmResult['routing_method'] ?? 'unknown';

            // Convert keys to full guideline info
            if (!empty($selectedKeys)) {
                $registry = $this->buildGuidelineRegistry();
                $selectedGuidelines = [];
                foreach ($selectedKeys as $key) {
                    if (isset($registry[$key])) {
                        $selectedGuidelines[$key] = $registry[$key];
                    }
                }
            } else {
                // Fallback to rule-based
                $selectedGuidelines = $this->selectGuidelinesRuleBased($scrubbedQuestion);
                $routingMethod = 'rule_based_fallback';
            }
        } else {
            $selectedGuidelines = $this->validateGuidelineKeys($requestedKeys);
            $routingMethod = 'explicit';
        }

        // Apply post-routing guardrails (SVT/anticoag)
        $selectedGuidelines = $this->applyGuardrails($selectedGuidelines, $scrubbedQuestion);

        // 4. Fallback Keyword Scoring (if still empty)
        if (empty($selectedGuidelines)) {
            $selectedGuidelines = $this->selectGuidelinesByKeywordScore($scrubbedQuestion, 4);
            $routingMethod = 'keyword_fallback';

            if (empty($selectedGuidelines)) {
                throw new \RuntimeException('Unable to identify relevant guidelines. Please try specific clinical terms.');
            }
        }

        // 4. Dual Retrieval
        $narrativeMax = 10;
        $citationMax = 4;

        // Create an expanded query for retrieval
        $router = new GuidelineRouterService();
        $expansionResult = $router->selectAndExpand($scrubbedQuestion, 3, null, null);
        $expandedQuery = $expansionResult['expanded'] ?? $scrubbedQuestion; // Use expanded or original
        $citationQuery = $scrubbedQuestion; // Keep citations tight to the original question

        $dualResult = $this->retrieveDualChunks($expandedQuery, $citationQuery, $selectedGuidelines, $narrativeMax, $citationMax, $guidelineScores);

        $duration = round((microtime(true) - $startTime) * 1000);

        return [
            'success' => true,
            'question' => $scrubbedQuestion,
            'expanded_query' => $expandedQuery, // Return for debug
            'phi_scrubbed' => $scrubResult['was_modified'],
            'selected_guidelines' => $selectedGuidelines,
            'routing_method' => $routingMethod,
            'narrative_chunks' => $dualResult['narrative_chunks'],
            'citation_chunks' => $dualResult['citation_chunks'],
            'duration_ms' => $duration,
            'system_prompt' => $this->getDualSystemPrompt(),
        ];
    }

    // -- Helper Methods Refactored from Controller --

    protected function buildGuidelineRegistry(): array
    {
        $categories = config('guidelines.categories', []);
        $registry = [];
        foreach ($categories as $category) {
            foreach ($category['guidelines'] as $key => $guideline) {
                $registry[$key] = [
                    'id' => $guideline['id'],
                    'name' => $guideline['name'],
                ];
            }
        }
        return $registry;
    }

    protected function selectGuidelinesRuleBased(string $question): array
    {
        $questionLower = strtolower($question);
        $categories = config('guidelines.categories', []);
        $registry = $this->buildGuidelineRegistry();

        // Hardcoded force rules (simplified for service)
        // Note: Ideally move these to a config file
        $forceRules = [
            'carotid' => ['triggers' => ['carotid', 'cea', 'cas', 'tcar'], 'keys' => ['carotid_vertebral']],
            'aaa' => ['triggers' => ['abdominal aortic', 'aaa', 'evar'], 'keys' => ['abdominal_aortic_aneurysm']],
            'pad' => ['triggers' => ['pad', 'claudication'], 'keys' => ['asymptomatic_pad']],
            'clti' => ['triggers' => ['clti', 'gangrene', 'tissue loss'], 'keys' => ['clti']],
            'dvt' => ['triggers' => ['dvt', 'deep vein', 'pulmonary embolism'], 'keys' => ['venous_thrombosis']],
        ];

        $selected = [];
        foreach ($forceRules as $rule) {
            foreach ($rule['triggers'] as $trigger) {
                if (str_contains($questionLower, $trigger)) {
                    foreach ($rule['keys'] as $key) {
                        if (isset($registry[$key]))
                            $selected[$key] = $registry[$key];
                    }
                }
            }
        }

        if (!empty($selected))
            return $selected;

        // Basic keyword match if no force rules
        foreach ($categories as $category) {
            foreach ($category['guidelines'] as $key => $guideline) {
                foreach ($guideline['key_concepts'] as $concept) {
                    if (str_contains($questionLower, strtolower($concept))) {
                        $selected[$key] = $registry[$key];
                        break;
                    }
                }
            }
        }

        return array_slice($selected, 0, 3, true);
    }

    protected function validateGuidelineKeys(array $keys): array
    {
        $registry = $this->buildGuidelineRegistry();
        $validated = [];
        foreach ($keys as $key) {
            if (isset($registry[$key])) {
                $validated[$key] = $registry[$key];
            }
        }
        return $validated;
    }

    protected function selectGuidelinesByKeywordScore(string $question, int $max = 4): array
    {
        // Simplified version of controller's logic
        $categories = config('guidelines.categories', []);
        $questionLower = strtolower($question);
        $scores = [];
        $data = [];

        foreach ($categories as $category) {
            foreach ($category['guidelines'] as $key => $guideline) {
                $score = 0;
                foreach ($guideline['key_concepts'] as $concept) {
                    if (str_contains($questionLower, strtolower($concept)))
                        $score += 2;
                }
                if ($score > 0) {
                    $scores[$key] = $score;
                    $data[$key] = ['id' => $guideline['id'], 'name' => $guideline['name']];
                }
            }
        }

        arsort($scores);
        $result = [];
        foreach (array_slice(array_keys($scores), 0, $max) as $key) {
            $result[$key] = $data[$key];
        }
        return $result;
    }

    /**
     * Apply post-routing guardrails to ensure critical guidelines are not missed.
     * 
     * Guardrails A-N cover all 14 ESVS guidelines with keyword detection.
     * 
     * @param array $selectedGuidelines Current selection (key => guideline info)
     * @param string $question The scrubbed user question
     * @return array Updated selection with guardrails applied
     */
    protected function applyGuardrails(array $selectedGuidelines, string $question): array
    {
        $log = Log::channel('retrieval');
        $questionLower = strtolower($question);
        $registry = $this->buildGuidelineRegistry();
        $modified = false;

        // Define all guardrails: [guideline_key => [trigger_terms]]
        $guardrails = [
            // Guardrail A: Thrombosis terms → venous_thrombosis
            'venous_thrombosis' => [
                'svt',
                'superficial vein thrombosis',
                'thrombophlebitis',
                'dvt',
                'deep vein thrombosis',
                'venous thrombosis',
                'pulmonary embolism',
                'vte',
                'venous thromboembolism',
                'ivc filter',
                'post-thrombotic',
                'catheter-directed thrombolysis'
            ],

            // Guardrail B: Anticoag terms → antithrombotic_therapy
            'antithrombotic_therapy' => [
                'anticoag',
                'anticoagulation',
                'fondaparinux',
                'heparin',
                'lmwh',
                'doac',
                'apixaban',
                'rivaroxaban',
                'warfarin',
                'dabigatran',
                'edoxaban',
                'aspirin',
                'clopidogrel',
                'dual antiplatelet',
                'dapt',
                'triple therapy'
            ],

            // Guardrail C: Aortic Arch terms → aortic_arch
            'aortic_arch' => [
                'aortic arch',
                'zone 0',
                'zone 1',
                'zone 2',
                'frozen elephant trunk',
                'fet',
                'arch aneurysm',
                'hybrid arch',
                'arch repair',
                'total arch'
            ],

            // Guardrail D: Thoracic Aorta terms → descending_thoracic_aorta
            'descending_thoracic_aorta' => [
                'type b dissection',
                'tbad',
                'tevar',
                'thoracic aneurysm',
                'intramural hematoma',
                'imh',
                'descending aorta',
                'penetrating ulcer',
                'thoracic aortic',
                'spinal cord ischemia'
            ],

            // Guardrail E: AAA terms → abdominal_aortic_aneurysm
            'abdominal_aortic_aneurysm' => [
                'aaa',
                'abdominal aortic aneurysm',
                'evar',
                'endoleak',
                'infrarenal aneurysm',
                'iliac aneurysm',
                'aortic rupture',
                'abdominal aneurysm',
                'open repair'
            ],

            // Guardrail F: Mesenteric/Renal terms → mesenteric_renal
            'mesenteric_renal' => [
                'mesenteric ischemia',
                'cmi',
                'ami',
                'bowel ischemia',
                'sma stenosis',
                'celiac stenosis',
                'renal artery stenosis',
                'ras',
                'visceral aneurysm',
                'chronic mesenteric',
                'acute mesenteric'
            ],

            // Guardrail G: Carotid terms → carotid_vertebral
            'carotid_vertebral' => [
                'stroke',
                'tia',
                'transient ischemic',
                'carotid stenosis',
                'cea',
                'cas',
                'tcar',
                'carotid endarterectomy',
                'carotid stenting',
                'vertebral artery',
                'carotid artery'
            ],

            // Guardrail H: PAD/Claudication terms → asymptomatic_pad
            'asymptomatic_pad' => [
                'claudication',
                'intermittent claudication',
                'peripheral arterial disease',
                'abi',
                'ankle brachial',
                'supervised exercise',
                'walking distance',
                'exercise therapy'
            ],

            // Guardrail I: CLTI terms → clti
            'clti' => [
                'clti',
                'cli',
                'critical limb',
                'rest pain',
                'tissue loss',
                'gangrene',
                'wifi',
                'wif-i',
                'limb salvage',
                'heel ulcer',
                'angiosome',
                'ischemic ulcer'
            ],

            // Guardrail J: ALI terms → acute_limb_ischaemia
            'acute_limb_ischaemia' => [
                'acute limb ischemia',
                'acute limb ischaemia',
                'ali',
                'embolectomy',
                '6 ps',
                'pulseless limb',
                'sudden leg pain',
                'rutherford',
                'acute arterial occlusion'
            ],

            // Guardrail K: Venous/Varicose terms → chronic_venous_disease
            'chronic_venous_disease' => [
                'varicose veins',
                'venous ulcer',
                'ceap',
                'venous reflux',
                'gsv',
                'great saphenous',
                'ssv',
                'small saphenous',
                'sclerotherapy',
                'venous ablation',
                'leg ulcer',
                'venous insufficiency'
            ],

            // Guardrail L: Trauma terms → vascular_trauma
            'vascular_trauma' => [
                'vascular trauma',
                'reboa',
                'penetrating injury',
                'blunt vascular',
                'mangled extremity',
                'mess score',
                'vascular injury',
                'hemorrhage control',
                'vascular laceration',
                'arterial injury'
            ],

            // Guardrail M: Graft Infection terms → vascular_graft_infections
            'vascular_graft_infections' => [
                'graft infection',
                'prosthetic infection',
                'aortic graft infection',
                'magic criteria',
                'infected graft',
                'endograft infection',
                'graft excision',
                'infected bypass'
            ],

            // Guardrail N: Vascular Access terms → vascular_access
            'vascular_access' => [
                'avf',
                'av fistula',
                'arteriovenous fistula',
                'dialysis access',
                'hemodialysis access',
                'steal syndrome',
                'access thrombosis',
                'fistula maturation',
                'dialysis catheter'
            ],
        ];

        // Apply each guardrail
        foreach ($guardrails as $guidelineKey => $triggerTerms) {
            if (isset($selectedGuidelines[$guidelineKey])) {
                continue; // Already selected
            }

            foreach ($triggerTerms as $term) {
                if (str_contains($questionLower, $term)) {
                    if (isset($registry[$guidelineKey])) {
                        $selectedGuidelines[$guidelineKey] = $registry[$guidelineKey];
                        $modified = true;
                        $log->info("[GUARDRAIL] Added $guidelineKey (detected: $term)");
                        break;
                    }
                }
            }
        }

        // Enforce max 3 guidelines
        if (count($selectedGuidelines) > 3) {
            $keys = array_keys($selectedGuidelines);
            $count = count($selectedGuidelines);
            $log->warning("[GUARDRAIL] Exceeded 3 guidelines ($count), keeping first 3", [
                'all_keys' => $keys
            ]);
            $selectedGuidelines = array_slice($selectedGuidelines, 0, 3, true);
        }

        if ($modified) {
            $log->info("[GUARDRAILS] Final guideline keys after guardrails", [
                'guideline_keys' => array_keys($selectedGuidelines)
            ]);
        }

        return $selectedGuidelines;
    }

    /**
     * Get full guideline config including recs_doc_id from config file.
     */
    protected function getGuidelineConfig(string $key): ?array
    {
        $categories = config('guidelines.categories', []);
        foreach ($categories as $category) {
            if (isset($category['guidelines'][$key])) {
                return $category['guidelines'][$key];
            }
        }
        return null;
    }


    protected function retrieveDualChunks(string $narrativeQuery, string $citationQuery, array $guidelines, int $narrativeMax, int $citationMax, array $scores = []): array
    {
        $narrativeDatasets = [];
        $citationDocumentIds = []; // NEW: for hard scoping citations

        foreach ($guidelines as $key => $info) {
            // Build narrative datasets
            $narrativeDatasets[] = [
                'id' => $info['id'],
                'name' => $info['name'],
                'score' => $scores[$key] ?? null,
            ];

            // NEW: Collect citation document IDs
            $guidelineConfig = $this->getGuidelineConfig($key);
            if (!empty($guidelineConfig['recs_doc_id'])) {
                // Only add if it's not a placeholder
                if (!str_starts_with($guidelineConfig['recs_doc_id'], 'NEED_')) {
                    $citationDocumentIds[] = $guidelineConfig['recs_doc_id'];
                }
            }
        }

        // Log citation document IDs
        Log::info("[CITATION SCOPE] Document IDs for selected guidelines", [
            'guideline_keys' => array_keys($guidelines),
            'citation_document_ids' => $citationDocumentIds,
            'count' => count($citationDocumentIds),
            'has_placeholders' => count($citationDocumentIds) < count($guidelines)
        ]);

        $citationDatasetId = config('guidelines.recommendations_dataset');
        if (empty($citationDatasetId))
            throw new \RuntimeException('Citation dataset not configured');

        $retrievalConfig = config('ragflow.retrieval', []);
        // Treat retrieval params as server-owned defaults; clamp anything that could
        // explode cost/latency or swamp reranking with noise.
        $topK = (int) ($retrievalConfig['top_k'] ?? 256);
        $topK = max(1, min($topK, 256));

        $bridgeRerank = new BridgeRerankService();
        $params = [
            'question' => $narrativeQuery,
            'citation_query' => $citationQuery,
            'narrative_max' => $narrativeMax,
            'citation_max' => $citationMax,
            'citation_document_ids' => $citationDocumentIds, // NEW: pass to Python
            'top_k' => $topK,
            'similarity_threshold' => $retrievalConfig['similarity_threshold'] ?? 0.2,
            'keyword' => $retrievalConfig['keyword_mode'] ?? true,
            'vector_similarity_weight' => $retrievalConfig['vector_similarity_weight'] ?? 0.3,
            'use_kg' => $retrievalConfig['use_kg'] ?? false,
            'citation_top_k' => (int) ($retrievalConfig['citation_top_k'] ?? 10),
            'highlight' => (bool) ($retrievalConfig['highlight'] ?? false),
        ];
        // Always provide rerank_id if configured; ragflow_service will only forward it
        // upstream if it is non-empty and not "local". If bridge rerank is enabled,
        // do not forward rerank_id to RAGFlow to avoid remote rerank latency.
        if (!$bridgeRerank->enabled() && array_key_exists('rerank_id', $retrievalConfig) && !empty($retrievalConfig['rerank_id'])) {
            $params['rerank_id'] = $retrievalConfig['rerank_id'];
        }

        try {
            $response = \App\Facades\RAGFlow::datasets()->retrieveDual($narrativeDatasets, $citationDatasetId, $params);
        } catch (\Exception $e) {
            throw new \RuntimeException('RAGFlow bridge retrieval failed: ' . $e->getMessage());
        }

        if (($response['status'] ?? 0) !== 200) {
            throw new \RuntimeException('RAGFlow retrieval failed');
        }

        $narrativeChunks = $response['narrative']['chunks'] ?? [];
        $citationChunks = $response['citations']['chunks'] ?? [];

        if ($bridgeRerank->enabled()) {
            $narrativeChunks = $bridgeRerank->rerank($narrativeQuery, $narrativeChunks, $narrativeMax, 'narrative');
            $citationChunks = $bridgeRerank->rerank($citationQuery, $citationChunks, $citationMax, 'citation');
        }

        return [
            'narrative_chunks' => $this->formatChunks($narrativeChunks, 'narrative', array_column($narrativeDatasets, 'name')),
            'citation_chunks' => $this->formatChunks($citationChunks, 'citation'),
        ];
    }

    protected function formatChunks(array $rawChunks, string $type, array $guidelineNames = []): array
    {
        $formatted = [];
        foreach ($rawChunks as $chunk) {
            $content = $chunk['content'] ?? $chunk['content_with_weight'] ?? '';
            if (empty($content))
                continue;

            $meta = [];

            if ($type === 'citation') {
                $text = $content;
                // Parse Citation Metadata - Handle both formats
                // Format 1: RECOMMENDATION_ID: Rec 12
                if (preg_match('/RECOMMENDATION_ID:\s*(Rec\s*[\d\w]+)/i', $content, $m))
                    $meta['recommendation_id'] = $m[1];
                // Format 2: rec_id:vascular_trauma_R002
                elseif (preg_match('/rec_id:\s*([^\s;]+)/i', $content, $m))
                    $meta['recommendation_id'] = $m[1];

                if (preg_match('/CLASS:\s*(Class\s*\S+)/i', $content, $m))
                    $meta['class'] = $m[1];
                elseif (preg_match('/class:\s*([^\s;]+)/i', $content, $m))
                    $meta['class'] = $m[1];

                if (preg_match('/LEVEL:\s*(Level\s*\S+)/i', $content, $m))
                    $meta['level'] = $m[1];
                elseif (preg_match('/level:\s*([^\s;]+)/i', $content, $m))
                    $meta['level'] = $m[1];

                // Extract guideline name if present
                if (preg_match('/guideline_name:\s*([^;]+)/i', $content, $m))
                    $meta['guideline'] = trim($m[1]);

                // Extract text content (remove metadata headers if possible)
                if (preg_match('/RECOMMENDATION_TEXT:\s*(.+?)(?=TRIPLES:|CLASS:|LEVEL:|$)/is', $content, $m))
                    $text = trim($m[1]);
                elseif (preg_match('/recommendation_text:\s*(.+?)(?=;|$)/is', $content, $m))
                    $text = trim($m[1]);
                // If the content is just metadata-heavy, we might want to clean it, but usually the text is in there.
                // For the new format, if no explicit text field, use the whole thing?
                // The chunk preview showed "rec_id:...". Let's assume the text follows or is the whole thing?
                // Actually, let's keep the full content as fallback.

                $meta['text'] = strlen($text) > 800 ? substr($text, 0, 800) . '...' : $text;
            } else {
                // Narrative
                $meta['content'] = strlen($content) > 1000 ? substr($content, 0, 1000) . '...' : $content;
                $meta['source_guideline'] = $chunk['_source_guideline'] ?? ($guidelineNames[0] ?? 'ESVS Guidelines');
            }

            $formatted[] = array_merge([
                'type' => $type,
                'similarity' => round(($chunk['similarity'] ?? 0) * 100, 1),
            ], $meta);
        }
        return $formatted;
    }

    public function getDualSystemPrompt(): string
    {
        return <<<'PROMPT'
You are an ESVS (European Society for Vascular Surgery) clinical guideline assistant.

## YOUR TASK
Answer vascular surgery questions using the provided evidence. You receive TWO types of chunks:

### NARRATIVE_CHUNKS (use_kg configurable)
- Rich clinical context from full guideline text
- Use these for understanding and synthesizing your clinical answer

### CITATION_CHUNKS (use_kg=false)
- Exact recommendations with metatags: recommendation_id, class, level, guideline
- Use these for VERBATIM citations only

## RESPONSE FORMAT
🩺 **Clinical Synthesis**
- 3-6 bullet points answering the clinical question
- Reference recommendation numbers (e.g., "per Rec 12")

📑 **Recommendations used in this answer**
- ONLY use recommendations from CITATION_CHUNKS
- Format: **Rec [ID]** (Class [X], Level [Y]) — [Guideline]
  > "[EXACT verbatim text from citation_chunks]"

## RULES
1. Never invent recommendations
2. If CITATION_CHUNKS don't support your synthesis, note: "Direct recommendation not retrieved"
PROMPT;
    }
}
