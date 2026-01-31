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

        // Call retrieval service
        $result = $this->retrievalService->retrieve($question, $history, $requestedKeys);

        // Select top 5 evidence items (deduplicated by rec_id)
        $evidence = $this->selectTopEvidence($result['citation_chunks'], 5);
        $guidelineNames = implode(', ', array_column($result['selected_guidelines'], 'name'));

        // Format as structured text for LLM
        $output = "# 📋 ESVS Guidelines Consultation\n\n";
        $output .= "**Query:** {$result['question']}\n\n";
        $output .= "**Guidelines:** {$guidelineNames}\n\n";

        // Context section (background for LLM reasoning)
        $output .= "---\n\n## 📚 Clinical Context\n\n";

        $ctxNum = 1;
        foreach (array_slice($result['narrative_chunks'], 0, 6) as $chunk) {
            $content = $this->cleanNarrativeContent($chunk['content'] ?? '');
            if (empty(trim($content)))
                continue;

            $source = $chunk['source_guideline'] ?? 'ESVS';
            $relevance = $chunk['similarity'] ?? 0;
            $output .= "**[ctx_{$ctxNum}] {$source}** *(relevance: {$relevance}%)*\n\n{$content}\n\n";
            $ctxNum++;
        }

        // Evidence section (citations)
        $output .= "---\n\n## 📑 Evidence (Cite These)\n\n";

        if (empty($evidence)) {
            $output .= "*No specific recommendations retrieved.*\n\n";
        } else {
            foreach ($evidence as $e) {
                $output .= "### {$e['cite_id']}: {$e['rec_id']}\n";
                $output .= "**{$e['guideline']}** | {$e['class']} | {$e['level']}\n\n";
                $output .= "> {$e['quote']}\n\n";
            }
        }

        $output .= "---\n\n## ⚠️ Response Format\n\n";
        $output .= "Use (E1), (E2), etc. to cite evidence in your answer.\n";

        // Debug log
        Log::info('Tool API Response', [
            'question' => $question,
            'evidence_count' => count($evidence),
        ]);

        // Return JSON with structured data for citation emission
        return response()->json([
            'result' => $output,
            'evidence' => $evidence,
            'narrative_chunks' => $result['narrative_chunks'],
            'citation_chunks' => $result['citation_chunks'],
        ])->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'POST, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
    }

    /**
     * Select top N evidence items, deduplicated by recommendation ID.
     */
    protected function selectTopEvidence(array $citationChunks, int $limit = 5): array
    {
        $evidence = [];
        $seenRecIds = [];

        // Sort by similarity (highest first)
        usort($citationChunks, fn($a, $b) => ($b['similarity'] ?? 0) <=> ($a['similarity'] ?? 0));

        foreach ($citationChunks as $chunk) {
            $recId = $chunk['recommendation_id'] ?? '';

            // Dedupe by rec_id
            if ($recId && in_array($recId, $seenRecIds))
                continue;
            if ($recId)
                $seenRecIds[] = $recId;

            $evidence[] = [
                'cite_id' => 'E' . (count($evidence) + 1),
                'rec_id' => $recId,
                'guideline' => $chunk['guideline'] ?? '',
                'class' => $chunk['class'] ?? '',
                'level' => $chunk['level'] ?? '',
                'quote' => $chunk['text'] ?? '',
                'score' => $chunk['similarity'] ?? 0,
            ];

            if (count($evidence) >= $limit)
                break;
        }

        return $evidence;
    }

    /**
     * Clean narrative content for readability.
     */
    protected function cleanNarrativeContent(string $content): string
    {
        // Remove entity blocks and visual descriptions
        $content = preg_replace('/----\s*Entities\s*----.*?(?=\n[A-Z]|\n\n\n|$)/s', '', $content);
        $content = preg_replace('/- Visual Type:.*?(?=\n\n|\n-\s+[A-Z]|$)/s', '', $content);
        $content = preg_replace('/^,Entity,Score,Description\n.*?(?=\n[A-Z]|\n\n|$)/ms', '', $content);
        $content = strip_tags($content);
        $content = preg_replace('/\n{3,}/', "\n\n", $content);

        if (strlen($content) > 500) {
            $content = substr($content, 0, 500) . '...';
        }

        return trim($content);
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
