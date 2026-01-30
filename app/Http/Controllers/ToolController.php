<?php

namespace App\Http\Controllers;

use App\Mcp\Tools\ConsultGuidelines;
use App\Services\RetrievalService;
use Illuminate\Http\Request;

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
        ]);
    }
}
