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
            'guidelines' => 'required|array|min:1|max:3', // LLM must select 1-3 guidelines
            'guidelines.*' => 'string', // Each item must be a string
        ]);

        $question = $request->input('question');
        $history = $request->input('history', []);
        $guidelinesInput = $request->input('guidelines', []);

        // Validate and convert guideline names to keys
        $requestedKeys = [];
        $invalidGuidelines = [];

        foreach ($guidelinesInput as $guideline) {
            $guidelineKey = $this->validateGuideline($guideline);
            if ($guidelineKey) {
                $requestedKeys[] = $guidelineKey;
            } else {
                $invalidGuidelines[] = $guideline;
            }
        }

        if (!empty($invalidGuidelines)) {
            return response()->json([
                'error' => "Invalid guideline(s): " . implode(', ', $invalidGuidelines) . ". Check tool documentation for valid options."
            ], 400);
        }

        if (empty($requestedKeys)) {
            return response()->json([
                'error' => "No valid guidelines provided. LLM must select 1-3 guidelines."
            ], 400);
        }

        // Debug logging
        Log::info('Tool API Request', [
            'question' => $question,
            'history_count' => count($history),
            'guidelines_selected' => $requestedKeys,
            'guidelines_count' => count($requestedKeys)
        ]);

        // Call retrieval service with optional guideline override
        $result = $this->retrievalService->retrieve($question, $history, $requestedKeys);

        // Format for LLM Consumption (same as ConsultGuidelines tool)
        $output = "RETRIEVED GUIDELINES for: \"{$result['question']}\"\n";
        $output .= "Guidelines Used: " . implode(', ', array_column($result['selected_guidelines'], 'name')) . "\n\n";

        $output .= "=== NARRATIVE EVIDENCE (Use for context) ===\n";
        foreach ($result['narrative_chunks'] as $chunk) {
            $output .= "- " . ($chunk['content'] ?? '') . "\n";
        }
        $output .= "\n";

        $output .= "=== CITATIONS (Use for verbatim quoting) ===\n";
        foreach ($result['citation_chunks'] as $chunk) {
            $meta = [];
            if (!empty($chunk['recommendation_id']))
                $meta[] = $chunk['recommendation_id'];
            if (!empty($chunk['class']))
                $meta[] = $chunk['class'];
            if (!empty($chunk['level']))
                $meta[] = $chunk['level'];
            $metaStr = !empty($meta) ? " [" . implode(', ', $meta) . "]" : "";

            $output .= "> \"{$chunk['text']}\"{$metaStr}\n";
        }

        // Add format reminder
        $output .= "\n\n=== IMPORTANT ===\n";
        $output .= "Present this using the mandatory response format:\n";
        $output .= "1. 🩺 Clinical Synthesis (3-6 bullets with inline citations)\n";
        $output .= "2. 📑 Recommendations used in this answer (verbatim quotes)\n";
        $output .= "3. 📌 Guideline supporting statements\n";

        // Debug: Log what we're returning
        Log::info('Tool API Response', [
            'question' => $question,
            'text_length' => strlen($output),
            'text_preview' => substr($output, 0, 200),
            'has_content' => !empty($output)
        ]);

        // Return simple JSON for OpenWebUI Action
        return response()->json([
            'result' => $output
        ])->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'POST, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
    }

    /**
     * Validate guideline name and return the key if valid
     */
    protected function validateGuideline(string $guideline): ?string
    {
        // Build a map of display names -> keys
        $categories = config('guidelines.categories', []);
        $nameMap = [];

        foreach ($categories as $category) {
            foreach ($category['guidelines'] as $key => $info) {
                // Accept both the key and the display name
                $nameMap[strtolower($key)] = $key;
                $nameMap[strtolower($info['name'])] = $key;
            }
        }

        $guidelineLower = strtolower(trim($guideline));
        return $nameMap[$guidelineLower] ?? null;
    }
}
