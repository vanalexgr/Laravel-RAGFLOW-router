<?php

namespace App\Http\Controllers;

use App\Mcp\Tools\ConsultGuidelines;
use App\Services\RetrievalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ToolController extends Controller
{
    public function __construct(
        protected RetrievalService $retrievalService
    ) {
    }

    public function consult(Request $request)
    {
        // validate input
        $request->validate([
            'question' => 'required|string',
            'history' => 'nullable|array',
        ]);

        $question = $request->input('question');
        $history = $request->input('history', []);

        // Debug logging
        Log::info('Tool API Request', [
            'question' => $question,
            'history_count' => count($history),
            'history' => $history
        ]);

        // Instantiate the tool directly
        $tool = new ConsultGuidelines($this->retrievalService);

        // Execute logic
        $result = $tool->handle($question, $history);

        // Extract the text content from the MCP format
        // Expected format: ['content' => [['type' => 'text', 'text' => '...']]]
        $textContent = "";
        if (isset($result['content']) && is_array($result['content'])) {
            foreach ($result['content'] as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $textContent .= $block['text'];
                }
            }
        }

        // Return simple JSON for OpenWebUI Action
        return response()->json([
            'result' => $textContent
        ])->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'POST, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
    }
}
