<?php

namespace App\Ai\Gate\State;

interface StateEventStore
{
    /** @return array<int, array<string, mixed>> */
    public function load(string $conversationId): array;

    /** @param array<int, array<string, mixed>> $events */
    public function append(string $conversationId, int $expectedCount, array $events): void;
}
