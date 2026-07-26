<?php

namespace App\Ai\Gate\State\Events;

final readonly class MessageReceived implements StateEvent
{
    public function __construct(
        public string $dedupeKey,
        public string $conversationId,
        public int $turn,
        public string $receivedAt,
    ) {}

    public function type(): string
    {
        return 'MessageReceived';
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type(),
            'dedupe_key' => $this->dedupeKey,
            'conversation_id' => $this->conversationId,
            'turn' => $this->turn,
            'received_at' => $this->receivedAt,
        ];
    }
}
