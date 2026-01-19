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
    protected string $routingMethod;
    protected string $bridgeUrl;

    public function __construct()
    {
        $this->endpoint = config('prism.providers.azure.endpoint') ?: env('AZURE_OPENAI_ENDPOINT');
        $this->apiKey = config('prism.providers.azure.api_key') ?: env('AZURE_OPENAI_API_KEY');
        $this->deployment = config('prism.providers.azure.deployment') ?: env('AZURE_OPENAI_DEPLOYMENT', 'gpt-5-chat');
        $this->apiVersion = config('prism.providers.azure.api_version') ?: env('AZURE_OPENAI_VERSION', '2024-12-01-preview');
        
        $this->isConfigured = !empty($this->endpoint) && !empty($this->apiKey) && !empty($this->deployment);
        $this->routingMethod = config('ragflow.routing_method', 'semantic');
        $this->bridgeUrl = config('ragflow.bridge_url', 'http://localhost:8000');
    }

    /**
     * Route query using semantic router (ultra-fast, ~10ms)
     * 
     * @return array Array of guideline keys with optional 'scores' key containing confidence values
     */
    public function selectGuidelinesViaSemantic(string $question, int $maxGuidelines = 3): array
    {
        $startTime = microtime(true);
        $log = Log::channel('retrieval');

        try {
            $response = Http::timeout(5)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post("{$this->bridgeUrl}/route", [
                    'query' => $question,
                    'max_routes' => $maxGuidelines,
                ]);

            if (!$response->successful()) {
                $log->warning('[SEMANTIC ROUTER] Request failed', [
                    'status' => $response->status(),
                ]);
                return [];
            }

            $data = $response->json();
            $duration = round((microtime(true) - $startTime) * 1000);

            $selected = array_map(fn($g) => $g['guideline_key'], $data['guidelines'] ?? []);
            
            // Build scores map for proportional chunk allocation
            $scores = [];
            foreach ($data['guidelines'] ?? [] as $g) {
                $scores[$g['guideline_key']] = $g['confidence'] ?? 0.5;
            }

            $log->info('[SEMANTIC ROUTER] Success', [
                'question_preview' => substr($question, 0, 80),
                'selected_keys' => $selected,
                'scores' => $scores,
                'duration_ms' => $duration,
                'router_duration_ms' => $data['duration_ms'] ?? null,
            ]);

            return ['keys' => $selected, 'scores' => $scores];

        } catch (\Exception $e) {
            $log->error('[SEMANTIC ROUTER] Exception', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Smart routing: uses configured method (semantic or llm) with fallback
     * 
     * @return array With 'keys' array of guideline keys and 'scores' map of key => confidence
     */
    public function routeQuery(string $question, int $maxGuidelines = 3): array
    {
        $log = Log::channel('retrieval');
        $method = $this->routingMethod;

        $log->info('[ROUTER] Routing query', [
            'method' => $method,
            'question_preview' => substr($question, 0, 60),
        ]);

        if ($method === 'semantic' || $method === 'semantic_with_llm_fallback') {
            $result = $this->selectGuidelinesViaSemantic($question, $maxGuidelines);
            $keys = $result['keys'] ?? [];
            
            if (!empty($keys)) {
                return $result;
            }

            if ($method === 'semantic_with_llm_fallback') {
                $log->info('[ROUTER] Semantic routing empty, falling back to LLM');
                $llmKeys = $this->selectGuidelines($question, $maxGuidelines);
                return ['keys' => $llmKeys, 'scores' => []];
            }

            return $result;
        }

        // Default: LLM routing (no scores available)
        $llmKeys = $this->selectGuidelines($question, $maxGuidelines);
        return ['keys' => $llmKeys, 'scores' => []];
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

        // Check if semantic routing is enabled
        if (in_array($this->routingMethod, ['semantic', 'semantic_with_llm_fallback'])) {
            $semanticResult = $this->selectGuidelinesViaSemantic($routingQuery, $maxGuidelines);
            $semanticKeys = $semanticResult['keys'] ?? [];
            $semanticScores = $semanticResult['scores'] ?? [];
            
            if (!empty($semanticKeys) || $this->routingMethod === 'semantic') {
                // Use semantic results, optionally do LLM expansion if enabled
                $expanded = $queryForExpansion;
                $queryExpansionEnabled = config('ragflow.query_expansion', false);
                if ($this->isConfigured && $queryExpansionEnabled) {
                    $expanded = $this->expandQuery($queryForExpansion);
                    $log->info('[QUERY EXPANSION] Enabled, expanded query');
                } else {
                    $log->info('[QUERY EXPANSION] Disabled, using original query');
                }
                $selected = $this->mergeDocumentAndQuestionRouting($semanticKeys, $documentAnalysis, $log, $maxGuidelines);
                
                $log->info('[SEMANTIC+EXPAND] Complete', [
                    'semantic_selected' => $semanticKeys,
                    'semantic_scores' => $semanticScores,
                    'final_selected' => $selected,
                    'duration_ms' => round((microtime(true) - $startTime) * 1000),
                ]);
                
                return ['selected' => $selected, 'expanded' => $expanded, 'scores' => $semanticScores, 'routing_method' => 'semantic'];
            }
            // Fallback to LLM routing if semantic returned empty and method allows fallback
            $log->info('[SEMANTIC ROUTER] Empty result, falling back to LLM routing');
        }

        if (!$this->isConfigured) {
            $log->warning('[PARALLEL LLM] Azure OpenAI not configured, using document analysis only');
            $selected = $this->mergeDocumentAndQuestionRouting([], $documentAnalysis, $log, $maxGuidelines);
            return ['selected' => $selected, 'expanded' => $queryForExpansion, 'scores' => [], 'routing_method' => 'document_only'];
        }

        $guidelineList = $this->buildGuidelineList();
        $routingPrompt = $this->buildPrompt($routingQuery, $guidelineList, $maxGuidelines);
        $expansionPrompt = $this->buildExpansionPrompt($queryForExpansion);

        $url = rtrim($this->endpoint, '/') . "/openai/deployments/{$this->deployment}/chat/completions?api-version={$this->apiVersion}";

        $log->info('[PARALLEL LLM] Starting routing + expansion', [
            'routing_query_preview' => substr($routingQuery, 0, 80),
            'expansion_includes_context' => ($expansionQuery !== null && $expansionQuery !== $routingQuery),
        ]);

        try {
            $responses = Http::pool(fn ($pool) => [
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

        if (!$this->isConfigured) {
            $log->warning('[QUERY EXPANSION] Azure OpenAI not configured, returning original query');
            return $question;
        }

        $prompt = <<<PROMPT
Expand this vascular surgery clinical query with medical synonyms and terminology to improve document retrieval.

Add relevant:
- Medical abbreviations (BCVI, AAA, PAD, CLI, CLTI, LEAD, DVT, PE, VTE, CAS, CEA, EVAR, TEVAR, FEVAR, TAA, TAAA)
- Alternate terms (e.g., "blunt carotid trauma" → "blunt cerebrovascular injury", "leg pain" → "intermittent claudication")
- Related anatomical terms and procedures

Return ONLY the expanded query as a single line. Keep original terms and add synonyms.

Original query: "{$question}"
PROMPT;

        try {
            $url = rtrim($this->endpoint, '/') . "/openai/deployments/{$this->deployment}/chat/completions?api-version={$this->apiVersion}";

            $response = Http::timeout(8)
                ->withHeaders([
                    'api-key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post($url, [
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are a medical terminology expert. Return only the expanded query, nothing else.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'max_tokens' => 200,
                    'temperature' => 0,
                ]);

            if (!$response->successful()) {
                $log->error('[QUERY EXPANSION] LLM call failed', [
                    'status' => $response->status(),
                ]);
                return $question;
            }

            $expanded = trim($response->json('choices.0.message.content', ''));
            
            if (empty($expanded) || strlen($expanded) < strlen($question)) {
                $log->warning('[QUERY EXPANSION] Invalid expansion, using original');
                return $question;
            }

            $duration = round((microtime(true) - $startTime) * 1000);
            $log->info('[QUERY EXPANSION] Success', [
                'original' => substr($question, 0, 80),
                'expanded' => substr($expanded, 0, 150),
                'duration_ms' => $duration,
            ]);

            return $expanded;

        } catch (\Exception $e) {
            $log->error('[QUERY EXPANSION] Exception', ['error' => $e->getMessage()]);
            return $question;
        }
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
}
