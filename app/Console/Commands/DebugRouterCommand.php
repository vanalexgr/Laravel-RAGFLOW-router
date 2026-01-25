<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\GuidelineRouterService;
use Illuminate\Support\Facades\Log;

class DebugRouterCommand extends Command
{
    protected $signature = 'router:debug {query}';
    protected $description = 'Debug semantic routing scores for a query';

    public function handle()
    {
        $query = $this->argument('query');
        $this->info("🔍 Debugging: \"$query\"");

        config(['ragflow.bridge_url' => 'http://localhost:8000']);

        $router = app(GuidelineRouterService::class);
        $ref = new \ReflectionClass($router);
        if ($ref->hasProperty('bridgeUrl')) {
            $prop = $ref->getProperty('bridgeUrl');
            $prop->setAccessible(true);
            $prop->setValue($router, 'http://localhost:8000');
        }

        // Clear logs first to isolate this run
        $logFile = storage_path('logs/retrieval.log');
        file_put_contents($logFile, '');

        $result = $router->routeQuery($query, 5);

        $this->info("\n📦 Raw Structure:");
        dump($result);

        $keys = $result['keys'] ?? [];

        $this->info("\n👉 Result:");
        $this->table(['Key', 'Score (Configured)'], array_map(function ($key) use ($result) {
            return [$key, ($result['scores'] ?? [])[$key] ?? 'N/A'];
        }, $keys));

        if (isset($result['expansion_debug'])) {
            $this->info("\n🔤 Expansion:");
            $this->line("Original: " . $result['expansion_debug']->originalQuery);
            $this->line("Expanded: " . $result['expansion_debug']->expandedQuery);
        }

        if (isset($result['guardrail_debug'])) {
            $this->info("\n🛡️ Guardrails:");
            foreach ($result['guardrail_debug']->decisions as $decision) {
                $this->line("- Rule: {$decision['rule']} -> {$decision['action']} ({$decision['reason']})");
            }
        }

        $this->info("\n📋 Full Score Analysis (Logic + Dropped):");
        $logFile = storage_path('logs/laravel.log'); // Switch to main log
        if (file_exists($logFile)) {
            $lines = file($logFile);
            $lastLines = array_slice($lines, -50);
            foreach ($lastLines as $line) {
                // Show relevant errors or router logs
                if (str_contains($line, 'SEMANTIC ROUTER') || str_contains($line, 'Connection refused')) {
                    $this->line(trim($line));
                }
            }
        }
    }
}
