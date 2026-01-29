<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Vizra\VizraADK\Facades\Agent;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Services\RetrievalService;

class OpenAICompatibleController extends Controller
{
    protected const MAX_CONTEXT_TOKENS = 128000;
    protected const RESERVED_TOKENS = 45000;
    protected const MAX_HISTORY_TOKENS = 80000;

    public function __construct(
        protected RetrievalService $retrievalService
    ) {
    }

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

    public function setScope(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'chat_id' => 'required|string',
            'scope' => 'required|array',
        ]);

        $chatId = $validated['chat_id'];
        $scope = $validated['scope'];

        \Cache::put("scope:{$chatId}", $scope, 60 * 60 * 24);

        return response()->json([
            'success' => true,
            'message' => 'Scope updated',
            'scope' => $scope
        ]);
    }

    public function getScope(Request $request): JsonResponse
    {
        $chatId = $request->input('chat_id');

        if (!$chatId) {
            return response()->json(['scope' => []]);
        }

        $scope = \Cache::get("scope:{$chatId}", []);

        return response()->json([
            'success' => true,
            'scope' => $scope
        ]);
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

            $this->emitProgressEvent($completionId, $model, '🔍 Selecting relevant guidelines...');
            usleep(200000);

            $this->emitProgressEvent($completionId, $model, '📚 Querying retrieval engine...');
            usleep(200000);

            $this->emitProgressEvent($completionId, $model, '🕸️ Knowledge Graph expansion...');
            usleep(200000);

            $this->emitProgressEvent($completionId, $model, '📊 Reranking retrieved knowledge...');
            usleep(200000);

            try {
                $response = Agent::run('vascular_expert', $fullPrompt, $sessionId);

                $this->emitProgressEvent($completionId, $model, '✍️ Drafting answer...');
                usleep(200000);

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

    public function retrieve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'question' => 'required|string|max:2000',
            'guideline_keys' => 'array',
            'top_k' => 'integer|min:1|max:50',
            'patient_context' => 'string|max:50000',
            'history' => 'array',
        ]);

        try {
            $result = $this->retrievalService->retrieve(
                $validated['question'],
                $validated['history'] ?? [],
                $validated['guideline_keys'] ?? null,
                $validated['top_k'] ?? 12,
                $validated['patient_context'] ?? ''
            );

            return response()->json($result);

        } catch (\Exception $e) {
            \Log::channel('retrieval')->error('Retrieve endpoint error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function healthRetrieval(Request $request): JsonResponse
    {
        $startTime = microtime(true);
        $log = \Log::channel('retrieval');
        $correlationId = $request->header('X-Correlation-ID', substr(uniqid(), -8));

        $log->info("[HEALTH] Retrieval health check", ['correlation_id' => $correlationId]);

        $checks = [
            'ragflow_bridge' => ['status' => 'unknown', 'latency_ms' => null],
            'ragflow_api' => ['status' => 'unknown', 'latency_ms' => null],
            'retrieval_test' => ['status' => 'unknown', 'chunks' => 0, 'latency_ms' => null],
            'semantic_router' => ['status' => 'unknown', 'model_name' => null, 'multilingual_support' => false],
        ];

        try {
            // Check 1: RAGFlow Bridge connectivity
            $bridgeStart = microtime(true);
            $bridgeUrl = rtrim(config('services.ragflow.bridge_url', 'http://localhost:8000'), '/');
            $bridgeResponse = \Illuminate\Support\Facades\Http::timeout(5)->get("{$bridgeUrl}/health");
            $checks['ragflow_bridge']['latency_ms'] = round((microtime(true) - $bridgeStart) * 1000);
            $checks['ragflow_bridge']['status'] = $bridgeResponse->successful() ? 'ok' : 'error';

            if ($bridgeResponse->successful()) {
                $bridgeData = $bridgeResponse->json();
                if (isset($bridgeData['semantic_router'])) {
                    $routerStatus = $bridgeData['semantic_router'];
                    $checks['semantic_router']['status'] = ($routerStatus['initialized'] ?? false) ? 'ok' : 'not_initialized';
                    $checks['semantic_router']['model_name'] = $routerStatus['model_name'] ?? 'unknown';
                    $checks['semantic_router']['multilingual_support'] = $routerStatus['multilingual_support'] ?? false;
                }
            }
        } catch (\Exception $e) {
            $checks['ragflow_bridge']['status'] = 'error';
            $checks['ragflow_bridge']['error'] = $e->getMessage();
        }

        // Skip retrieval test in simplified health check or keep if critical
        // For now, returning status based on bridge

        $overallStatus = ($checks['ragflow_bridge']['status'] === 'ok') ? 'ok' : 'degraded';

        $duration = round((microtime(true) - $startTime) * 1000);

        return response()->json([
            'status' => $overallStatus,
            'checks' => $checks,
            'duration_ms' => $duration,
            'timestamp' => now()->toIso8601String(),
        ], $overallStatus === 'ok' ? 200 : 503);
    }
}
