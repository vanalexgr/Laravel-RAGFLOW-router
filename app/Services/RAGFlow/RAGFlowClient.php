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

    public function __construct(string $apiKey, string $baseUrl, int $timeout = 30)
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/') . '/';
        $this->timeout = $timeout;
        
        $this->httpClient = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => $this->timeout,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    protected function request(string $method, string $endpoint, array $data = []): array
    {
        try {
            $options = [];
            if (!empty($data)) {
                if ($method === 'GET') {
                    $options['query'] = $data;
                } else {
                    $options['json'] = $data;
                }
            }

            $response = $this->httpClient->request($method, $endpoint, $options);
            $body = $response->getBody()->getContents();
            
            return json_decode($body, true) ?? [];
        } catch (GuzzleException $e) {
            Log::error('RAGFlow API Error: ' . $e->getMessage());
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
