<?php

namespace App\Ai\Gate\State;

final class MessageIdentity
{
    public static function dedupeKey(
        string $conversationId,
        string $message,
        ?string $clientSuppliedId = null,
        ?int $turnIndex = null,
    ): string {
        if (trim((string) $clientSuppliedId) !== '') {
            return 'client:'.trim((string) $clientSuppliedId);
        }

        // Without a stable client ID, only the caller's conversation-scoped
        // monotonic turn index is sufficiently strong evidence of a retry.
        // If neither exists, deliberately make the event unique: guessing a
        // duplicate is less safe than processing a clinician turn twice.
        if ($turnIndex === null || $turnIndex < 1) {
            return 'undedupeable:'.bin2hex(random_bytes(16));
        }

        return 'synthetic:'.hash('sha256', implode("\n", [
            trim($conversationId),
            self::normalizeMessage($message),
            (string) $turnIndex,
        ]));
    }

    public static function normalizeMessage(string $message): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $message)));
    }
}
