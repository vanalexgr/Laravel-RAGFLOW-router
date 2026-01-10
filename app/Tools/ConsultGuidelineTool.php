<?php

namespace App\Tools;

use App\Facades\RAGFlow;
use Illuminate\Support\Facades\Log;
use Vizra\VizraADK\Contracts\ToolInterface;
use Vizra\VizraADK\Memory\AgentMemory;
use Vizra\VizraADK\System\AgentContext;

class ConsultGuidelineTool implements ToolInterface
{
    protected const MAX_CHUNKS_TOTAL = 15; // Cap total chunks to prevent context bloat
    protected const MAX_CHUNKS_PER_GUIDELINE = 8; // Cap per guideline for per-guideline retrieval

    public function definition(): array
    {
        return [
            'name' => 'consult_guideline',
            'description' => 'REQUIRED: Consult ESVS vascular surgery guidelines. Queries official guideline datasets with Knowledge Graph enabled and returns evidence-based recommendations.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'topic' => [
                        'type' => 'string',
                        'description' => 'The complete search query including guideline topic and clinical question (e.g., "Carotid stenosis management symptomatic", "Aortic aneurysm repair threshold")',
                    ],
                    'guideline_keys' => [
                        'type' => 'string',
                        'description' => 'JSON array of guideline keys from select_guidelines output (e.g., ["carotid_vertebral", "vascular_trauma"]). If not provided, queries all guideline datasets.',
                    ],
                ],
                'required' => ['topic'],
            ],
        ];
    }

    public function execute(array $arguments, AgentContext $context, AgentMemory $memory): string
    {
        $topic = $arguments['topic'] ?? '';

        if (empty($topic)) {
            return json_encode(['error' => 'A topic is required to consult the guidelines.']);
        }

        $query = $topic;
        $retrievalConfig = config('ragflow.retrieval', []);
        
        $datasetIds = $this->resolveDatasetIds($arguments['guideline_keys'] ?? null);
        $selectedGuidelineNames = $this->resolveGuidelineNames($arguments['guideline_keys'] ?? null);
        
        if (empty($datasetIds)) {
            Log::error("ConsultGuidelineTool: No valid dataset IDs resolved", [
                'guideline_keys_input' => $arguments['guideline_keys'] ?? null,
            ]);
            return json_encode(['error' => 'No valid guideline datasets found for the provided keys.']);
        }

        $topK = $retrievalConfig['top_k'] ?? 1024;
        $size = $retrievalConfig['size'] ?? 10;
        $page = $retrievalConfig['page'] ?? 1;
        $similarityThreshold = $retrievalConfig['similarity_threshold'] ?? 0.2;
        $keywordMode = $retrievalConfig['keyword_mode'] ?? true;
        $vectorWeight = $retrievalConfig['vector_similarity_weight'] ?? 0.3;
        $rerankId = $retrievalConfig['rerank_id'] ?? null;
        $useKg = $retrievalConfig['use_kg'] ?? true;
        $highlight = $retrievalConfig['highlight'] ?? true;

        $usePerGuidelineRetrieval = count($datasetIds) > 1;
        
        Log::info("ConsultGuidelineTool: Querying RAGFlow", [
            'query' => $query,
            'dataset_ids' => $datasetIds,
            'guideline_names' => $selectedGuidelineNames,
            'retrieval_mode' => $usePerGuidelineRetrieval ? 'PER-GUIDELINE' : 'SINGLE',
            'top_k' => $topK,
            'size' => $size,
            'rerank_id' => $rerankId,
            'use_kg' => $useKg,
        ]);

        try {
            $retrievalParams = [
                'question' => $query,
                'top_k' => $topK,
                'size' => $size,
                'page' => $page,
                'similarity_threshold' => $similarityThreshold,
                'keyword' => $keywordMode,
                'vector_similarity_weight' => $vectorWeight,
                'highlight' => $highlight,
            ];
            
            if (!empty($rerankId)) {
                $retrievalParams['rerank_id'] = $rerankId;
            }
            
            if ($useKg) {
                $retrievalParams['use_kg'] = true;
            }

            if (!is_array($datasetIds) || empty($datasetIds)) {
                throw new \InvalidArgumentException("dataset_ids must be a non-empty array, got: " . gettype($datasetIds));
            }

            // Per-guideline parallel retrieval for multi-intent queries
            if ($usePerGuidelineRetrieval) {
                $allChunks = $this->retrieveMultiParallel($datasetIds, $retrievalParams, $selectedGuidelineNames);
            } else {
                // Single guideline - direct retrieval
                Log::channel('ragflow')->info("ConsultGuidelineTool: Single guideline retrieval", [
                    'dataset_ids' => $datasetIds,
                    'params' => $retrievalParams,
                ]);
                
                $response = RAGFlow::datasets()->retrieve($datasetIds, $retrievalParams);
                $allChunks = $response['data']['chunks'] ?? [];
            }

            Log::info("ConsultGuidelineTool: RAGFlow returned " . count($allChunks) . " chunks (pre-cap)");

            // Cap total chunks to prevent context bloat
            $cappedChunks = array_slice($allChunks, 0, self::MAX_CHUNKS_TOTAL);
            
            Log::info("ConsultGuidelineTool: Capped to " . count($cappedChunks) . " chunks (max: " . self::MAX_CHUNKS_TOTAL . ")");

            if (!empty($cappedChunks)) {
                return $this->formatRetrievalResults($cappedChunks, $topic, $selectedGuidelineNames);
            }

            if (isset($response['code']) && $response['code'] !== 0) {
                $errorMsg = $response['message'] ?? 'Unknown error';
                Log::warning('RAGFlow returned error: ' . $errorMsg, [
                    'dataset_ids' => $datasetIds,
                    'response' => $response,
                ]);
                return $this->getReferenceGuidelines($topic) . "\n\n[RAGFlow Error: {$errorMsg}]";
            }

            Log::info('RAGFlow: No chunks returned for query: ' . $query);
            return $this->getReferenceGuidelines($topic) . "\n\n[No matching documents found in RAGFlow datasets]";

        } catch (\Exception $e) {
            Log::error('RAGFlow dataset query failed: ' . $e->getMessage(), [
                'dataset_ids' => $datasetIds,
                'exception' => $e->getTraceAsString(),
            ]);
            return $this->getReferenceGuidelines($topic) . "\n\n[RAGFlow temporarily unavailable: " . $e->getMessage() . "]";
        }
    }

    protected function resolveDatasetIds(?string $guidelineKeysJson): array
    {
        $registry = $this->buildGuidelineRegistry();
        $recommendationsDatasetId = config('guidelines.recommendations_dataset');
        
        if (empty($guidelineKeysJson)) {
            $allIds = config('guidelines.all_guideline_datasets', []);
            return array_values(array_filter($allIds, fn($id) => $id !== $recommendationsDatasetId));
        }

        $keys = json_decode($guidelineKeysJson, true);
        if (!is_array($keys)) {
            Log::warning("ConsultGuidelineTool: Invalid guideline_keys JSON, using all datasets", [
                'input' => $guidelineKeysJson,
            ]);
            $allIds = config('guidelines.all_guideline_datasets', []);
            return array_values(array_filter($allIds, fn($id) => $id !== $recommendationsDatasetId));
        }

        $validIds = [];
        foreach ($keys as $key) {
            if (isset($registry[$key])) {
                $id = $registry[$key]['id'];
                if ($id !== $recommendationsDatasetId) {
                    $validIds[] = $id;
                }
            } else {
                Log::warning("ConsultGuidelineTool: Unknown guideline key ignored", ['key' => $key]);
            }
        }

        if (empty($validIds)) {
            Log::warning("ConsultGuidelineTool: No valid keys found, using all datasets");
            $allIds = config('guidelines.all_guideline_datasets', []);
            return array_values(array_filter($allIds, fn($id) => $id !== $recommendationsDatasetId));
        }

        return array_values(array_unique($validIds));
    }

    protected function resolveGuidelineNames(?string $guidelineKeysJson): array
    {
        if (empty($guidelineKeysJson)) {
            return ['All Guidelines'];
        }

        $keys = json_decode($guidelineKeysJson, true);
        if (!is_array($keys)) {
            return ['All Guidelines'];
        }

        $registry = $this->buildGuidelineRegistry();
        $names = [];
        foreach ($keys as $key) {
            if (isset($registry[$key])) {
                $names[] = $registry[$key]['name'];
            }
        }

        return empty($names) ? ['All Guidelines'] : $names;
    }

    /**
     * Parallel retrieval across multiple datasets using bridge's asyncio.gather.
     * This prevents one guideline from dominating results and reduces latency.
     */
    protected function retrieveMultiParallel(array $datasetIds, array $baseParams, array $guidelineNames): array
    {
        $registry = $this->buildGuidelineRegistry();
        $idToName = [];
        foreach ($registry as $key => $info) {
            $idToName[$info['id']] = $info['name'];
        }

        // Build datasets array for the multi-retrieval endpoint
        $datasets = [];
        foreach ($datasetIds as $datasetId) {
            $datasets[] = [
                'id' => $datasetId,
                'name' => $idToName[$datasetId] ?? 'Unknown',
            ];
        }

        Log::channel('ragflow')->info("ConsultGuidelineTool: PARALLEL multi-retrieval", [
            'datasets' => $datasets,
            'params' => $baseParams,
        ]);

        // Call the bridge's parallel endpoint
        $params = array_merge($baseParams, [
            'max_per_dataset' => self::MAX_CHUNKS_PER_GUIDELINE,
            'max_total' => self::MAX_CHUNKS_TOTAL,
        ]);

        $response = RAGFlow::datasets()->retrieveMulti($datasets, $params);
        
        $chunks = $response['data']['chunks'] ?? [];
        $retrievalInfo = $response['retrieval_info'] ?? [];
        
        Log::info("ConsultGuidelineTool: Parallel retrieval complete", [
            'per_dataset' => $retrievalInfo['per_dataset'] ?? [],
            'combined_pre_global_cap' => $retrievalInfo['combined_pre_global_cap'] ?? 0,
            'combined_after_global_cap' => $retrievalInfo['combined_after_global_cap'] ?? count($chunks),
            'duration_ms' => $response['duration_ms'] ?? 0,
        ]);

        return $chunks;
    }

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

    protected function formatRetrievalResults(array $chunks, string $topic, array $guidelineNames): string
    {
        $guidelinesStr = implode(', ', $guidelineNames);
        $output = "[SOURCE: RAGFlow KG Retrieval - {$guidelinesStr} - " . count($chunks) . " chunks]\n\n";
        $output .= "ESVS Guidelines - {$topic}\n";
        $output .= str_repeat("=", 60) . "\n\n";

        foreach ($chunks as $index => $chunk) {
            $num = $index + 1;
            $content = $chunk['content'] ?? $chunk['content_with_weight'] ?? '';

            if (empty($content)) {
                continue;
            }

            $metadata = $this->extractMetadata($chunk, $content);
            
            $sourceGuideline = $chunk['_source_guideline'] ?? null;
            $sourceTag = $sourceGuideline ? " [from: {$sourceGuideline}]" : "";
            
            $output .= "--- Result {$num}{$sourceTag} ---\n";
            $output .= "METADATA:\n";
            $output .= "  Guideline: {$metadata['guideline_id']} ({$metadata['guideline_year']})\n";
            $output .= "  Recommendation: {$metadata['recommendation_id']}\n";
            $output .= "  Class: {$metadata['class']} | Level: {$metadata['level']}\n";
            $output .= "  Territory: {$metadata['territory']}\n";
            $output .= "  Similarity: {$metadata['similarity']}% (Vector: {$metadata['vector_similarity']}%, Term: {$metadata['term_similarity']}%)\n";
            $output .= "  Document: {$metadata['document']}\n\n";
            $output .= "CONTENT:\n{$metadata['recommendation_text']}\n\n";
        }

        if (strlen($output) < 100) {
            return $this->getReferenceGuidelines($topic) . "\n\n[RAGFlow returned empty content]";
        }

        $output .= "\n[SELECTED_GUIDELINES: " . json_encode($guidelineNames) . "]\n";

        return $output;
    }

    protected function extractMetadata(array $chunk, string $content): array
    {
        $metadata = [
            'guideline_id' => 'Unknown',
            'guideline_year' => 'Unknown',
            'recommendation_id' => 'Unknown',
            'class' => 'Unknown',
            'level' => 'Unknown',
            'territory' => 'Unknown',
            'recommendation_text' => $content,
            'document' => $chunk['document_keyword'] ?? $chunk['doc_name'] ?? 'Unknown',
            'similarity' => isset($chunk['similarity']) ? round($chunk['similarity'] * 100, 1) : 0,
            'vector_similarity' => isset($chunk['vector_similarity']) ? round($chunk['vector_similarity'] * 100, 1) : 0,
            'term_similarity' => isset($chunk['term_similarity']) ? round($chunk['term_similarity'] * 100, 1) : 0,
        ];

        if (preg_match('/GUIDELINE_ID:\s*(\S+)/i', $content, $m)) {
            $metadata['guideline_id'] = $m[1];
        }
        if (preg_match('/GUIDELINE_YEAR:\s*(\d+)/i', $content, $m)) {
            $metadata['guideline_year'] = $m[1];
        }
        if (preg_match('/RECOMMENDATION_ID:\s*(Rec\s*\d+)/i', $content, $m)) {
            $metadata['recommendation_id'] = $m[1];
        }
        if (preg_match('/CLASS:\s*(Class\s*\S+)/i', $content, $m)) {
            $metadata['class'] = $m[1];
        }
        if (preg_match('/LEVEL:\s*(Level\s*\S+)/i', $content, $m)) {
            $metadata['level'] = $m[1];
        }
        if (preg_match('/TERRITORY:\s*(\S+)/i', $content, $m)) {
            $metadata['territory'] = $m[1];
        }
        if (preg_match('/RECOMMENDATION_TEXT:\s*(.+?)(?=TRIPLES:|$)/is', $content, $m)) {
            $metadata['recommendation_text'] = trim($m[1]);
        }

        return $metadata;
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
