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

    /**
     * Parallel retrieval across multiple datasets with per-dataset capping.
     * Uses the bridge's /retrieve_multi endpoint for asyncio.gather parallelization.
     *
     * @param array $datasets Array of ['id' => string, 'name' => string]
     * @param array $parameters Query parameters
     * @return array
     */
    public function retrieveMulti(array $datasets, array $parameters): array
    {
        $payload = array_merge($parameters, [
            'datasets' => $datasets,
        ]);

        return $this->client->post('retrieve_multi', $payload);
    }

    /**
     * Dual retrieval: narrative chunks (KG on) + citation chunks (KG off).
     * Uses the bridge's /retrieve_dual endpoint for parallel fetching.
     *
     * @param array $narrativeDatasets Array of ['id' => string, 'name' => string] for narrative retrieval
     * @param string $citationDatasetId ID of the recommendations-only dataset
     * @param array $parameters Query parameters including:
     *   - question (required): Query text
     *   - narrative_max (optional): Max narrative chunks (default: 8)
     *   - citation_max (optional): Max citation chunks (default: 4)
     * @return array
     */
    public function retrieveDual(array $narrativeDatasets, string $citationDatasetId, array $parameters): array
    {
        $payload = array_merge($parameters, [
            'narrative_datasets' => $narrativeDatasets,
            'citation_dataset_id' => $citationDatasetId,
        ]);

        return $this->client->post('retrieve_dual', $payload);
    }
}
