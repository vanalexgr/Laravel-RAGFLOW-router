<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\GuidelineRouterService;

$router = new GuidelineRouterService();
$question = "management of small AAA";
$history = ["Is ultrasound needed?", "How often?"];

echo "Testing Pipeline Observability\n";
echo "Question: $question\n";
echo "History: " . implode(' | ', $history) . "\n";
echo "---------------------------------\n";

// This will trigger:
// 1. routeWithContext (logging fusion)
// 2. selectAndExpand (logging expansion)
// 3. selectGuidelinesViaSemantic -> Bridge (logging scores)
// 4. GuardrailDecider (logging trace)

$results = $router->routeWithContext($question, $history);

echo "Final Selection: " . implode(', ', $results) . "\n";
echo "\nCheck 'storage/logs/retrieval.php' and 'bridge.log' for trace details.\n";
