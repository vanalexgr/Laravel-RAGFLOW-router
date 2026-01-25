<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\GuidelineRouterService;

class RunGoldenSuite extends Command
{
    protected $signature = 'test:golden';
    protected $description = 'Run the Golden Validation Suite for Semantic Routing';

    public function handle(GuidelineRouterService $router)
    {
        // Force localhost for bridge routing to support CLI testing
        config(['ragflow.bridge_url' => 'http://localhost:8000']);

        $datasetPath = base_path('tests/golden_dataset.php');
        if (!file_exists($datasetPath)) {
            $this->error("Dataset not found at {$datasetPath}");
            return 1;
        }

        $dataset = require $datasetPath;

        $this->info("\n🏆 RUNNING GOLDEN VALIDATION SUITE");
        $this->line("================================================================================");

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

            $isMatch = ($primary === $expected);
            $status = $isMatch ? "✅ PASS" : "❌ FAIL";

            if ($isMatch) {
                $pass++;
            } else {
                $fail++;
            }

            $this->line(sprintf("%-4s %-50s [%s]", "#$num", substr($desc, 0, 50), $status));
            $this->line("      Q: \"$query\"");

            if (!$isMatch) {
                $this->error("      ❌ " . "Expected: $expected");
                $this->warn("      ⚠️ " . "Actual:   " . implode(', ', $selected));

                if (isset($result['expansion_debug'])) {
                    $this->line("      🔍 Expanded: " . $result['expansion_debug']->expandedQuery);
                    $this->line("      🔤 Acronyms: " . implode(', ', $result['expansion_debug']->detectedAcronyms));
                }

                if (isset($result['guardrail_debug']) && !empty($result['guardrail_debug']->decisions)) {
                    $this->line("      🛡️  Guardrail: " . json_encode($result['guardrail_debug']->decisions));
                }
            }
            $this->line("--------------------------------------------------------------------------------");
        }

        $duration = round(microtime(true) - $startTime, 2);
        $accuracy = round(($pass / $total) * 100, 1);

        $this->info("\n📊 FINAL REPORT");
        $this->line("====================");
        $this->line("Total Cases: $total");
        $this->line("Passed:      $pass");
        $this->line("Failed:      $fail");
        $this->line("Accuracy:    $accuracy%");
        $this->line("Time:        {$duration}s");

        if ($fail > 0) {
            $this->error("\n⚠️  REGRESSION DETECTED! Fix semantic logic before deploying.");
            return 1;
        } else {
            $this->info("\n✨ GOLDEN SUITE PASSED! Logic is solid.");
            return 0;
        }
    }
}
