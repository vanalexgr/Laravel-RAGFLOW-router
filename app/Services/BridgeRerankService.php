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

    public function rerankDocuments(
        string $query,
        array $documents,
        int $topN,
        string $label,
        ?array $configOverride = null
    ): array {
        $config = array_replace($this->config, $configOverride ?? []);

        if (!(bool) ($config['enabled'] ?? false)) {
            return [];
        }

        if (empty($config['api_key'])) {
            Log::channel('ragflow')->warning('Bridge rerank enabled but API key is empty.', [
                'label' => $label,
            ]);
            return [];
        }

        $docs = [];
        $docMap = [];
        foreach ($documents as $i => $document) {
            $text = $this->truncateDocument((string) $document);
            if ($text === '') {
                continue;
            }
            $docMap[] = ['index' => $i];
            $docs[] = $text;
        }

        if (count($docs) < 2) {
            return [];
        }

        $topN = max(1, min($topN, count($docs)));

        $provider = strtolower((string) ($config['provider'] ?? 'cohere'));
        if ($provider !== 'cohere') {
            Log::channel('ragflow')->warning('Bridge rerank provider not supported', [
                'provider' => $provider,
                'label' => $label,
            ]);
            return [];
        }

        $payload = [
            'model' => $config['model'] ?? 'rerank-english-v3.0',
            'query' => $query,
            'documents' => $docs,
            'top_n' => $topN,
        ];

        $client = new Client([
            'timeout' => (int) ($config['timeout'] ?? ($this->config['timeout'] ?? 20)),
        ]);

        try {
            $response = $client->post($config['endpoint'], [
                'headers' => [
                    'Authorization' => 'Bearer ' . $config['api_key'],
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
            return [];
        }

        $body = json_decode($response->getBody()->getContents(), true);
        $results = $body['results'] ?? [];
        if (!$results) {
            return [];
        }

        $ranked = [];
        foreach ($results as $result) {
            $docIndex = $result['index'] ?? null;
            if ($docIndex === null || !isset($docMap[$docIndex])) {
                continue;
            }

            $ranked[] = [
                'index' => $docMap[$docIndex]['index'],
                'score' => $result['relevance_score'] ?? null,
            ];
        }

        if (!$ranked) {
            return [];
        }

        Log::channel('ragflow')->info('Bridge rerank applied', [
            'label' => $label,
            'model' => $payload['model'],
            'top_n' => $topN,
            'document_count' => count($documents),
        ]);

        return $ranked;
    }

    public function rerank(string $query, array $chunks, int $topN, string $label): array
    {
        if (!$this->enabled()) {
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

        $rankedIndices = $this->rerankDocuments($query, $docs, $topN, $label);
        if (!$rankedIndices) {
            return $chunks;
        }

        $rankedChunks = [];
        $used = [];
        foreach ($rankedIndices as $ranked) {
            $idx = $docMap[$ranked['index']]['chunk_index'];
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
