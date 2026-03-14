<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Small pre-gate LLM call: given a clinical case description, return a structured
 * interpretation (diagnosis, anatomy, complications already present, missing parameters).
 * Used by the OpenWebUI adapter to give the LLM clinical context before it asks
 * clarification questions — so it acknowledges what is known and only asks what is
 * genuinely absent.
 */
class ClinicalGateService
{
    protected ?string $endpoint;
    protected ?string $apiKey;
    protected ?string $deployment;
    protected ?string $apiVersion;
    protected bool $isConfigured = false;

    public function __construct()
    {
        $this->endpoint   = config('prism.providers.azure.endpoint')    ?: env('AZURE_OPENAI_ENDPOINT');
        $this->apiKey     = config('prism.providers.azure.api_key')     ?: env('AZURE_OPENAI_API_KEY');
        $this->deployment = config('prism.providers.azure.deployment')  ?: env('AZURE_OPENAI_DEPLOYMENT', 'gpt-5-chat');
        $this->apiVersion = config('prism.providers.azure.api_version') ?: env('AZURE_OPENAI_VERSION', '2024-12-01-preview');

        $this->isConfigured = !empty($this->endpoint) && !empty($this->apiKey) && !empty($this->deployment);
    }

    /**
     * Interpret a clinical case and return structured gate context.
     *
     * @return array{
     *   diagnosis: string,
     *   anatomy: string,
     *   classification: string,
     *   complications_present: string[],
     *   missing_parameters: string[],
     *   summary: string
     * }
     */
    public function interpret(string $question, array $history = []): array
    {
        $fallback = [
            'diagnosis'             => '',
            'anatomy'               => '',
            'classification'        => '',
            'complications_present' => [],
            'missing_parameters'    => [],
            'summary'               => '',
        ];

        if (!$this->isConfigured || empty(trim($question))) {
            return $fallback;
        }

        $log = Log::channel('retrieval');

        $recentHistory = implode("\n", array_map(
            fn($h) => is_string($h) ? trim($h) : '',
            array_slice($history, -3)
        ));

        $prompt = $this->buildPrompt($question, $recentHistory);

        try {
            $url = rtrim($this->endpoint, '/')
                . "/openai/deployments/{$this->deployment}/chat/completions"
                . "?api-version={$this->apiVersion}";

            $response = Http::timeout(8)
                ->withHeaders([
                    'api-key'      => $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post($url, [
                    'messages' => [
                        [
                            'role'    => 'system',
                            'content' => 'You are a vascular surgery clinical expert. Return ONLY valid JSON — no prose, no markdown fences.',
                        ],
                        [
                            'role'    => 'user',
                            'content' => $prompt,
                        ],
                    ],
                    'max_tokens'  => 220,
                    'temperature' => 0,
                ]);

            if (!$response->successful()) {
                $log->warning('[CLINICAL GATE] LLM call failed', ['status' => $response->status()]);
                return $fallback;
            }

            $content = $response->json('choices.0.message.content', '');
            $parsed  = $this->parseJson($content);

            $log->info('[CLINICAL GATE] Interpretation', [
                'question_preview'   => substr($question, 0, 80),
                'classification'     => $parsed['classification'] ?? '',
                'complications'      => $parsed['complications_present'] ?? [],
                'missing_parameters' => $parsed['missing_parameters'] ?? [],
            ]);

            return array_merge($fallback, $parsed);

        } catch (\Exception $e) {
            $log->warning('[CLINICAL GATE] Exception', ['error' => $e->getMessage()]);
            return $fallback;
        }
    }

    protected function buildPrompt(string $question, string $recentHistory): string
    {
        $historyBlock = $recentHistory ? "\nPrior context:\n{$recentHistory}\n" : '';

        return <<<PROMPT
Analyse this vascular surgery case and return ONLY valid JSON with these exact fields:

- "diagnosis": brief diagnosis (e.g. "acute type B aortic dissection")
- "anatomy": key anatomical detail (e.g. "origin zone 2 above left subclavian, carotid extension")
- "classification": clinical classification (e.g. "non-A non-B complicated", "type B uncomplicated", "CLTI Rutherford 5")
- "complications_present": array of complication strings ALREADY described in the case (e.g. ["stroke", "carotid thrombus", "malperfusion"]) — only what is explicitly stated
- "missing_parameters": array of 1-2 strings for parameters genuinely absent that would change management (e.g. ["time from symptom onset", "haemodynamic stability"]) — omit anything already in the description
- "summary": one sentence clinical summary (e.g. "Acute non-A non-B arch dissection complicated by carotid thrombus and stroke; phase and haemodynamic status unknown")

Case: {$question}{$historyBlock}

Return ONLY the JSON object. No explanation.
PROMPT;
    }

    protected function parseJson(string $content): array
    {
        $content = trim($content);

        // Strip markdown fences if present
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
        $content = preg_replace('/\s*```$/', '', $content);

        // Extract first JSON object
        if (preg_match('/\{[\s\S]*\}/u', $content, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                // Ensure array fields
                foreach (['complications_present', 'missing_parameters'] as $key) {
                    if (!isset($decoded[$key]) || !is_array($decoded[$key])) {
                        $decoded[$key] = [];
                    }
                }
                // Ensure string fields
                foreach (['diagnosis', 'anatomy', 'classification', 'summary'] as $key) {
                    if (!isset($decoded[$key])) {
                        $decoded[$key] = '';
                    }
                }
                return $decoded;
            }
        }

        return [];
    }
}
