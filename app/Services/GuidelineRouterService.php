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

    public function selectAndExpand(string $question, int $maxGuidelines = 3): array
    {
        $startTime = microtime(true);
        $log = Log::channel('retrieval');

        if (!$this->isConfigured) {
            $log->warning('[PARALLEL LLM] Azure OpenAI not configured');
            return ['selected' => [], 'expanded' => $question];
        }

        $guidelineList = $this->buildGuidelineList();
        $routingPrompt = $this->buildPrompt($question, $guidelineList, $maxGuidelines);
        $expansionPrompt = $this->buildExpansionPrompt($question);

        $url = rtrim($this->endpoint, '/') . "/openai/deployments/{$this->deployment}/chat/completions?api-version={$this->apiVersion}";

        $log->info('[PARALLEL LLM] Starting routing + expansion', [
            'question_preview' => substr($question, 0, 80),
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

            $selected = [];
            if ($responses['routing']->successful()) {
                $content = $responses['routing']->json('choices.0.message.content', '');
                $selected = $this->parseResponse($content);
            }

            $expanded = $question;
            if ($responses['expansion']->successful()) {
                $exp = trim($responses['expansion']->json('choices.0.message.content', ''));
                if (!empty($exp) && strlen($exp) >= strlen($question)) {
                    $expanded = $exp;
                }
            }

            $duration = round((microtime(true) - $startTime) * 1000);
            $log->info('[PARALLEL LLM] Complete', [
                'selected_keys' => $selected,
                'expanded_preview' => substr($expanded, 0, 100),
                'duration_ms' => $duration,
            ]);

            return ['selected' => $selected, 'expanded' => $expanded];

        } catch (\Exception $e) {
            $log->error('[PARALLEL LLM] Exception', ['error' => $e->getMessage()]);
            return ['selected' => [], 'expanded' => $question];
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
}
