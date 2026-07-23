<?php

namespace App\Ai\Gate\Progress;

/**
 * No-op progress sink for batch runs, offline evals and tests.
 */
final class NullGateProgress implements GateProgress
{
    public function emit(string $stage, string $message, array $context = []): void
    {
        // intentionally empty
    }
}
