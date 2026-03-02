<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClinicalInterpreterService
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

    public function enabled(): bool
    {
        return (bool) config('clinical_interpreter.enabled', false);
    }

    /**
     * Interpret the query to produce retrieval terms and an optional clinical frame.
     *
     * @return array{enabled:bool, frame:?string, terms:array, must_terms:array, used_llm:bool}
     */
    public function interpret(string $question, ?array $intentProfile = null): array
    {
        if (!$this->enabled()) {
            return ['enabled' => false, 'frame' => null, 'terms' => [], 'must_terms' => [], 'used_llm' => false];
        }
        if (!$this->isConfigured) {
            return ['enabled' => true, 'frame' => null, 'terms' => [], 'must_terms' => [], 'used_llm' => false];
        }

        $maxTerms = max(4, (int) config('clinical_interpreter.max_terms', 10));
        $maxMust = max(1, (int) config('clinical_interpreter.max_must_terms', 4));
        $timeout = max(3, (int) config('clinical_interpreter.timeout', 6));

        $intent = trim((string) ($intentProfile['intent'] ?? ''));
        $keyTerms = $intentProfile['key_terms'] ?? [];
        if (!is_array($keyTerms)) {
            $keyTerms = [];
        }
        $keyTerms = array_values(array_filter(array_map(fn($v) => trim((string) $v), $keyTerms), fn($v) => $v !== ''));

        $prompt = <<<PROMPT
Task: Interpret this vascular clinical query to guide ESVS guideline retrieval.

Return ONLY valid JSON:
{
  "frame": "1-2 sentences describing the dominant clinical phenotype/decision axis. NO treatment or recommendations.",
  "retrieval_terms": ["short terms likely to appear in ESVS guidelines", "..."],
  "must_include_terms": ["most critical terms to ensure in evidence selection", "..."]
}

Rules:
- Do NOT give treatment advice or recommendations.
- Keep the frame neutral and descriptive.
- Prefer terms that would appear verbatim in guidelines.
- Include key syndrome synonyms if likely (e.g., cholesterol embolization, atheroembolism).
- Avoid long sentences in the term lists.

Question: "{$question}"
Intent: "{$intent}"
Key terms: ["{$this->joinJsonArray($keyTerms)}"]
PROMPT;

        try {
            $url = rtrim($this->endpoint, '/') . "/openai/deployments/{$this->deployment}/chat/completions?api-version={$this->apiVersion}";
            $response = Http::timeout($timeout)
                ->withHeaders([
                    'api-key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post($url, [
                    'messages' => [
                        ['role' => 'system', 'content' => 'Return ONLY valid JSON.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'max_tokens' => 220,
                    'temperature' => 0.2,
                ]);

            if (!$response->successful()) {
                Log::channel('retrieval')->warning('[CLINICAL INTERPRETER] LLM failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return ['enabled' => true, 'frame' => null, 'terms' => [], 'must_terms' => [], 'used_llm' => false];
            }

            $content = (string) $response->json('choices.0.message.content', '');
            $parsed = $this->parseJson($content);
            if (!is_array($parsed)) {
                return ['enabled' => true, 'frame' => null, 'terms' => [], 'must_terms' => [], 'used_llm' => false];
            }

            $frame = isset($parsed['frame']) ? trim((string) $parsed['frame']) : null;
            if ($frame === '') {
                $frame = null;
            }

            $terms = $this->normalizeList($parsed['retrieval_terms'] ?? []);
            $must = $this->normalizeList($parsed['must_include_terms'] ?? []);
            if (count($terms) > $maxTerms) {
                $terms = array_slice($terms, 0, $maxTerms);
            }
            if (count($must) > $maxMust) {
                $must = array_slice($must, 0, $maxMust);
            }

            Log::channel('retrieval')->info('[CLINICAL INTERPRETER] Interpreted query', [
                'frame' => $frame,
                'terms' => $terms,
                'must_terms' => $must,
            ]);

            return [
                'enabled' => true,
                'frame' => $frame,
                'terms' => $terms,
                'must_terms' => $must,
                'used_llm' => true,
            ];
        } catch (\Throwable $e) {
            Log::channel('retrieval')->warning('[CLINICAL INTERPRETER] Exception', [
                'error' => $e->getMessage(),
            ]);
            return ['enabled' => true, 'frame' => null, 'terms' => [], 'must_terms' => [], 'used_llm' => false];
        }
    }

    protected function parseJson(string $content): ?array
    {
        $content = trim($content);
        if ($content === '') {
            return null;
        }
        if (preg_match('/\{.*\}/s', $content, $matches)) {
            $content = $matches[0];
        }
        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function normalizeList($list): array
    {
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($list as $item) {
            $term = trim((string) $item);
            if ($term === '') {
                continue;
            }
            $norm = mb_strtolower($term);
            if (isset($seen[$norm])) {
                continue;
            }
            $seen[$norm] = true;
            $out[] = $term;
        }
        return $out;
    }

    protected function joinJsonArray(array $items): string
    {
        $escaped = array_map(fn($v) => str_replace('"', '\\"', (string) $v), $items);
        return implode('","', $escaped);
    }
}
