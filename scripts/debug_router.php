<?php

use Illuminate\Contracts\Console\Kernel;
use App\Services\GuidelineRouterService;

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Force localhost for bridge routing (CLI context)
config(['ragflow.bridge_url' => 'http://localhost:8000']);

$query = "What the the recommended treatment for intermiitent claudication?";

echo "\n🔍 Debugging Query: \"$query\"\n";
echo "    --------------------------------------------------\n";

$router = app(GuidelineRouterService::class);

// Force update bridgeUrl via reflection in case it was already set
$ref = new \ReflectionClass($router);
if ($ref->hasProperty('bridgeUrl')) {
    $prop = $ref->getProperty('bridgeUrl');
    $prop->setAccessible(true);
    $prop->setValue($router, 'http://localhost:8000');
}

// 1. Get Raw Semantic Scores (bypassing threshold if possible? No, the service filters.)
// To see raw scores, we might need to peek into the logs OR just trust the filtered output.
// Actually, `selectGuidelinesViaSemantic` returns the *filtered* list now.
// But wait, the previous code I wrote *filtered* the return array too?
// Let's check the code I wrote in Step 1239.
// It builds `$scores` ONLY for selected items. 
// "Dropped candidate" logic logs it but doesn't return it.
// To satisfy the user request ("what is the relevance score for EACH dataset"), I need to see the dropped ones too.
// I can temporarily modify the service to print them, OR read the log.
// Reading the log is better.

echo "🚀 Running Semantic Router...\n";
$result = $router->routeQuery($query, 5); // Request more to see candidates

dump($result);

// Now let's try to tail the log to see dropped candidates
echo "\n📋 Checking Logs for Dropped Candidates:\n";
$logFile = storage_path('logs/retrieval.log');
if (file_exists($logFile)) {
    $lines = file($logFile);
    $lastLines = array_slice($lines, -20);
    foreach ($lastLines as $line) {
        if (str_contains($line, 'Dropped candidate') || str_contains($line, 'SEMANTIC ROUTER')) {
            echo $line;
        }
    }
}
