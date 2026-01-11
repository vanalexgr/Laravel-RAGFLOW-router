<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Vizra\VizraADK\Facades\Agent;
use Illuminate\Support\Str;

class OpenAICompatibleController extends Controller
{
    protected const MAX_CONTEXT_TOKENS = 128000;
    protected const RESERVED_TOKENS = 45000;  // Increased for system prompt + retrieval + response buffer
    protected const MAX_HISTORY_TOKENS = 80000;

    protected function estimateTokens(string $text): int
    {
        return (int) ceil(strlen($text) / 4);
    }

    protected function truncateHistory(array $history, int $maxTokens): array
    {
        if (empty($history)) {
            return [];
        }

        $totalTokens = 0;
        $truncated = [];

        $reversed = array_reverse($history);

        foreach ($reversed as $msg) {
            $msgTokens = $this->estimateTokens($msg['content'] ?? '');
            
            if ($totalTokens + $msgTokens > $maxTokens) {
                break;
            }
            
            $totalTokens += $msgTokens;
            array_unshift($truncated, $msg);
        }

        if (count($truncated) < count($history)) {
            \Log::info('Context truncation applied', [
                'original_messages' => count($history),
                'kept_messages' => count($truncated),
                'estimated_tokens' => $totalTokens,
            ]);
        }

        return $truncated;
    }

    public function listModels(): JsonResponse
    {
        return response()->json([
            'object' => 'list',
            'data' => [
                [
                    'id' => 'vascular_expert',
                    'object' => 'model',
                    'created' => time(),
                    'owned_by' => 'vizra-adk',
                    'permission' => [],
                    'root' => 'vascular_expert',
                    'parent' => null,
                ],
            ],
        ]);
    }

    public function getModel(string $model): JsonResponse
    {
        if ($model !== 'vascular_expert') {
            return response()->json([
                'error' => [
                    'message' => "Model '{$model}' not found",
                    'type' => 'invalid_request_error',
                    'code' => 'model_not_found',
                ],
            ], 404);
        }

        return response()->json([
            'id' => 'vascular_expert',
            'object' => 'model',
            'created' => time(),
            'owned_by' => 'vizra-adk',
        ]);
    }

    public function chatCompletions(Request $request): JsonResponse|StreamedResponse
    {
        $validated = $request->validate([
            'model' => 'required|string',
            'messages' => 'required|array',
            'messages.*.role' => 'required|string|in:system,user,assistant',
            'messages.*.content' => 'required|string',
            'stream' => 'boolean',
            'temperature' => 'numeric|min:0|max:2',
            'max_tokens' => 'integer|min:1',
        ]);

        $model = $validated['model'];
        $messages = $validated['messages'];
        $stream = $validated['stream'] ?? false;

        if ($model !== 'vascular_expert') {
            return response()->json([
                'error' => [
                    'message' => "Model '{$model}' not found. Use 'vascular_expert'.",
                    'type' => 'invalid_request_error',
                    'code' => 'model_not_found',
                ],
            ], 404);
        }

        $userMessage = '';
        $conversationContext = [];
        
        foreach ($messages as $msg) {
            if ($msg['role'] === 'user') {
                $userMessage = $msg['content'];
            }
            if ($msg['role'] !== 'system') {
                $conversationContext[] = $msg;
            }
        }

        if (empty($userMessage)) {
            return response()->json([
                'error' => [
                    'message' => 'No user message found in messages array',
                    'type' => 'invalid_request_error',
                ],
            ], 400);
        }

        $userMessageTokens = $this->estimateTokens($userMessage);
        $maxUserTokens = self::MAX_CONTEXT_TOKENS - self::RESERVED_TOKENS;
        
        if ($userMessageTokens > $maxUserTokens) {
            \Log::warning('User message exceeds token limit', [
                'user_tokens' => $userMessageTokens,
                'max_allowed' => $maxUserTokens,
            ]);
            return response()->json([
                'error' => [
                    'message' => "Message too long. Please shorten your message (estimated {$userMessageTokens} tokens, max {$maxUserTokens}).",
                    'type' => 'invalid_request_error',
                    'code' => 'context_length_exceeded',
                ],
            ], 400);
        }
        
        $availableHistoryTokens = max(0, $maxUserTokens - $userMessageTokens);
        
        $contextString = '';
        if (count($conversationContext) > 1) {
            $history = array_slice($conversationContext, 0, -1);
            $history = $this->truncateHistory($history, $availableHistoryTokens);
            
            if (!empty($history)) {
                $contextString = "CONVERSATION HISTORY:\n";
                foreach ($history as $msg) {
                    $role = strtoupper($msg['role']);
                    $contextString .= "[{$role}]: {$msg['content']}\n\n";
                }
                $contextString .= "---\nCURRENT QUESTION:\n";
            }
        }
        
        $fullPrompt = $contextString . $userMessage;
        $sessionId = $request->header('X-Session-ID', 'openwebui-session');

        try {
            $response = Agent::run('vascular_expert', $fullPrompt, $sessionId);
            $completionId = 'chatcmpl-' . Str::random(29);

            if ($stream) {
                return $this->streamResponse($response, $completionId, $model);
            }

            return response()->json([
                'id' => $completionId,
                'object' => 'chat.completion',
                'created' => time(),
                'model' => $model,
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => $response,
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => strlen($userMessage) / 4,
                    'completion_tokens' => strlen($response) / 4,
                    'total_tokens' => (strlen($userMessage) + strlen($response)) / 4,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => [
                    'message' => $e->getMessage(),
                    'type' => 'api_error',
                ],
            ], 500);
        }
    }

    protected function streamResponse(string $content, string $completionId, string $model): StreamedResponse
    {
        return response()->stream(function () use ($content, $completionId, $model) {
            if (ob_get_level()) {
                ob_end_clean();
            }
            
            $chunkSize = 50;
            $chunks = mb_str_split($content, $chunkSize);
            
            foreach ($chunks as $index => $textChunk) {
                $chunk = [
                    'id' => $completionId,
                    'object' => 'chat.completion.chunk',
                    'created' => time(),
                    'model' => $model,
                    'choices' => [
                        [
                            'index' => 0,
                            'delta' => [
                                'content' => $textChunk,
                            ],
                            'finish_reason' => null,
                        ],
                    ],
                ];

                echo "data: " . json_encode($chunk) . "\n\n";
                flush();
                usleep(10000);
            }

            $finalChunk = [
                'id' => $completionId,
                'object' => 'chat.completion.chunk',
                'created' => time(),
                'model' => $model,
                'choices' => [
                    [
                        'index' => 0,
                        'delta' => [],
                        'finish_reason' => 'stop',
                    ],
                ],
            ];
            echo "data: " . json_encode($finalChunk) . "\n\n";
            echo "data: [DONE]\n\n";
            flush();

        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Stream response with progress events (for real-time streaming).
     * Shows processing steps before the final response.
     */
    public function chatCompletionsWithProgress(Request $request): StreamedResponse|JsonResponse
    {
        $validated = $request->validate([
            'model' => 'required|string',
            'messages' => 'required|array',
            'messages.*.role' => 'required|string|in:system,user,assistant',
            'messages.*.content' => 'required|string',
        ]);

        $model = $validated['model'];
        $messages = $validated['messages'];

        $userMessage = '';
        $conversationContext = [];
        
        foreach ($messages as $msg) {
            if ($msg['role'] === 'user') {
                $userMessage = $msg['content'];
            }
            if ($msg['role'] !== 'system') {
                $conversationContext[] = $msg;
            }
        }

        $userMessageTokens = $this->estimateTokens($userMessage);
        $maxUserTokens = self::MAX_CONTEXT_TOKENS - self::RESERVED_TOKENS;
        
        if ($userMessageTokens > $maxUserTokens) {
            return response()->json([
                'error' => [
                    'message' => "Message too long. Please shorten your message (estimated {$userMessageTokens} tokens, max {$maxUserTokens}).",
                    'type' => 'invalid_request_error',
                    'code' => 'context_length_exceeded',
                ],
            ], 400);
        }
        
        $availableHistoryTokens = max(0, $maxUserTokens - $userMessageTokens);
        
        $contextString = '';
        if (count($conversationContext) > 1) {
            $history = array_slice($conversationContext, 0, -1);
            $history = $this->truncateHistory($history, $availableHistoryTokens);
            
            if (!empty($history)) {
                $contextString = "CONVERSATION HISTORY:\n";
                foreach ($history as $msg) {
                    $role = strtoupper($msg['role']);
                    $contextString .= "[{$role}]: {$msg['content']}\n\n";
                }
                $contextString .= "---\nCURRENT QUESTION:\n";
            }
        }
        
        $fullPrompt = $contextString . $userMessage;
        $sessionId = $request->header('X-Session-ID', 'openwebui-session');
        $completionId = 'chatcmpl-' . Str::random(29);

        return response()->stream(function () use ($fullPrompt, $sessionId, $completionId, $model) {
            if (ob_get_level()) {
                ob_end_clean();
            }

            // Step 1: Selecting guidelines
            $this->emitProgressEvent($completionId, $model, '🔍 Selecting relevant guidelines...');
            usleep(200000);

            // Step 2: Querying retrieval engine
            $this->emitProgressEvent($completionId, $model, '📚 Querying retrieval engine...');
            usleep(200000);

            // Step 3: Knowledge Graph expansion
            $this->emitProgressEvent($completionId, $model, '🕸️ Knowledge Graph expansion...');
            usleep(200000);

            // Step 4: Reranking
            $this->emitProgressEvent($completionId, $model, '📊 Reranking retrieved knowledge...');
            usleep(200000);

            try {
                $response = \Vizra\VizraADK\Facades\Agent::run('vascular_expert', $fullPrompt, $sessionId);
                
                // Step 5: Drafting answer
                $this->emitProgressEvent($completionId, $model, '✍️ Drafting answer...');
                usleep(200000);

                // Clear progress and stream actual response
                $chunkSize = 50;
                $chunks = mb_str_split($response, $chunkSize);
                
                foreach ($chunks as $textChunk) {
                    $chunk = [
                        'id' => $completionId,
                        'object' => 'chat.completion.chunk',
                        'created' => time(),
                        'model' => $model,
                        'choices' => [
                            [
                                'index' => 0,
                                'delta' => ['content' => $textChunk],
                                'finish_reason' => null,
                            ],
                        ],
                    ];
                    echo "data: " . json_encode($chunk) . "\n\n";
                    flush();
                    usleep(10000);
                }

            } catch (\Exception $e) {
                $this->emitProgressEvent($completionId, $model, '❌ Error: ' . $e->getMessage());
            }

            // Final chunk
            echo "data: " . json_encode([
                'id' => $completionId,
                'object' => 'chat.completion.chunk',
                'created' => time(),
                'model' => $model,
                'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']],
            ]) . "\n\n";
            echo "data: [DONE]\n\n";
            flush();

        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    protected function emitProgressEvent(string $completionId, string $model, string $status): void
    {
        $event = [
            'id' => $completionId,
            'object' => 'chat.completion.chunk',
            'created' => time(),
            'model' => $model,
            'choices' => [
                [
                    'index' => 0,
                    'delta' => ['content' => "_{$status}_\n\n"],
                    'finish_reason' => null,
                ],
            ],
        ];
        echo "data: " . json_encode($event) . "\n\n";
        flush();
    }

    /**
     * Retrieval-only endpoint for OpenWebUI Filter Pipeline.
     * Returns chunks without LLM processing - OpenWebUI handles synthesis.
     */
    public function retrieve(Request $request): JsonResponse
    {
        $startTime = microtime(true);
        
        $validated = $request->validate([
            'question' => 'required|string|max:2000',
            'guideline_keys' => 'array',
            'guideline_keys.*' => 'string',
            'top_k' => 'integer|min:1|max:50',
        ]);

        $question = $validated['question'];
        $requestedKeys = $validated['guideline_keys'] ?? null;
        $topK = $validated['top_k'] ?? 12;

        $log = \Log::channel('retrieval');
        $log->info('[RAW QUERY] Received from OpenWebUI', [
            'question_preview' => substr($question, 0, 80) . (strlen($question) > 80 ? '...' : ''),
            'question_length' => strlen($question),
        ]);
        $log->info('=== RETRIEVE REQUEST ===', [
            'timestamp' => now()->toIso8601String(),
            'question_preview' => substr($question, 0, 80) . (strlen($question) > 80 ? '...' : ''),
            'requested_keys' => $requestedKeys,
            'top_k' => $topK,
            'client_ip' => $request->ip(),
        ]);

        try {
            // Step 1: Select guidelines (if not specified)
            if (empty($requestedKeys)) {
                $selectedGuidelines = $this->selectGuidelines($question);
                $log->info('Guidelines auto-selected', [
                    'keys' => array_keys($selectedGuidelines),
                    'names' => array_column($selectedGuidelines, 'name'),
                ]);
            } else {
                $selectedGuidelines = $this->validateGuidelineKeys($requestedKeys);
                $log->info('Guidelines from request', [
                    'keys' => array_keys($selectedGuidelines),
                ]);
            }

            if (empty($selectedGuidelines)) {
                $log->warning('No guidelines matched query');
                return response()->json([
                    'success' => false,
                    'error' => 'No matching guidelines found for query',
                    'duration_ms' => round((microtime(true) - $startTime) * 1000),
                ], 404);
            }

            // Step 2: Dual retrieval - narrative chunks (KG) + citation chunks (no KG)
            $narrativeMax = 12;
            $citationMax = 5;
            
            $dualResult = $this->retrieveDualChunks($question, $selectedGuidelines, $narrativeMax, $citationMax);

            $duration = round((microtime(true) - $startTime) * 1000);

            $log->info('=== RETRIEVE COMPLETE ===', [
                'guidelines_count' => count($selectedGuidelines),
                'narrative_chunks' => count($dualResult['narrative_chunks']),
                'citation_chunks' => count($dualResult['citation_chunks']),
                'duration_ms' => $duration,
            ]);

            return response()->json([
                'success' => true,
                'question' => $question,
                'selected_guidelines' => $selectedGuidelines,
                'narrative_chunks' => $dualResult['narrative_chunks'],
                'citation_chunks' => $dualResult['citation_chunks'],
                'narrative_count' => count($dualResult['narrative_chunks']),
                'citation_count' => count($dualResult['citation_chunks']),
                'duration_ms' => $duration,
                'system_prompt' => $this->getDualSystemPrompt(),
            ]);

        } catch (\Exception $e) {
            $log->error('Retrieve endpoint error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'duration_ms' => round((microtime(true) - $startTime) * 1000),
            ], 500);
        }
    }

    protected function selectGuidelines(string $question): array
    {
        $registry = $this->buildGuidelineRegistry();
        $log = \Log::channel('retrieval');

        $router = new \App\Services\GuidelineRouterService();
        $selectedKeys = $router->selectGuidelines($question, 3);

        if (!empty($selectedKeys)) {
            $selected = [];
            foreach ($selectedKeys as $key) {
                if (isset($registry[$key])) {
                    $selected[$key] = $registry[$key];
                }
            }
            if (!empty($selected)) {
                $log->info('LLM routing succeeded', ['keys' => array_keys($selected)]);
                return $selected;
            }
        }

        $log->info('Falling back to rule-based routing');
        return $this->selectGuidelinesRuleBased($question);
    }

    protected function selectGuidelinesRuleBased(string $question): array
    {
        $questionLower = strtolower($question);
        $categories = config('guidelines.categories', []);
        
        $forceRules = [
            'carotid' => ['triggers' => ['carotid', 'cea', 'cas ', 'tcar'], 'keys' => ['carotid_vertebral']],
            'aaa' => ['triggers' => ['abdominal aortic aneurysm', 'aaa', 'evar', 'endoleak'], 'keys' => ['abdominal_aortic_aneurysm']],
            'trauma' => ['triggers' => ['trauma', 'reboa', 'vascular injury'], 'keys' => ['vascular_trauma']],
            'pad' => ['triggers' => ['peripheral arterial', 'pad', 'claudication', 'abi'], 'keys' => ['asymptomatic_pad']],
            'clti' => ['triggers' => ['critical limb', 'clti', 'rest pain', 'gangrene'], 'keys' => ['clti']],
            'dvt' => ['triggers' => ['dvt', 'deep vein', 'pulmonary embolism'], 'keys' => ['venous_thrombosis']],
            'thoracic' => ['triggers' => ['type b dissection', 'tbad', 'tevar', 'thoracic aorta'], 'keys' => ['descending_thoracic_aorta']],
        ];

        $selected = [];
        $registry = $this->buildGuidelineRegistry();

        foreach ($forceRules as $rule) {
            foreach ($rule['triggers'] as $trigger) {
                if (str_contains($questionLower, $trigger)) {
                    foreach ($rule['keys'] as $key) {
                        if (isset($registry[$key]) && !isset($selected[$key])) {
                            $selected[$key] = $registry[$key];
                        }
                    }
                    break;
                }
            }
        }

        if (empty($selected)) {
            $candidates = [];
            foreach ($categories as $category) {
                foreach ($category['guidelines'] as $key => $guideline) {
                    $score = 0;
                    foreach ($guideline['key_concepts'] as $concept) {
                        if (str_contains($questionLower, strtolower($concept))) {
                            $score += 10;
                        }
                    }
                    if ($score > 0) {
                        $candidates[$key] = ['info' => $registry[$key], 'score' => $score];
                    }
                }
            }
            
            uasort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);
            $topCandidates = array_slice($candidates, 0, 3, true);
            
            foreach ($topCandidates as $key => $data) {
                $selected[$key] = $data['info'];
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

    protected function retrieveChunks(string $question, array $guidelines, int $topK): array
    {
        $datasetIds = array_map(fn($g) => $g['id'], array_values($guidelines));
        $guidelineNames = array_map(fn($g) => $g['name'], array_values($guidelines));
        
        $retrievalConfig = config('ragflow.retrieval', []);
        
        $params = [
            'question' => $question,
            'top_k' => $retrievalConfig['top_k'] ?? 256,
            'size' => $topK,
            'page' => 1,
            'similarity_threshold' => $retrievalConfig['similarity_threshold'] ?? 0.2,
            'keyword' => true,
            'vector_similarity_weight' => 0.3,
            'highlight' => true,
        ];

        if (!empty($retrievalConfig['rerank_id'])) {
            $params['rerank_id'] = $retrievalConfig['rerank_id'];
        }
        if ($retrievalConfig['use_kg'] ?? true) {
            $params['use_kg'] = true;
        }

        // Use parallel retrieval for multiple guidelines
        if (count($datasetIds) > 1) {
            $datasets = [];
            foreach ($guidelines as $key => $info) {
                $datasets[] = ['id' => $info['id'], 'name' => $info['name']];
            }
            
            $params['max_per_dataset'] = min(6, $topK);
            $params['max_total'] = $topK;
            
            $response = \App\Facades\RAGFlow::datasets()->retrieveMulti($datasets, $params);
            $rawChunks = $response['data']['chunks'] ?? [];
        } else {
            $response = \App\Facades\RAGFlow::datasets()->retrieve($datasetIds, $params);
            $rawChunks = $response['data']['chunks'] ?? [];
        }

        // Cap to requested topK before formatting
        $rawChunks = array_slice($rawChunks, 0, $topK);

        // Format chunks for OpenWebUI consumption
        return $this->formatChunksForPipeline($rawChunks, $guidelineNames);
    }

    protected function formatChunksForPipeline(array $rawChunks, array $guidelineNames): array
    {
        $formatted = [];
        
        foreach ($rawChunks as $chunk) {
            $content = $chunk['content'] ?? $chunk['content_with_weight'] ?? '';
            if (empty($content)) continue;

            // Extract metadata
            $recId = 'Unknown';
            $class = '';
            $level = '';
            $text = $content;

            if (preg_match('/RECOMMENDATION_ID:\s*(Rec\s*\d+)/i', $content, $m)) {
                $recId = $m[1];
            }
            if (preg_match('/CLASS:\s*(Class\s*\S+)/i', $content, $m)) {
                $class = $m[1];
            }
            if (preg_match('/LEVEL:\s*(Level\s*\S+)/i', $content, $m)) {
                $level = $m[1];
            }
            if (preg_match('/RECOMMENDATION_TEXT:\s*(.+?)(?=TRIPLES:|$)/is', $content, $m)) {
                $text = trim($m[1]);
            }

            // Truncate long content
            if (strlen($text) > 800) {
                $text = substr($text, 0, 800) . '...';
            }

            $formatted[] = [
                'recommendation_id' => $recId,
                'class' => $class,
                'level' => $level,
                'content' => $text,
                'source_guideline' => $chunk['_source_guideline'] ?? ($guidelineNames[0] ?? 'ESVS Guidelines'),
                'similarity' => round(($chunk['similarity'] ?? 0) * 100, 1),
            ];
        }

        return $formatted;
    }

    protected function getRecommendedSystemPrompt(): string
    {
        return "You are a vascular surgery expert. Answer the question using ONLY the provided guideline evidence. For each claim, cite the exact recommendation (e.g., 'Rec 12, Class I, Level A'). If the evidence doesn't cover the question, say so clearly. Never invent recommendations.";
    }

    /**
     * Dual retrieval: narrative chunks (KG enabled) + citation chunks (no KG, metatags).
     */
    protected function retrieveDualChunks(string $question, array $guidelines, int $narrativeMax, int $citationMax): array
    {
        $narrativeDatasets = [];
        foreach ($guidelines as $key => $info) {
            $narrativeDatasets[] = ['id' => $info['id'], 'name' => $info['name']];
        }

        $citationDatasetId = config('guidelines.recommendations_dataset');
        
        if (empty($citationDatasetId)) {
            \Log::channel('retrieval')->error('Citation dataset ID not configured in guidelines.recommendations_dataset');
            throw new \RuntimeException('Citation dataset not configured. Please set guidelines.recommendations_dataset in config.');
        }
        
        $retrievalConfig = config('ragflow.retrieval', []);

        $params = [
            'question' => $question,
            'narrative_max' => $narrativeMax,
            'citation_max' => $citationMax,
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
            $response = \App\Facades\RAGFlow::datasets()->retrieveDual(
                $narrativeDatasets,
                $citationDatasetId,
                $params
            );
        } catch (\Exception $e) {
            \Log::channel('retrieval')->error('Bridge retrieve_dual failed', [
                'error' => $e->getMessage(),
                'narrative_datasets' => count($narrativeDatasets),
                'citation_dataset_id' => $citationDatasetId,
            ]);
            throw new \RuntimeException('RAGFlow bridge error: ' . $e->getMessage());
        }

        if (($response['status'] ?? 0) !== 200) {
            $errorMsg = $response['message'] ?? $response['detail'] ?? 'Unknown bridge error';
            \Log::channel('retrieval')->error('Bridge retrieve_dual returned non-200', [
                'status' => $response['status'] ?? 'unknown',
                'message' => $errorMsg,
            ]);
            throw new \RuntimeException('RAGFlow retrieval failed: ' . $errorMsg);
        }

        // Format narrative chunks (for synthesis)
        $narrativeChunks = $this->formatNarrativeChunks(
            $response['narrative']['chunks'] ?? [],
            array_column($narrativeDatasets, 'name')
        );

        // Format citation chunks (preserve metatags)
        $citationChunks = $this->formatCitationChunks(
            $response['citations']['chunks'] ?? []
        );

        return [
            'narrative_chunks' => $narrativeChunks,
            'citation_chunks' => $citationChunks,
        ];
    }

    /**
     * Format narrative chunks for clinical synthesis.
     */
    protected function formatNarrativeChunks(array $rawChunks, array $guidelineNames): array
    {
        $formatted = [];

        foreach ($rawChunks as $chunk) {
            $content = $chunk['content'] ?? $chunk['content_with_weight'] ?? '';
            if (empty($content)) continue;

            // Truncate long content
            if (strlen($content) > 1000) {
                $content = substr($content, 0, 1000) . '...';
            }

            $formatted[] = [
                'type' => 'narrative',
                'content' => $content,
                'source_guideline' => $chunk['_source_guideline'] ?? ($guidelineNames[0] ?? 'ESVS Guidelines'),
                'similarity' => round(($chunk['similarity'] ?? 0) * 100, 1),
            ];
        }

        return $formatted;
    }

    /**
     * Format citation chunks with preserved metatags for verbatim citation.
     */
    protected function formatCitationChunks(array $rawChunks): array
    {
        $formatted = [];

        foreach ($rawChunks as $chunk) {
            $content = $chunk['content'] ?? $chunk['content_with_weight'] ?? '';
            if (empty($content)) continue;

            // Extract metatags
            $recId = '';
            $class = '';
            $level = '';
            $guideline = '';
            $text = $content;

            if (preg_match('/RECOMMENDATION_ID:\s*(Rec\s*\d+)/i', $content, $m)) {
                $recId = $m[1];
            }
            if (preg_match('/CLASS:\s*(Class\s*\S+)/i', $content, $m)) {
                $class = $m[1];
            }
            if (preg_match('/LEVEL:\s*(Level\s*\S+)/i', $content, $m)) {
                $level = $m[1];
            }
            if (preg_match('/GUIDELINE:\s*(.+?)(?:\n|RECOMMENDATION_ID:|$)/i', $content, $m)) {
                $guideline = trim($m[1]);
            }
            if (preg_match('/RECOMMENDATION_TEXT:\s*(.+?)(?=TRIPLES:|CLASS:|LEVEL:|$)/is', $content, $m)) {
                $text = trim($m[1]);
            }

            // Keep citation text clean and complete (no truncation for citations)
            if (strlen($text) > 500) {
                $text = substr($text, 0, 500) . '...';
            }

            $formatted[] = [
                'type' => 'citation',
                'recommendation_id' => $recId,
                'class' => $class,
                'level' => $level,
                'guideline' => $guideline,
                'text' => $text,
                'similarity' => round(($chunk['similarity'] ?? 0) * 100, 1),
            ];
        }

        return $formatted;
    }

    /**
     * System prompt for dual-source retrieval.
     */
    protected function getDualSystemPrompt(): string
    {
        return <<<'PROMPT'
You are an ESVS (European Society for Vascular Surgery) clinical guideline assistant.

## YOUR TASK
Answer vascular surgery questions using the provided evidence. You receive TWO types of chunks:

### NARRATIVE_CHUNKS (use_kg=true)
- Rich clinical context from full guideline text with knowledge graph expansion
- Use these for understanding and synthesizing your clinical answer
- Do NOT quote verbatim from these

### CITATION_CHUNKS (use_kg=false) 
- Exact recommendations with metatags: recommendation_id, class, level, guideline
- Use these for VERBATIM citations only
- Copy the exact text without modification

## RESPONSE FORMAT

🩺 **Clinical Synthesis**
- 3-6 bullet points answering the clinical question
- Draw insights from NARRATIVE_CHUNKS
- Reference recommendation numbers (e.g., "per Rec 12")
- End with: (ESVS [guideline name])

📑 **Recommendations used in this answer**
- ONLY use recommendations from CITATION_CHUNKS
- Format: **Rec [ID]** (Class [X], Level [Y]) — [Guideline]
  > "[EXACT verbatim text from citation_chunks]"

📌 **Guideline supporting statements**
- Include if relevant supporting statements exist
- Otherwise write: "No additional supporting statements retrieved."

## RULES
1. Never invent recommendations or class/level ratings
2. If CITATION_CHUNKS don't support your synthesis, note: "Direct recommendation not retrieved"
3. Keep synthesis concise but clinically complete
PROMPT;
    }
}
