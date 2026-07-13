<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GraphRagService
{
    protected ?string $endpoint;

    protected ?string $apiKey;

    protected ?string $deployment;

    protected ?string $apiVersion;

    protected bool $isConfigured = false;

    public function __construct()
    {
        $this->endpoint = config('prism.providers.azure.endpoint');
        $this->apiKey = config('prism.providers.azure.api_key');
        $this->deployment = config('prism.providers.azure.deployment');
        $this->apiVersion = config('prism.providers.azure.api_version');

        $this->isConfigured = ! empty($this->endpoint) && ! empty($this->apiKey) && ! empty($this->deployment);
    }

    public function enabled(): bool
    {
        return (bool) config('graphrag.enabled', false);
    }

    public function intentEnabled(): bool
    {
        return (bool) config('graphrag.intent_enabled', true);
    }

    public function conceptGapCheckEnabled(): bool
    {
        return (bool) config('graphrag.concept_gap_check', true);
    }

    public function expand(string $question, array $selectedGuidelineKeys, ?array $intentProfile = null): array
    {
        $enabled = $this->enabled();
        if (! $enabled) {
            return ['enabled' => false];
        }

        $config = config('graphrag', []);
        $maxCore = max(1, (int) ($config['max_core_concepts'] ?? 8));
        $maxRelated = max(0, (int) ($config['max_related_concepts'] ?? 8));
        $maxQueryTerms = max(4, (int) ($config['max_query_terms'] ?? 12));
        $maxCandidates = max(10, (int) ($config['max_candidate_concepts'] ?? 60));

        $candidates = $this->collectCandidateConcepts($selectedGuidelineKeys, $intentProfile);
        if (count($candidates) > $maxCandidates) {
            $candidates = array_slice($candidates, 0, $maxCandidates);
        }

        $llmEnabled = (bool) ($config['llm_enabled'] ?? true);
        $useLlm = $llmEnabled && $this->isConfigured;

        $result = null;
        if ($useLlm) {
            $result = $this->expandWithLlm($question, $intentProfile, $candidates, $maxCore, $maxRelated);
        }

        if (! is_array($result)) {
            $result = $this->expandHeuristic($question, $intentProfile, $candidates, $maxCore, $maxRelated);
        }

        $core = $this->normalizeList($result['core_concepts'] ?? []);
        $related = $this->normalizeList($result['related_concepts'] ?? []);
        $slots = $this->normalizeSlots($result['slots'] ?? []);

        $core = array_slice($core, 0, $maxCore);
        $related = array_slice(array_values(array_diff($related, $core)), 0, $maxRelated);

        $retrievalTerms = $this->buildRetrievalTerms($core, $related, $slots, $maxQueryTerms);
        $citationTerms = $this->buildCitationTerms($core, $slots, $maxQueryTerms);

        $log = Log::channel('retrieval');
        $log->info('[GRAPHRAG] Concept expansion', [
            'core' => $core,
            'related' => $related,
            'slots' => $slots,
            'retrieval_terms' => $retrievalTerms,
            'citation_terms' => $citationTerms,
            'used_llm' => $useLlm && is_array($result) && ($result['used_llm'] ?? false),
        ]);

        return [
            'enabled' => true,
            'core_concepts' => $core,
            'related_concepts' => $related,
            'slots' => $slots,
            'retrieval_terms' => $retrievalTerms,
            'citation_terms' => $citationTerms,
            'used_llm' => (bool) ($result['used_llm'] ?? false),
        ];
    }

    protected function collectCandidateConcepts(array $selectedGuidelineKeys, ?array $intentProfile): array
    {
        $candidates = [];
        foreach ($selectedGuidelineKeys as $key) {
            $config = $this->getGuidelineConfig((string) $key);
            if (! $config) {
                continue;
            }
            foreach (($config['key_concepts'] ?? []) as $concept) {
                $concept = trim((string) $concept);
                if ($concept !== '') {
                    $candidates[] = $concept;
                }
            }
        }

        foreach (($intentProfile['key_terms'] ?? []) as $term) {
            $term = trim((string) $term);
            if ($term !== '') {
                $candidates[] = $term;
            }
        }

        $normalized = [];
        $out = [];
        foreach ($candidates as $term) {
            $norm = $this->normalizeTerm($term);
            if ($norm === '') {
                continue;
            }
            if (isset($normalized[$norm])) {
                continue;
            }
            $normalized[$norm] = true;
            $out[] = $term;
        }

        return $out;
    }

    protected function expandHeuristic(string $question, ?array $intentProfile, array $candidates, int $maxCore, int $maxRelated): array
    {
        $questionLower = mb_strtolower($question);
        $matched = [];

        foreach ($candidates as $term) {
            $termLower = mb_strtolower($term);
            if ($termLower !== '' && str_contains($questionLower, $termLower)) {
                $matched[] = $term;
            }
        }

        $core = [];
        foreach (($intentProfile['key_terms'] ?? []) as $term) {
            $term = trim((string) $term);
            if ($term !== '' && ! in_array($term, $core, true)) {
                $core[] = $term;
            }
        }
        foreach ($matched as $term) {
            if (! in_array($term, $core, true)) {
                $core[] = $term;
            }
        }
        if (empty($core)) {
            $core = array_slice($candidates, 0, $maxCore);
        }

        $related = [];
        foreach ($candidates as $term) {
            if (! in_array($term, $core, true)) {
                $related[] = $term;
            }
        }

        return [
            'core_concepts' => array_slice($core, 0, $maxCore),
            'related_concepts' => array_slice($related, 0, $maxRelated),
            'slots' => [],
            'used_llm' => false,
        ];
    }

    protected function expandWithLlm(string $question, ?array $intentProfile, array $candidates, int $maxCore, int $maxRelated): ?array
    {
        $log = Log::channel('retrieval');
        if (! $this->isConfigured) {
            return null;
        }

        $intent = trim((string) ($intentProfile['intent'] ?? ''));
        $questionType = trim((string) ($intentProfile['question_type'] ?? ''));
        $keyTerms = $intentProfile['key_terms'] ?? [];
        if (! is_array($keyTerms)) {
            $keyTerms = [];
        }
        $keyTerms = array_values(array_filter(array_map(fn ($v) => trim((string) $v), $keyTerms), fn ($v) => $v !== ''));

        $candidateText = '';
        if (! empty($candidates)) {
            $candidateText = implode(', ', array_slice($candidates, 0, 60));
        }

        $prompt = <<<PROMPT
Task: Map the question into a compact concept graph for vascular guideline retrieval.

Return ONLY valid JSON with:
{
  "core_concepts": [3-8 short clinical concepts],
  "related_concepts": [3-10 related concepts],
  "slots": {
    "anatomy": [],
    "pathology": [],
    "stage": [],
    "intervention": [],
    "imaging": [],
    "complications": []
  }
}

Rules:
- Prefer terms from the candidate concept list when possible.
- If needed, add close synonyms that would appear in ESVS guidelines.
- Keep terms short (1-4 words), no sentences, no duplicates.

Question: "{$question}"
Intent: "{$intent}"
Question type: "{$questionType}"
Key terms: ["{$this->joinJsonArray($keyTerms)}"]
Candidate concepts: {$candidateText}
PROMPT;

        try {
            $url = rtrim($this->endpoint, '/')."/openai/deployments/{$this->deployment}/chat/completions?api-version={$this->apiVersion}";

            $response = Http::timeout(7)
                ->withHeaders([
                    'api-key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post($url, [
                    'messages' => [
                        ['role' => 'system', 'content' => 'You extract clinical concepts for retrieval. Return ONLY JSON.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'max_completion_tokens' => 260,
                ]);

            if (! $response->successful()) {
                $log->warning('[GRAPHRAG] LLM expansion failed', [
                    'status' => $response->status(),
                ]);

                return null;
            }

            $content = trim((string) $response->json('choices.0.message.content', ''));
            if ($content === '') {
                return null;
            }

            $json = $this->extractJsonObject($content);
            if ($json === null) {
                $log->warning('[GRAPHRAG] LLM JSON extraction failed', ['content' => $content]);

                return null;
            }

            $decoded = json_decode($json, true);
            if (! is_array($decoded)) {
                $log->warning('[GRAPHRAG] LLM JSON parse failed', ['content' => $json]);

                return null;
            }

            $decoded['used_llm'] = true;

            return $decoded;
        } catch (\Throwable $e) {
            $log->warning('[GRAPHRAG] LLM exception', ['error' => $e->getMessage()]);

            return null;
        }
    }

    protected function extractJsonObject(string $content): ?string
    {
        $content = trim($content);
        if ($content === '') {
            return null;
        }

        // Strip code fences if present.
        if (preg_match('/```(?:json)?\\s*(.*?)```/is', $content, $m)) {
            $content = trim($m[1]);
        }

        $start = strpos($content, '{');
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escape = false;
        $len = strlen($content);

        for ($i = $start; $i < $len; $i++) {
            $ch = $content[$i];

            if ($inString) {
                if ($escape) {
                    $escape = false;

                    continue;
                }
                if ($ch === '\\\\') {
                    $escape = true;

                    continue;
                }
                if ($ch === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($ch === '"') {
                $inString = true;

                continue;
            }

            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($content, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    protected function buildRetrievalTerms(array $core, array $related, array $slots, int $maxTerms): array
    {
        $terms = [];
        foreach (array_merge($core, $related) as $term) {
            $this->pushTerm($terms, $term);
        }
        foreach ($slots as $slotTerms) {
            if (! is_array($slotTerms)) {
                continue;
            }
            foreach ($slotTerms as $term) {
                $this->pushTerm($terms, $term);
            }
        }

        return array_slice($terms, 0, $maxTerms);
    }

    protected function buildCitationTerms(array $core, array $slots, int $maxTerms): array
    {
        $terms = [];
        foreach ($core as $term) {
            $this->pushTerm($terms, $term);
        }
        foreach (['intervention', 'imaging', 'complications'] as $slot) {
            $slotTerms = $slots[$slot] ?? [];
            if (! is_array($slotTerms)) {
                continue;
            }
            foreach ($slotTerms as $term) {
                $this->pushTerm($terms, $term);
            }
        }

        return array_slice($terms, 0, $maxTerms);
    }

    protected function normalizeList(array $terms): array
    {
        $out = [];
        $seen = [];
        foreach ($terms as $term) {
            $term = trim((string) $term);
            $norm = $this->normalizeTerm($term);
            if ($norm === '' || isset($seen[$norm])) {
                continue;
            }
            $seen[$norm] = true;
            $out[] = $term;
        }

        return $out;
    }

    protected function normalizeSlots($slots): array
    {
        if (! is_array($slots)) {
            return [];
        }
        $out = [];
        foreach ($slots as $slot => $terms) {
            if (! is_array($terms)) {
                continue;
            }
            $out[$slot] = $this->normalizeList($terms);
        }

        return $out;
    }

    protected function normalizeTerm(string $term): string
    {
        $term = mb_strtolower(trim($term));
        $term = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $term) ?? $term;

        return trim(preg_replace('/\s+/u', ' ', $term) ?? $term);
    }

    protected function pushTerm(array &$terms, string $term): void
    {
        $term = trim((string) $term);
        if ($term === '') {
            return;
        }
        $norm = $this->normalizeTerm($term);
        if ($norm === '' || in_array($norm, array_map(fn ($t) => $this->normalizeTerm((string) $t), $terms), true)) {
            return;
        }
        $terms[] = $term;
    }

    protected function joinJsonArray(array $vals): string
    {
        if (empty($vals)) {
            return '';
        }

        return implode('", "', array_map(fn ($v) => str_replace('"', '', (string) $v), $vals));
    }

    protected function getGuidelineConfig(string $key): ?array
    {
        $categories = config('guidelines.categories', []);
        foreach ($categories as $category) {
            if (isset($category['guidelines'][$key])) {
                return $category['guidelines'][$key];
            }
        }

        return null;
    }
}
