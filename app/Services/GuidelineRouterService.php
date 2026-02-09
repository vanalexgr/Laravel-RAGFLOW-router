<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GuidelineRouterService
{
    protected ?string $endpoint;
    protected ?string $apiKey;
    protected ?string $deployment;
    protected ?string $apiVersion;
    protected bool $isConfigured = false;

    public function __construct()
    {
        $this->endpoint = config('prism.providers.azure.endpoint') ?: env('AZURE_OPENAI_ENDPOINT');
        $this->apiKey = config('prism.providers.azure.api_key') ?: env('AZURE_OPENAI_API_KEY');
        $this->deployment = config('prism.providers.azure.deployment') ?: env('AZURE_OPENAI_DEPLOYMENT', 'gpt-5-chat');
        $this->apiVersion = config('prism.providers.azure.api_version') ?: env('AZURE_OPENAI_VERSION', '2024-12-01-preview');

        $this->isConfigured = !empty($this->endpoint) && !empty($this->apiKey) && !empty($this->deployment);
    }

    /**
     * Smart routing: uses LLM-based routing with abbreviation expansion and guardrails.
     * 
     * @return array With 'keys' array of guideline keys and 'scores' map of key => confidence
     */
    public function routeQuery(string $question, int $maxGuidelines = 2): array
    {
        $log = Log::channel('retrieval');

        $log->info('[ROUTER] Routing query (LLM)', [
            'question_preview' => substr($question, 0, 60),
        ]);

        // Abbreviation expansion
        $expansionEnabled = config('router_abbreviations.enabled', true);
        $expandedQuery = $question;
        $expansionDebug = null;

        if ($expansionEnabled) {
            try {
                $expander = app(\App\Services\Routing\QueryExpander::class);
                $result = $expander->expand($question);
                $expandedQuery = $result->expandedQuery;
                $expansionDebug = $result;

                $log->info('[ABBREVIATION] Query expanded', [
                    'detected' => $result->detectedAcronyms,
                    'expansions' => $result->appliedExpansions,
                    'conflicts' => $result->conflicts,
                    'time_ms' => $result->expansionTimeMs,
                ]);
            } catch (\Exception $e) {
                $log->warning('[ABBREVIATION] Expansion failed, using original query', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // LLM routing
        $llmKeys = $this->selectGuidelines($expandedQuery, $maxGuidelines);
        $result = ['keys' => $llmKeys, 'scores' => [], 'expansion_debug' => $expansionDebug];

        // Apply guardrails
        $guardrailsEnabled = config('router_abbreviations.guardrails_enabled', true);

        if ($guardrailsEnabled && !empty($llmKeys)) {
            try {
                $guardrails = app(\App\Services\Routing\GuardrailDecider::class);
                $guardrailResult = $guardrails->apply($expandedQuery, $result);

                return [
                    'keys' => $guardrailResult->selectedRoutes,
                    'scores' => [],
                    'expansion_debug' => $expansionDebug,
                    'guardrail_debug' => $guardrailResult,
                ];
            } catch (\Exception $e) {
                $log->warning('[GUARDRAILS] Failed to apply, using original routing', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    public function selectGuidelines(string $question, int $maxGuidelines = 3): array
    {
        $startTime = microtime(true);
        $log = Log::channel('retrieval');

        $log->info('[LLM ROUTER] Question received for routing', [
            'question_preview' => substr($question, 0, 80) . (strlen($question) > 80 ? '...' : ''),
            'question_length' => strlen($question),
        ]);

        if (!$this->isConfigured) {
            $log->warning('[LLM ROUTER] Azure OpenAI not configured, skipping LLM routing');
            return [];
        }

        $guidelineList = $this->buildGuidelineList();
        $prompt = $this->buildPrompt($question, $guidelineList, $maxGuidelines);

        try {
            $url = rtrim($this->endpoint, '/') . "/openai/deployments/{$this->deployment}/chat/completions?api-version={$this->apiVersion}";

            $log->debug('[LLM ROUTER] Calling Azure OpenAI', [
                'url' => $url,
                'deployment' => $this->deployment,
            ]);

            $response = Http::timeout(10)
                ->withHeaders([
                    'api-key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post($url, [
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are a medical guideline router. Return ONLY valid JSON.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'max_tokens' => 150,
                    'temperature' => 0,
                ]);

            if (!$response->successful()) {
                $log->error('LLM routing failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return [];
            }

            $content = $response->json('choices.0.message.content', '');
            $selected = $this->parseResponse($content);

            $duration = round((microtime(true) - $startTime) * 1000);
            $log->info('LLM guideline routing', [
                'question' => substr($question, 0, 100),
                'selected_keys' => $selected,
                'duration_ms' => $duration,
            ]);

            return $selected;

        } catch (\Exception $e) {
            $log->error('LLM routing exception', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    protected function buildGuidelineList(): string
    {
        $categories = config('guidelines.categories', []);
        $lines = [];

        foreach ($categories as $category) {
            foreach ($category['guidelines'] as $key => $guideline) {
                $concepts = implode(', ', array_slice($guideline['key_concepts'], 0, 5));
                $lines[] = "- {$key}: {$guideline['name']} ({$concepts})";
            }
        }

        return implode("\n", $lines);
    }

    protected function buildPrompt(string $question, string $guidelineList, int $max): string
    {
        return <<<PROMPT
Select 1-{$max} ESVS vascular surgery guidelines most relevant to this clinical question.

Available guidelines:
{$guidelineList}

Question: "{$question}"

Return ONLY a JSON object with a "selected" array of guideline keys. Example:
{"selected": ["carotid_vertebral", "abdominal_aortic_aneurysm"]}

If the question spans multiple topics (e.g., "AAA and carotid"), include all relevant guidelines.
If the question is too vague or unrelated, return {"selected": []}.
PROMPT;
    }

    protected function parseResponse(string $content): array
    {
        $content = trim($content);

        if (preg_match('/\{.*\}/s', $content, $matches)) {
            $content = $matches[0];
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            if (isset($decoded['selected']) && is_array($decoded['selected'])) {
                $validKeys = $this->getValidGuidelineKeys();
                return array_values(array_filter($decoded['selected'], fn($k) => in_array($k, $validKeys)));
            }
        } catch (\JsonException $e) {
            Log::channel('retrieval')->warning('Failed to parse LLM routing response', [
                'content' => $content,
                'error' => $e->getMessage(),
            ]);
        }

        return [];
    }

    protected function getValidGuidelineKeys(): array
    {
        $keys = [];
        $categories = config('guidelines.categories', []);

        foreach ($categories as $category) {
            foreach ($category['guidelines'] as $key => $guideline) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Parallel LLM calls for guideline routing and query expansion.
     * 
     * @param string $routingQuery Query for guideline selection (question only, no patient context)
     * @param int $maxGuidelines Maximum guidelines to select
     * @param string|null $expansionQuery Optional separate query for expansion (can include patient context)
     * @param array|null $documentAnalysis Optional document analysis with extracted entities and guideline scores
     */
    public function selectAndExpand(string $routingQuery, int $maxGuidelines = 3, ?string $expansionQuery = null, ?array $documentAnalysis = null): array
    {
        $startTime = microtime(true);
        $log = Log::channel('retrieval');

        // Use routing query for expansion if no separate expansion query provided
        $queryForExpansion = $expansionQuery ?? $routingQuery;

        // NEW: Always expand abbreviations first (matches routeQuery behavior)
        if (config('router_abbreviations.enabled', true)) {
            $routingQuery = $this->expandQuery($routingQuery);
            if ($routingQuery !== ($expansionQuery ?? $routingQuery)) {
                $log->info('[ROUTING] Query expanded via regex', ['query' => $routingQuery]);
            }
        }

        // If not configured for LLM, use document analysis only
        if (!$this->isConfigured) {
            $log->warning('[ROUTER] Azure OpenAI not configured, using document analysis only');
            $selected = $this->mergeDocumentAndQuestionRouting([], $documentAnalysis, $log, $maxGuidelines);
            return ['selected' => $selected, 'expanded' => $queryForExpansion, 'scores' => [], 'routing_method' => 'document_only'];
        }

        // Run routing and expansion in parallel using LLM
        Log::info('[ROUTER] Running parallel LLM routing + expansion');

        $guidelineList = $this->buildGuidelineList();
        $routingPrompt = $this->buildPrompt($routingQuery, $guidelineList, $maxGuidelines);
        $expansionPrompt = $this->buildExpansionPrompt($queryForExpansion);

        $url = rtrim($this->endpoint, '/') . "/openai/deployments/{$this->deployment}/chat/completions?api-version={$this->apiVersion}";

        $log->info('[PARALLEL LLM] Starting routing + expansion', [
            'routing_query_preview' => substr($routingQuery, 0, 80),
            'expansion_includes_context' => ($expansionQuery !== null && $expansionQuery !== $routingQuery),
        ]);

        try {
            $responses = Http::pool(fn($pool) => [
                $pool->as('routing')
                    ->timeout(10)
                    ->withHeaders(['api-key' => $this->apiKey, 'Content-Type' => 'application/json'])
                    ->post($url, [
                        'messages' => [
                            ['role' => 'system', 'content' => 'You are a medical guideline router. Return ONLY valid JSON.'],
                            ['role' => 'user', 'content' => $routingPrompt],
                        ],
                        'max_tokens' => 150,
                        'temperature' => 0,
                    ]),
                $pool->as('expansion')
                    ->timeout(10)
                    ->withHeaders(['api-key' => $this->apiKey, 'Content-Type' => 'application/json'])
                    ->post($url, [
                        'messages' => [
                            ['role' => 'system', 'content' => 'You are a medical terminology expert. Return only the expanded query, nothing else.'],
                            ['role' => 'user', 'content' => $expansionPrompt],
                        ],
                        'max_tokens' => 200,
                        'temperature' => 0,
                    ]),
            ]);

            $llmSelected = [];
            if ($responses['routing']->successful()) {
                $content = $responses['routing']->json('choices.0.message.content', '');
                $llmSelected = $this->parseResponse($content);
            }

            $expanded = $queryForExpansion;
            if ($responses['expansion']->successful()) {
                $exp = trim($responses['expansion']->json('choices.0.message.content', ''));
                if (!empty($exp) && strlen($exp) >= strlen($routingQuery)) {
                    $expanded = $exp;
                }
            }

            $selected = $this->mergeDocumentAndQuestionRouting($llmSelected, $documentAnalysis, $log, $maxGuidelines);

            $duration = round((microtime(true) - $startTime) * 1000);
            $log->info('[PARALLEL LLM] Complete', [
                'llm_selected_keys' => $llmSelected,
                'document_recommended' => $documentAnalysis['recommended_guidelines'] ?? [],
                'final_selected_keys' => $selected,
                'expanded_preview' => substr($expanded, 0, 100),
                'duration_ms' => $duration,
            ]);

            return ['selected' => $selected, 'expanded' => $expanded, 'scores' => [], 'routing_method' => 'llm'];

        } catch (\Exception $e) {
            $log->error('[PARALLEL LLM] Exception', ['error' => $e->getMessage()]);
            $selected = $this->mergeDocumentAndQuestionRouting([], $documentAnalysis, $log, $maxGuidelines);
            return ['selected' => $selected, 'expanded' => $queryForExpansion, 'scores' => [], 'routing_method' => 'fallback'];
        }
    }

    protected function buildExpansionPrompt(string $question): string
    {
        return <<<PROMPT
Expand this vascular surgery clinical query with medical synonyms and terminology to improve document retrieval.

Add relevant:
- Medical abbreviations (BCVI, AAA, PAD, CLI, CLTI, LEAD, DVT, PE, VTE, CAS, CEA, EVAR, TEVAR, FEVAR, TAA, TAAA)
- Alternate terms (e.g., "blunt carotid trauma" → "blunt cerebrovascular injury", "leg pain" → "intermittent claudication")
- Related anatomical terms and procedures

Return ONLY the expanded query as a single line. Keep original terms and add synonyms.

Original query: "{$question}"
PROMPT;
    }

    public function expandQuery(string $question): string
    {
        $startTime = microtime(true);
        $log = Log::channel('retrieval');

        if (config('router_abbreviations.enabled', true)) {
            try {
                $expander = app(\App\Services\Routing\QueryExpander::class);
                $result = $expander->expand($question);

                if (!empty($result->appliedExpansions)) {
                    $question = $result->expandedQuery;
                    $log->info('[QUERY EXPANSION] Regex applied, skipping LLM for focus', [
                        'expansions' => $result->appliedExpansions
                    ]);
                    return $question; // Return early to avoid signal dilution with too many LLM synonyms
                }
            } catch (\Exception $e) {
                $log->warning('[QUERY EXPANSION] Regex failed', ['error' => $e->getMessage()]);
            }
        }

        // DISABLED: LLM expansion causes keyword stuffing and poor semantic retrieval
        // For now, only use regex-based abbreviation expansion above
        $log->info('[QUERY EXPANSION] LLM expansion disabled, using original/regex-expanded query only');
        return $question;
    }

    protected function mergeDocumentAndQuestionRouting(array $llmSelected, ?array $documentAnalysis, $log, int $maxGuidelines = 4): array
    {
        $docRecommended = $documentAnalysis['recommended_guidelines'] ?? [];
        $docScores = $documentAnalysis['guideline_scores'] ?? [];

        if (empty($docRecommended) && empty($llmSelected)) {
            $log->info('[MERGE ROUTING] No routing from LLM or document analysis');
            return [];
        }

        if (empty($docRecommended)) {
            return array_slice($llmSelected, 0, $maxGuidelines);
        }

        if (empty($llmSelected)) {
            $log->info('[MERGE ROUTING] Using document-based routing only', [
                'doc_recommended' => $docRecommended,
            ]);
            return array_slice($docRecommended, 0, $maxGuidelines);
        }

        $merged = array_unique(array_merge($llmSelected, $docRecommended));

        usort($merged, function ($a, $b) use ($docScores, $llmSelected) {
            $scoreA = ($docScores[$a] ?? 0) + (in_array($a, $llmSelected) ? 10 : 0);
            $scoreB = ($docScores[$b] ?? 0) + (in_array($b, $llmSelected) ? 10 : 0);
            return $scoreB - $scoreA;
        });

        $result = array_slice($merged, 0, $maxGuidelines);

        $log->info('[MERGE ROUTING] Merged document + question routing', [
            'llm_selected' => $llmSelected,
            'doc_recommended' => $docRecommended,
            'merged_result' => $result,
        ]);

        return $result;
    }
    /**
     * Context-aware routing wrapper.
     * Rewrites ambiguous follow-up questions using history before routing.
     */
    public function routeWithContext(string $question, array $history, int $maxGuidelines = 2, ?array $documentAnalysis = null): array
    {
        $log = Log::channel('retrieval');
        $fused = $question;

        // Only fuse if we have history and it looks like a follow-up
        if (!empty($history) && $this->isLikelyFollowUp($question)) {
            $historyCount = count($history);
            $log->info("[CONTEXT] Detected potential follow-up, attempting fusion (history turns: $historyCount)", ['query' => $question]);
            $fused = $this->fuseContext($question, $history);
            if ($fused !== $question) {
                $log->info('[CONTEXT] Fused query result', [
                    'original' => $question,
                    'fused' => $fused,
                    'delta' => $fused === $question ? 'None' : 'Modified'
                ]);
            } else {
                $log->info('[CONTEXT] Fusion returned original query (no changes)');
            }
        }

        // Pass fused query as both routing and base expansion query
        return $this->selectAndExpand($fused, $maxGuidelines, null, $documentAnalysis);
    }

    protected function isLikelyFollowUp(string $question): bool
    {
        $question = trim($question);

        // Very short queries are likely follow-ups (e.g. "why?", "and for women?")
        if (strlen($question) < 15)
            return true;

        // Check for pronouns/connectors at start
        if (preg_match('/^(and|but|so|or|what about|how about|does it|is it|can (i|we)|if)/i', $question))
            return true;

        // Check for specific pronouns indicating dependency
        if (preg_match('/\b(it|they|this|that|these|those|he|she)\b/i', $question))
            return true;

        return false;
    }

    protected function fuseContext(string $question, array $history): string
    {
        if (empty($history) || !$this->isConfigured)
            return $question;

        $log = Log::channel('retrieval');

        // Filter out empty strings and ensure strings
        $history = array_filter($history, fn($h) => is_string($h) && !empty($h));

        $recentHistory = array_slice($history, -2); // Only last 2 turns to avoid confusion
        if (empty($recentHistory))
            return $question;

        $contextStr = implode("\n", $recentHistory);

        $prompt = <<<PROMPT
Previous User Questions:
{$contextStr}

Current User Question: "{$question}"

Rewrite the Current User Question to make it self-contained by resolving pronouns (it, they, this) and adding missing context from the Previous Questions.
If the Current Question is already specific and self-contained, return it unchanged.
Return ONLY the rewritten text.
PROMPT;

        try {
            $url = rtrim($this->endpoint, '/') . "/openai/deployments/{$this->deployment}/chat/completions?api-version={$this->apiVersion}";

            $response = Http::timeout(4) // Fast timeout for context fusion
                ->withHeaders([
                    'api-key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post($url, [
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are a helpful assistant that clarifies ambiguous questions. Return ONLY the rewritten question.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'max_tokens' => 100,
                    'temperature' => 0,
                ]);

            if ($response->successful()) {
                $rewritten = trim($response->json('choices.0.message.content', ''));
                if (!empty($rewritten)) {
                    return $rewritten;
                }
            }
        } catch (\Exception $e) {
            $log->warning('[CONTEXT] Fusion failed', ['error' => $e->getMessage()]);
        }

        return $question;
    }
}
