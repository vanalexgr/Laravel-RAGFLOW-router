<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class BridgeRerankService
{
    protected array $config;
    protected Client $client;

    public function __construct()
    {
        $this->config = config('ragflow.bridge_rerank', []);
        $this->client = new Client([
            'timeout' => (int) ($this->config['timeout'] ?? 20),
        ]);
    }

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false);
    }

    public function rerank(string $query, array $chunks, int $topN, string $label): array
    {
        if (!$this->enabled()) {
            return $chunks;
        }

        if (empty($this->config['api_key'])) {
            Log::channel('ragflow')->warning('Bridge rerank enabled but BRIDGE_RERANK_API_KEY is empty.');
            return $chunks;
        }

        $docs = [];
        $docMap = [];
        foreach ($chunks as $i => $chunk) {
            $text = $chunk['content'] ?? $chunk['content_with_weight'] ?? '';
            $text = $this->truncateDocument($text);
            if ($text === '') {
                continue;
            }
            $docMap[] = ['chunk_index' => $i];
            $docs[] = $text;
        }

        if (count($docs) < 2) {
            return $chunks;
        }

        $topN = max(1, min($topN, count($docs)));

        $provider = strtolower((string) ($this->config['provider'] ?? 'cohere'));
        if ($provider !== 'cohere') {
            Log::channel('ragflow')->warning('Bridge rerank provider not supported', ['provider' => $provider]);
            return $chunks;
        }

        $payload = [
            'model' => $this->config['model'] ?? 'rerank-english-v3.0',
            'query' => $query,
            'documents' => $docs,
            'top_n' => $topN,
        ];

        try {
            $response = $this->client->post($this->config['endpoint'], [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->config['api_key'],
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => $payload,
            ]);
        } catch (GuzzleException $e) {
            Log::channel('ragflow')->warning('Bridge rerank request failed', [
                'label' => $label,
                'error' => $e->getMessage(),
            ]);
            return $chunks;
        }

        $body = json_decode($response->getBody()->getContents(), true);
        $results = $body['results'] ?? [];
        if (!$results) {
            return $chunks;
        }

        $rankedIndices = [];
        foreach ($results as $result) {
            $docIndex = $result['index'] ?? null;
            if ($docIndex === null || !isset($docMap[$docIndex])) {
                continue;
            }
            $chunkIndex = $docMap[$docIndex]['chunk_index'];
            $rankedIndices[] = [
                'chunk_index' => $chunkIndex,
                'score' => $result['relevance_score'] ?? null,
            ];
        }

        if (!$rankedIndices) {
            return $chunks;
        }

        $rankedChunks = [];
        $used = [];
        foreach ($rankedIndices as $ranked) {
            $idx = $ranked['chunk_index'];
            $used[$idx] = true;
            $chunk = $chunks[$idx];
            if ($ranked['score'] !== null) {
                $chunk['rerank_score'] = $ranked['score'];
            }
            $rankedChunks[] = $chunk;
        }

        // Append remaining chunks in original order.
        foreach ($chunks as $idx => $chunk) {
            if (!isset($used[$idx])) {
                $rankedChunks[] = $chunk;
            }
        }

        Log::channel('ragflow')->info('Bridge rerank applied', [
            'label' => $label,
            'model' => $payload['model'],
            'top_n' => $topN,
            'chunk_count' => count($chunks),
        ]);

        return $rankedChunks;
    }

    protected function truncateDocument(string $text, int $maxChars = 3000): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        if (strlen($text) <= $maxChars) {
            return $text;
        }
        return substr($text, 0, $maxChars);
    }
}
