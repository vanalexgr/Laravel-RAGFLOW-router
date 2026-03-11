<?php

namespace App\Services\RAGFlow;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class RAGFlowClient
{
    protected Client $httpClient;
    protected string $apiKey;
    protected string $baseUrl;
    protected int $timeout;
    protected bool $useBridge;
    protected string $bridgeUrl;
    protected ?string $bridgeSecret;

    public function __construct(string $apiKey, string $baseUrl, int $timeout = 30)
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/') . '/';
        $this->timeout = $timeout;
        $this->useBridge = config('ragflow.use_bridge', false);
        $this->bridgeUrl = rtrim(config('ragflow.bridge_url', 'http://localhost:7001'), '/');
        $this->bridgeSecret = config('ragflow.bridge_secret');
        
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        
        if (!$this->useBridge) {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }
        
        $this->httpClient = new Client([
            'base_uri' => $this->useBridge ? $this->bridgeUrl . '/' : $this->baseUrl,
            'timeout' => $this->timeout,
            'headers' => $headers,
        ]);
    }

    protected function request(string $method, string $endpoint, array $data = []): array
    {
        $targetUrl = $this->useBridge 
            ? $this->bridgeUrl . '/' . ltrim($endpoint, '/')
            : $this->baseUrl . ltrim($endpoint, '/');
        
        Log::channel('ragflow')->info("RAGFlow Request", [
            'method' => $method,
            'url' => $targetUrl,
            'via_bridge' => $this->useBridge,
            'payload_keys' => array_keys($data),
        ]);
        
        $startTime = microtime(true);
        
        try {
            $options = [];
            
            if ($this->useBridge && $this->bridgeSecret) {
                $options['headers'] = ['X-Bridge-Secret' => $this->bridgeSecret];
            }
            
            if (!empty($data)) {
                if ($method === 'GET') {
                    $options['query'] = $data;
                } else {
                    $options['json'] = $data;
                }
            }

            $response = $this->httpClient->request($method, $endpoint, $options);
            $body = $response->getBody()->getContents();
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            $decoded = json_decode($body, true) ?? [];
            
            $retrievalInfo = $decoded['retrieval_info'] ?? null;
            
            Log::channel('ragflow')->info("RAGFlow Response", [
                'url' => $targetUrl,
                'status' => $response->getStatusCode(),
                'duration_ms' => $duration,
                'chunk_count' => isset($decoded['data']['chunks'])
                    ? count($decoded['data']['chunks'])
                    : ((($decoded['narrative']['count'] ?? 0) + ($decoded['citations']['count'] ?? 0)) ?: null),
                'code' => $decoded['code'] ?? null,
                'message' => $decoded['message'] ?? null,
                'retrieval_info' => $retrievalInfo,
            ]);
            
            if ($retrievalInfo) {
                Log::channel('ragflow')->info("RAGFlow Retrieval Details", [
                    'rerank_id' => $retrievalInfo['rerank_id'] ?? 'not_set',
                    'use_kg' => $retrievalInfo['use_kg'] ?? false,
                    'top_k' => $retrievalInfo['top_k'] ?? null,
                    'size' => $retrievalInfo['size'] ?? null,
                    'chunk_count' => ($retrievalInfo['chunk_count'] ?? (($decoded['narrative']['count'] ?? 0) + ($decoded['citations']['count'] ?? 0))) ?: 0,
                    'narrative_count' => $decoded['narrative']['count'] ?? null,
                    'citation_count' => $decoded['citations']['count'] ?? null,
                    'top_chunks' => $retrievalInfo['top_chunks'] ?? [],
                ]);
            }
            
            return $decoded;
        } catch (GuzzleException $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Log::channel('ragflow')->error("RAGFlow Error", [
                'url' => $targetUrl,
                'duration_ms' => $duration,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('RAGFlow API request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function chat(): ChatResource
    {
        return new ChatResource($this);
    }

    public function datasets(): DatasetResource
    {
        return new DatasetResource($this);
    }

    public function documents(): DocumentResource
    {
        return new DocumentResource($this);
    }

    public function get(string $endpoint, array $data = []): array
    {
        return $this->request('GET', $endpoint, $data);
    }

    public function post(string $endpoint, array $data = []): array
    {
        return $this->request('POST', $endpoint, $data);
    }

    public function put(string $endpoint, array $data = []): array
    {
        return $this->request('PUT', $endpoint, $data);
    }

    public function delete(string $endpoint, array $data = []): array
    {
        return $this->request('DELETE', $endpoint, $data);
    }
}
