<?php

namespace App\Ai\Gate\State;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Append-only database storage. Unlike Laravel Cache, this table is the
 * authoritative event log and has no TTL or eviction policy.
 */
final class LedgerEventStore implements StateEventStore
{
    /** @return array<int, array<string, mixed>> */
    public function load(string $conversationId): array
    {
        return DB::table('gate_state_events')
            ->where('conversation_key', $this->key($conversationId))
            ->orderBy('sequence')
            ->get(['payload'])
            ->map(static function (object $row): array {
                $payload = json_decode((string) $row->payload, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($payload)) {
                    throw new RuntimeException('Invalid gate state event payload.');
                }

                return $payload;
            })
            ->all();
    }

    /** @param array<int, array<string, mixed>> $events */
    public function append(string $conversationId, int $expectedCount, array $events): void
    {
        if ($events === []) {
            return;
        }

        DB::transaction(function () use ($conversationId, $expectedCount, $events): void {
            $conversationKey = $this->key($conversationId);
            $actualCount = DB::table('gate_state_events')
                ->where('conversation_key', $conversationKey)
                ->lockForUpdate()
                ->count();

            if ($actualCount !== $expectedCount) {
                throw new RuntimeException('Gate state ledger changed concurrently; retry the append.');
            }

            $rows = [];
            foreach (array_values($events) as $offset => $event) {
                $rows[] = [
                    'conversation_key' => $conversationKey,
                    'sequence' => $expectedCount + $offset + 1,
                    'payload' => json_encode(
                        $event,
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                    ),
                    'created_at' => now(),
                ];
            }

            DB::table('gate_state_events')->insert($rows);
        }, 3);
    }

    private function key(string $conversationId): string
    {
        return hash('sha256', $conversationId);
    }
}
