<?php

namespace App\Services\RAGFlow;

class DatasetResource
{
    protected RAGFlowClient $client;

    public function __construct(RAGFlowClient $client)
    {
        $this->client = $client;
    }

    public function create(array $parameters): array
    {
        return $this->client->post('/datasets', $parameters);
    }

    public function list(array $parameters = []): array
    {
        return $this->client->get('/datasets', $parameters);
    }

    public function get(string $datasetId): array
    {
        return $this->client->get("/datasets/{$datasetId}");
    }

    public function update(string $datasetId, array $parameters): array
    {
        return $this->client->put("/datasets/{$datasetId}", $parameters);
    }

    public function delete(string $datasetId): array
    {
        return $this->client->delete("/datasets/{$datasetId}");
    }
}
