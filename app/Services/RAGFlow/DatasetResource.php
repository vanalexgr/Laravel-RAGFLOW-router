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
        return $this->client->post('datasets', $parameters);
    }

    public function list(array $parameters = []): array
    {
        return $this->client->get('datasets', $parameters);
    }

    public function get(string $datasetId): array
    {
        return $this->client->get("datasets/{$datasetId}");
    }

    public function update(string $datasetId, array $parameters): array
    {
        return $this->client->put("datasets/{$datasetId}", $parameters);
    }

    public function delete(string $datasetId): array
    {
        return $this->client->delete("datasets/{$datasetId}");
    }

    /**
     * Query/retrieve chunks from multiple datasets using the global retrieval endpoint
     *
     * @param array $datasetIds List of dataset IDs to query
     * @param array $parameters Query parameters:
     *   - question (required): Query text
     *   - top_k (optional): Number of results (default: 10)
     *   - similarity_threshold (optional): Minimum similarity score 0-1
     *   - keyword (optional): Boolean for keyword search
     *   - doc_ids (optional): Specific document IDs to search within
     * @return array
     */
    public function retrieve(array $datasetIds, array $parameters): array
    {
        $payload = array_merge($parameters, [
            'dataset_ids' => $datasetIds,
        ]);

        return $this->client->post('retrieval', $payload);
    }
}
