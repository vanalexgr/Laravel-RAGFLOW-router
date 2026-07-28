<?php

namespace App\Ai\Gate\Progress;

use App\Services\PHIScrubberService;
use Illuminate\Support\Facades\Redis;

/**
 * Short-lived, pollable progress channel. Only workflow-authored status text and
 * an allowlist of non-clinical counters/identifiers are persisted.
 */
final class RedisGateProgress implements GateProgress
{
    private const CONTEXT_KEYS = [
        'guideline',
        'attempt',
        'iteration',
        'completed',
        'total',
    ];

    public function __construct(
        private readonly string $requestId,
        private readonly ?PHIScrubberService $scrubber,
        private readonly int $ttlSeconds = 300,
        bool $reset = true,
    ) {
        if ($reset) {
            Redis::del($this->eventsKey(), $this->doneKey());
            Redis::setex($this->doneKey(), $this->ttl(), '0');
        }
    }

    public function emit(string $stage, string $message, array $context = []): void
    {
        if ($this->scrubber === null) {
            throw new \LogicException('A PHI scrubber is required when emitting gate progress.');
        }

        $safeMessage = trim((string) $this->scrubber->scrub(
            mb_substr(preg_replace('/[\r\n]+/u', ' ', $message) ?? $message, 0, 180),
        )['scrubbed_text']);
        $safeContext = array_intersect_key($context, array_flip(self::CONTEXT_KEYS));
        $safeContext = array_filter(
            $safeContext,
            static fn (mixed $value): bool => is_int($value) || is_float($value)
                || is_bool($value) || (is_string($value) && mb_strlen($value) <= 80),
        );
        foreach ($safeContext as $key => $value) {
            if (is_string($value)) {
                $safeContext[$key] = (string) $this->scrubber->scrub($value)['scrubbed_text'];
            }
        }

        Redis::rpush($this->eventsKey(), json_encode([
            'stage' => $this->publicStage($stage),
            'message' => $safeMessage,
            'context' => $safeContext,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        Redis::expire($this->eventsKey(), $this->ttl());
        Redis::expire($this->doneKey(), $this->ttl());
    }

    /** @return array<int, array{stage: string, message: string, context: array<string, mixed>|\stdClass}> */
    public function emissions(): array
    {
        return array_values(array_filter(array_map(
            static function (mixed $value): ?array {
                $decoded = json_decode((string) $value, true);
                if (is_array($decoded) && ($decoded['context'] ?? null) === []) {
                    $decoded['context'] = new \stdClass;
                }

                return is_array($decoded) ? $decoded : null;
            },
            (array) Redis::lrange($this->eventsKey(), 0, -1),
        )));
    }

    public function complete(): void
    {
        Redis::setex($this->doneKey(), $this->ttl(), '1');
        Redis::expire($this->eventsKey(), $this->ttl());
    }

    public function done(): bool
    {
        return (string) Redis::get($this->doneKey()) === '1';
    }

    /**
     * Read an existing channel without clearing it.
     */
    public static function read(string $requestId): self
    {
        return new self(
            $requestId,
            null,
            (int) config('gate-v2.progress.ttl_seconds', 300),
            false,
        );
    }

    private function publicStage(string $stage): string
    {
        return match ($stage) {
            'probe', 'evaluate', 'critic' => 'weigh',
            'revise' => 'recheck',
            'knowledge_fast' => 'orient',
            'orient', 'retrieve', 'weigh', 'recheck', 'done' => $stage,
            default => 'working',
        };
    }

    private function eventsKey(): string
    {
        return 'gate-progress:'.hash('sha256', $this->requestId).':events';
    }

    private function doneKey(): string
    {
        return 'gate-progress:'.hash('sha256', $this->requestId).':done';
    }

    private function ttl(): int
    {
        return max(30, $this->ttlSeconds);
    }
}
