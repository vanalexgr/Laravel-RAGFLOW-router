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
        $sessionId = $request->header('X-Session-ID', 'openwebui-' . Str::random(16));
        
        foreach ($messages as $msg) {
            if ($msg['role'] === 'user') {
                $userMessage = $msg['content'];
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

        try {
            $response = Agent::run('vascular_expert', $userMessage, $sessionId);
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
}
