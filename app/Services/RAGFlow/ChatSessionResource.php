<?php

namespace App\Services\RAGFlow;

class ChatSessionResource
{
    protected RAGFlowClient $client;
    protected string $chatId;

    public function __construct(RAGFlowClient $client, string $chatId)
    {
        $this->client = $client;
        $this->chatId = $chatId;
    }

    public function create(array $parameters = []): array
    {
        return $this->client->post("chats/{$this->chatId}/sessions", $parameters);
    }

    public function list(array $parameters = []): array
    {
        return $this->client->get("chats/{$this->chatId}/sessions", $parameters);
    }

    public function get(string $sessionId): array
    {
        return $this->client->get("chats/{$this->chatId}/sessions/{$sessionId}");
    }

    public function update(string $sessionId, array $parameters): array
    {
        return $this->client->put("chats/{$this->chatId}/sessions/{$sessionId}", $parameters);
    }

    public function delete(string $sessionId): array
    {
        return $this->client->delete("chats/{$this->chatId}/sessions/{$sessionId}");
    }

    public function sendMessage(string $sessionId, array $parameters): array
    {
        return $this->client->post("chats/{$this->chatId}/completions", array_merge(
            $parameters,
            ['session_id' => $sessionId]
        ));
    }
}
