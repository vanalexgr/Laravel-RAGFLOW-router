<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use App\Services\GuidelineRouterService;
use App\Services\PHIScrubberService;
use App\Services\DocumentContextAnalyzerService;
use Illuminate\Support\Facades\Http;

class RetrievalService
{
    /**
     * Core retrieval pipeline: PHI Scrub -> Route -> Dual Retrieve.
     *
     * @param string $question
     * @param array $history
     * @param array|null $requestedKeys
     * @param int $topK
     * @param string $patientContext
     * @return array
     */
    public function retrieve(string $question, array $history = [], ?array $requestedKeys = null, int $topK = 12, string $patientContext = ''): array
    {
        $startTime = microtime(true);
        $log = Log::channel('retrieval');
        $correlationId = substr(uniqid(), -8);

        // 1. PHI Scrubbing
        $phiScrubber = new PHIScrubberService();
        $scrubResult = $phiScrubber->scrub($question);
        $scrubbedQuestion = $scrubResult['scrubbed_text'];

        $scrubbedPatientContext = '';
        $patientContextRedactions = 0;
        if (!empty($patientContext)) {
            $contextScrubResult = $phiScrubber->scrub($patientContext);
            $scrubbedPatientContext = $contextScrubResult['scrubbed_text'];
            $patientContextRedactions = $contextScrubResult['total_redactions'];
        }

        $log->info('=== RETRIEVAL SERVICE ===', [
            'correlation_id' => $correlationId,
            'question_preview' => substr($scrubbedQuestion, 0, 50),
            'has_history' => !empty($history),
        ]);

        // 2. Document Analysis (if context provided)
        $documentAnalysis = null;
        if (!empty($scrubbedPatientContext)) {
            $documentAnalyzer = new DocumentContextAnalyzerService();
            $documentAnalysis = $documentAnalyzer->analyze($scrubbedPatientContext);
        }

        // 3. Routing
        $guidelineScores = [];
        $routingMethod = 'manual';

        if (empty($requestedKeys)) {
            $router = new GuidelineRouterService();
            // Use context-aware routing
            $llmResult = $router->routeWithContext($scrubbedQuestion, $history, 3, $documentAnalysis);

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

        // 5. Dual Retrieval
        $narrativeMax = 15;
        $citationMax = 5; // Reduced from 6

        // Create an expanded query for retrieval
        $router = new GuidelineRouterService();
        $expansionResult = $router->selectAndExpand($scrubbedQuestion, 3, null, $documentAnalysis);
        $expandedQuery = $expansionResult['expanded'] ?? $scrubbedQuestion; // Use expanded or original

        $dualResult = $this->retrieveDualChunks($expandedQuery, $selectedGuidelines, $narrativeMax, $citationMax, $guidelineScores);

        $duration = round((microtime(true) - $startTime) * 1000);

        return [
            'success' => true,
            'question' => $scrubbedQuestion,
            'expanded_query' => $expandedQuery, // Return for debug
            'phi_scrubbed' => $scrubResult['was_modified'] || !empty($patientContext),
            'selected_guidelines' => $selectedGuidelines,
            'routing_method' => $routingMethod,
            'narrative_chunks' => $dualResult['narrative_chunks'],
            'citation_chunks' => $dualResult['citation_chunks'],
            'duration_ms' => $duration,
            'system_prompt' => $this->getDualSystemPrompt(),
            // Add scrubbed context if it existed
            'scrubbed_patient_context' => $scrubbedPatientContext,
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
     * Guardrail A: Thrombosis keywords → ensure venous_thrombosis
     * Guardrail B: Anticoagulation keywords → ensure antithrombotic_therapy
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

        // Guardrail A: Thrombosis terms → ensure venous_thrombosis
        $thrombosisTerms = [
            'svt',
            'superficial vein thrombosis',
            'thrombophlebitis',
            'dvt',
            'deep vein thrombosis',
            'venous thrombosis',
            'pulmonary embolism',
            'pe',
            'vte',
            'venous thromboembolism'
        ];

        foreach ($thrombosisTerms as $term) {
            if (str_contains($questionLower, $term) && !isset($selectedGuidelines['venous_thrombosis'])) {
                if (isset($registry['venous_thrombosis'])) {
                    $selectedGuidelines['venous_thrombosis'] = $registry['venous_thrombosis'];
                    $modified = true;
                    $log->info("[GUARDRAIL A] Added venous_thrombosis (detected: $term)");
                    break;
                }
            }
        }

        // Guardrail B: Anticoag terms → ensure antithrombotic_therapy
        $anticoagTerms = [
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
            'duration',
            'dose',
            'dosing'
        ];

        foreach ($anticoagTerms as $term) {
            if (str_contains($questionLower, $term) && !isset($selectedGuidelines['antithrombotic_therapy'])) {
                if (isset($registry['antithrombotic_therapy'])) {
                    $selectedGuidelines['antithrombotic_therapy'] = $registry['antithrombotic_therapy'];
                    $modified = true;
                    $log->info("[GUARDRAIL B] Added antithrombotic_therapy (detected: $term)");
                    break;
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


    protected function retrieveDualChunks(string $question, array $guidelines, int $narrativeMax, int $citationMax, array $scores = []): array
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
        $params = [
            'question' => $question,
            'narrative_max' => $narrativeMax,
            'citation_max' => $citationMax,
            'citation_document_ids' => $citationDocumentIds, // NEW: pass to Python
            'top_k' => $retrievalConfig['top_k'] ?? 256,
            'similarity_threshold' => $retrievalConfig['similarity_threshold'] ?? 0.2,
            'keyword' => true,
            'vector_similarity_weight' => 0.3,
            'highlight' => true,
        ];
        if (!empty($retrievalConfig['rerank_id'])) {
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

        return [
            'narrative_chunks' => $this->formatChunks($response['narrative']['chunks'] ?? [], 'narrative', array_column($narrativeDatasets, 'name')),
            'citation_chunks' => $this->formatChunks($response['citations']['chunks'] ?? [], 'citation'),
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

### NARRATIVE_CHUNKS (use_kg=true)
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
