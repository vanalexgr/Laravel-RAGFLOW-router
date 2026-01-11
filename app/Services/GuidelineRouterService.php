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
}
