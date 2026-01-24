#!/usr/bin/env php
<?php

/**
 * Test Script for Enhanced Guardrail System (Phase 3A)
 * 
 * Tests 5 critical guardrail scenarios:
 * 1. TEVAR + Infection (VGEI collision)
 * 2. AAA Rupture (Trauma exclusion)
 * 3. Trauma + ALI (Collision add)
 * 4. Companion Antithrombotic
 * 5. Score Gap Analysis
 */

require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Foundation\Application;
use App\Services\GuidelineRouterService;

// Bootstrap Laravel
$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__ . '/routes/web.php', commands: __DIR__ . '/routes/console.php', health: '/up')
    ->withMiddleware(function ($middleware) { })
    ->withExceptions(function ($exceptions) { })->create();

$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Get router service
$router = $app->make(GuidelineRouterService::class);

// Test scenarios
$tests = [
    [
        'name' => 'TEVAR Infection (VGEI Collision)',
        'query' => '6 months after TEVAR persistent fever raised CRP peri-graft fluid on CTA',
        'expected_top' => 'vascular_graft_infections',
        'expected_companion' => 'descending_thoracic_aorta',
        'reason' => 'Infection markers + TEVAR should trigger VGEI PIN + add Thoracic via collision'
    ],
    [
        'name' => 'AAA Rupture (NOT Trauma)',
        'query' => 'ruptured AAA hemodynamically unstable hypotensive',
        'expected_top' => 'abdominal_aortic_aneurysm',
        'excluded' => 'vascular_trauma',
        'reason' => 'No trauma mechanism, should exclude Trauma guideline'
    ],
    [
        'name' => 'Trauma + ALI',
        'query' => 'GSW to femoral artery cold pulseless leg paralysis',
        'expected_top' => 'vascular_trauma',
        'expected_companion' => 'acute_limb_ischaemia',
        'reason' => 'Trauma mechanism + limb ischemia should return both'
    ],
    [
        'name' => 'PAD + Antithrombotic (Companion)',
        'query' => 'ABI 0.68 patient on aspirin developing bruising',
        'expected_companion' => 'antithrombotic_therapy',
        'reason' => 'Aspirin/bleeding keywords should add Antithrombotic as companion'
    ],
    [
        'name' => 'Acute Limb Ischemia (Classic)',
        'query' => 'sudden onset cold pulseless leg Rutherford IIb embolus',
        'expected_top' => 'acute_limb_ischaemia',
        'reason' => 'Classic ALI presentation should PIN ALI guideline'
    ],
];

echo "\n🧪 TESTING ENHANCED GUARDRAIL SYSTEM (Phase 3A)\n";
echo str_repeat("=", 80) . "\n\n";

$passed = 0;
$failed = 0;

foreach ($tests as $i => $test) {
    $testNum = $i + 1;
    echo "Test {$testNum}: {$test['name']}\n";
    echo str_repeat("-", 80) . "\n";
    echo "Query: {$test['query']}\n";
    echo "Expected: {$test['reason']}\n\n";

    try {
        // Route query
        $result = $router->routeQuery($test['query'], 3);

        $keys = $result['keys'] ?? [];
        $guardrail = $result['guardrail_debug'] ?? null;

        echo "📊 Result:\n";
        echo "  Selected: " . implode(', ', $keys) . "\n";

        if ($guardrail) {
            echo "  Rules Triggered: " . implode(', ', $guardrail->rulesTriggered) . "\n";
            echo "  Decisions:\n";
            foreach ($guardrail->decisions as $decision) {
                echo "    - [{$decision['action']}] {$decision['reason']}\n";
            }
        }

        // Validate expectations
        $testPassed = true;

        if (isset($test['expected_top'])) {
            if (($keys[0] ?? null) !== $test['expected_top']) {
                echo "  ❌ FAIL: Expected #{$test['expected_top']} at top, got " . ($keys[0] ?? 'none') . "\n";
                $testPassed = false;
            } else {
                echo "  ✅ PASS: {$test['expected_top']} at #1\n";
            }
        }

        if (isset($test['expected_companion'])) {
            if (!in_array($test['expected_companion'], $keys)) {
                echo "  ❌ FAIL: Expected {$test['expected_companion']} in results\n";
                $testPassed = false;
            } else {
                echo "  ✅ PASS: {$test['expected_companion']} included\n";
            }
        }

        if (isset($test['excluded'])) {
            if (in_array($test['excluded'], $keys)) {
                echo "  ❌ FAIL: {$test['excluded']} should be EXCLUDED\n";
                $testPassed = false;
            } else {
                echo "  ✅ PASS: {$test['excluded']} correctly excluded\n";
            }
        }

        if ($testPassed) {
            echo "\n✅ Test {$testNum} PASSED\n";
            $passed++;
        } else {
            echo "\n❌ Test {$testNum} FAILED\n";
            $failed++;
        }

    } catch (\Exception $e) {
        echo "❌ EXCEPTION: " . $e->getMessage() . "\n";
        echo "\n❌ Test {$testNum} FAILED\n";
        $failed++;
    }

    echo "\n" . str_repeat("=", 80) . "\n\n";
}

// Summary
echo "\n📊 TEST SUMMARY\n";
echo str_repeat("=", 80) . "\n";
echo "Passed: {$passed} / " . count($tests) . "\n";
echo "Failed: {$failed} / " . count($tests) . "\n";

if ($failed === 0) {
    echo "\n🎉 ALL TESTS PASSED! Enhanced guardrails working correctly!\n\n";
    exit(0);
} else {
    echo "\n⚠️  Some tests failed. Review guardrail configuration.\n\n";
    exit(1);
}
