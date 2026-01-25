<?php

use Illuminate\Contracts\Console\Kernel;
use App\Services\GuidelineRouterService;

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$router = app(GuidelineRouterService::class);
$dataset = require __DIR__ . '/golden_dataset.php';

echo "\n🏆 RUNNING GOLDEN VALIDATION SUITE\n";
echo "================================================================================\n";

$pass = 0;
$fail = 0;
$total = count($dataset);
$startTime = microtime(true);

foreach ($dataset as $i => $case) {
    $num = $i + 1;
    $query = $case['query'];
    $expected = $case['expected'];
    $desc = $case['desc'];

    // Run routing
    $result = $router->routeQuery($query, 3);
    $selected = $result['keys'] ?? [];
    $primary = $selected[0] ?? 'NONE';

    // Check match
    // We check if EXPECTED is the PRIMARY result (Top 1)
    $isMatch = ($primary === $expected);

    // Sometimes we accept it if it's in Top 2 for ambiguous cases, but Golden Suite targets Top 1.
    // Let's stick to Top 1 for rigour.

    $status = $isMatch ? "✅ PASS" : "❌ FAIL";

    if ($isMatch) {
        $pass++;
    } else {
        $fail++;
    }

    // Output
    echo sprintf("%-4s %-50s [%s]\n", "#$num", substr($desc, 0, 50), $status);
    echo "      Q: \"$query\"\n";
    if (!$isMatch) {
        echo "      ❌ Expected: $expected\n";
        echo "      ⚠️ Actual:   " . implode(', ', $selected) . "\n";

        // Debug expansion if available
        if (isset($result['expansion_debug'])) {
            echo "      🔍 Expanded: " . $result['expansion_debug']->expandedQuery . "\n";
            echo "      🔤 Acronyms: " . implode(', ', $result['expansion_debug']->detectedAcronyms) . "\n";
        }

        // Debug guardrail trigger
        if (isset($result['guardrail_debug']) && !empty($result['guardrail_debug']->decisions)) {
            echo "      🛡️  Guardrail: " . json_encode($result['guardrail_debug']->decisions) . "\n";
        }
    }
    echo "--------------------------------------------------------------------------------\n";
}

$duration = round(microtime(true) - $startTime, 2);
$accuracy = round(($pass / $total) * 100, 1);

echo "\n📊 FINAL REPORT\n";
echo "====================\n";
echo "Total Cases: $total\n";
echo "Passed:      $pass\n";
echo "Failed:      $fail\n";
echo "Accuracy:    $accuracy%\n";
echo "Time:        {$duration}s\n";

if ($fail > 0) {
    echo "\n⚠️  REGRESSION DETECTED! Fix semantic logic before deploying.\n";
    exit(1);
} else {
    echo "\n✨ GOLDEN SUITE PASSED! Logic is solid.\n";
    exit(0);
}
