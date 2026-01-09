<?php

namespace App\Services\RAGFlow;

class DocumentResource
{
    protected RAGFlowClient $client;

    public function __construct(RAGFlowClient $client)
    {
        $this->client = $client;
    }

    public function upload(string $datasetId, array $parameters): array
    {
        return $this->client->post("/datasets/{$datasetId}/documents", $parameters);
    }

    public function list(string $datasetId, array $parameters = []): array
    {
        return $this->client->get("/datasets/{$datasetId}/documents", $parameters);
    }

    public function get(string $datasetId, string $documentId): array
    {
        return $this->client->get("/datasets/{$datasetId}/documents/{$documentId}");
    }

    public function update(string $datasetId, string $documentId, array $parameters): array
    {
        return $this->client->put("/datasets/{$datasetId}/documents/{$documentId}", $parameters);
    }

    public function delete(string $datasetId, string $documentId): array
    {
        return $this->client->delete("/datasets/{$datasetId}/documents/{$documentId}");
    }

    public function parse(string $datasetId, array $documentIds): array
    {
        return $this->client->post("/datasets/{$datasetId}/documents/parse", [
            'document_ids' => $documentIds,
        ]);
    }

    public function chunks(string $datasetId, string $documentId, array $parameters = []): array
    {
        return $this->client->get("/datasets/{$datasetId}/documents/{$documentId}/chunks", $parameters);
    }
}
