<?php

namespace App\Ai\Gate\Progress;

use Illuminate\Support\Facades\Log;

/**
 * Writes gate progress to the retrieval log. Useful on Hetzner to watch the loop
 * bounce between stages in real time:
 *   tail -f storage/logs/retrieval-$(date +%F).log | grep '\[GATE PROGRESS\]'
 */
final class LogGateProgress implements GateProgress
{
    public function emit(string $stage, string $message, array $context = []): void
    {
        Log::channel('retrieval')->info('[GATE PROGRESS] '.$message, [
            'stage' => $stage,
        ] + $context);
    }
}
