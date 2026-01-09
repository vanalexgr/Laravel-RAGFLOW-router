<?php

namespace App\Services\RAGFlow;

class ChatResource
{
    protected RAGFlowClient $client;

    public function __construct(RAGFlowClient $client)
    {
        $this->client = $client;
    }

    public function create(array $parameters): array
    {
        return $this->client->post('/chats', $parameters);
    }

    public function list(array $parameters = []): array
    {
        return $this->client->get('/chats', $parameters);
    }

    public function get(string $chatId): array
    {
        return $this->client->get("/chats/{$chatId}");
    }

    public function update(string $chatId, array $parameters): array
    {
        return $this->client->put("/chats/{$chatId}", $parameters);
    }

    public function delete(string $chatId): array
    {
        return $this->client->delete("/chats/{$chatId}");
    }

    public function sendMessage(string $chatId, array $parameters): array
    {
        return $this->client->post("/chats/{$chatId}/completions", $parameters);
    }

    public function sessions(string $chatId): ChatSessionResource
    {
        return new ChatSessionResource($this->client, $chatId);
    }
}
