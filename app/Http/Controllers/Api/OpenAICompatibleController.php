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
}
