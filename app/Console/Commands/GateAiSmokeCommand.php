<?php

namespace App\Console\Commands;

use App\Ai\Gate\StructuredSmokeAgent;
use Illuminate\Console\Command;
use Throwable;

final class GateAiSmokeCommand extends Command
{
    protected $signature = 'gate:ai-smoke
        {--provider= : Laravel AI provider name}
        {--model= : Provider model name}';

    protected $description = 'Verify a live Laravel AI structured-output call';

    public function handle(): int
    {
        $provider = (string) ($this->option('provider') ?: config('gate-v2.provider'));
        $model = (string) ($this->option('model') ?: config('gate-v2.model'));
        $startedAt = microtime(true);

        try {
            $response = (new StructuredSmokeAgent)->prompt(
                'Set status to ready and items to ["structured", "cloud"].',
                provider: $provider,
                model: $model,
                timeout: 60,
            );
        } catch (Throwable $exception) {
            $this->error(sprintf(
                'FAIL provider=%s model=%s error=%s',
                $provider,
                $model,
                $exception->getMessage(),
            ));

            return self::FAILURE;
        }

        $payload = $response->toArray();
        $valid = ($payload['status'] ?? null) === 'ready'
            && ($payload['items'] ?? null) === ['structured', 'cloud'];
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        $this->line(json_encode([
            'ok' => $valid,
            'provider' => $provider,
            'model' => $model,
            'latency_ms' => $elapsedMs,
            'response' => $payload,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $valid ? self::SUCCESS : self::FAILURE;
    }
}
