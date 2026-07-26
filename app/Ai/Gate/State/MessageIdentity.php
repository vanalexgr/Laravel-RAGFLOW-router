<?php

namespace App\Ai\Gate\State;

use DateTimeImmutable;

final class MessageIdentity
{
    public static function dedupeKey(
        string $conversationId,
        string $message,
        ?string $clientSuppliedId = null,
        ?DateTimeImmutable $receivedAt = null,
    ): string {
        if (trim((string) $clientSuppliedId) !== '') {
            return 'client:'.trim((string) $clientSuppliedId);
        }

        // The approved design includes a minute bucket in its fallback. It
        // limits false duplicate matches when identical text is intentionally
        // sent again much later, while retries in the same minute still merge.
        $minute = ($receivedAt ?? new DateTimeImmutable)->format('Y-m-d\TH:i');

        return 'synthetic:'.hash('sha256', implode("\n", [
            trim($conversationId),
            self::normalizeMessage($message),
            $minute,
        ]));
    }

    public static function normalizeMessage(string $message): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $message)));
    }
}
