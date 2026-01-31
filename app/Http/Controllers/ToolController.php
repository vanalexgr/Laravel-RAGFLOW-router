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
            'guidelines' => 'nullable|array|max:3', // Encouraged but not required
            'guidelines.*' => 'string',
        ]);

        $question = $request->input('question');
        $history = $request->input('history', []);
        $guidelinesInput = $request->input('guidelines', null);

        // Validate and convert guideline names to keys
        $requestedKeys = null; // null = auto-routing

        if (!empty($guidelinesInput) && is_array($guidelinesInput)) {
            $validKeys = [];
            $invalidGuidelines = [];

            foreach ($guidelinesInput as $guideline) {
                $guidelineKey = $this->validateGuideline($guideline);
                if ($guidelineKey) {
                    $validKeys[] = $guidelineKey;
                } else {
                    $invalidGuidelines[] = $guideline;
                }
            }

            if (!empty($invalidGuidelines)) {
                return response()->json([
                    'error' => "Invalid guideline(s): " . implode(', ', $invalidGuidelines) . ". Check tool documentation for valid options."
                ], 400);
            }

            if (!empty($validKeys)) {
                $requestedKeys = $validKeys;
            }
        }

        // Debug logging
        Log::info('Tool API Request', [
            'question' => $question,
            'history_count' => count($history),
            'guidelines_selected' => $requestedKeys ?? 'auto',
            'selection_mode' => $requestedKeys ? 'explicit' : 'auto-routing'
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
            if (!empty($chunk['guideline']))
                $meta[] = $chunk['guideline'];
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

        // Return JSON with result and raw chunks for citation emission
        return response()->json([
            'result' => $output,
            'narrative_chunks' => $result['narrative_chunks'],
            'citation_chunks' => $result['citation_chunks'],
        ])->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'POST, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
    }

    protected function validateGuideline(string $guideline): ?string
    {
        $categories = config('guidelines.categories', []);
        $nameMap = [];
        $keywordMap = [];
        $allKeys = [];

        foreach ($categories as $category) {
            foreach ($category['guidelines'] as $key => $info) {
                $allKeys[] = $key;

                // Accept exact key
                $nameMap[strtolower($key)] = $key;
                // Accept exact display name
                $nameMap[strtolower($info['name'])] = $key;

                // Extract keywords from key name
                $keyWords = explode('_', strtolower($key));
                foreach ($keyWords as $word) {
                    if (strlen($word) > 2) {
                        $keywordMap[$word][] = $key;
                    }
                }

                // Also add key_concepts as exact matches and keywords
                foreach ($info['key_concepts'] ?? [] as $concept) {
                    $conceptLower = strtolower($concept);
                    $nameMap[$conceptLower] = $key;

                    // Also extract words from concepts
                    $conceptWords = preg_split('/[\s\-_]+/', $conceptLower);
                    foreach ($conceptWords as $word) {
                        if (strlen($word) > 2) {
                            $keywordMap[$word][] = $key;
                        }
                    }
                }
            }
        }

        $guidelineLower = strtolower(trim($guideline));

        // 1. Try exact match
        if (isset($nameMap[$guidelineLower])) {
            Log::info("[GUIDELINE MATCH] Exact: '{$guideline}' -> '{$nameMap[$guidelineLower]}'");
            return $nameMap[$guidelineLower];
        }

        // 2. Try keyword scoring
        $scores = [];
        foreach ($keywordMap as $keyword => $keys) {
            if (str_contains($guidelineLower, $keyword)) {
                foreach ($keys as $key) {
                    $scores[$key] = ($scores[$key] ?? 0) + 1;
                }
            }
        }

        if (!empty($scores)) {
            arsort($scores);
            $bestKey = array_key_first($scores);
            $bestScore = $scores[$bestKey];
            if ($bestScore >= 1) { // Accept even 1 keyword match
                Log::info("[GUIDELINE MATCH] Keyword: '{$guideline}' -> '{$bestKey}' (score: {$bestScore})");
                return $bestKey;
            }
        }

        // 3. Failed
        Log::warning("[GUIDELINE VALIDATION FAILED]", [
            'received' => $guideline,
            'available_keys' => $allKeys
        ]);

        return null;
    }
}
