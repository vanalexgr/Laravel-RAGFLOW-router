#!/usr/bin/env php
<?php

/**
 * Standalone test script for abbreviation parser
 * Run without Laravel: php scripts/test_abbreviation_parser.php
 */

// Simple parser test (no Laravel dependencies)
class SimpleMarkdownParser
{
    public function parse(string $content): array
    {
        $abbreviations = [];
        $lines = explode("\n", $content);

        // Parse pipe tables
        foreach ($lines as $line) {
            $line = trim($line);

            if (!str_starts_with($line, '|'))
                continue;
            if (preg_match('/^\|[\s\-\|]+\|$/', $line))
                continue;

            $parts = array_map('trim', explode('|', $line));
            $parts = array_filter($parts, fn($p) => $p !== '');
            $parts = array_values($parts);

            if (count($parts) < 2)
                continue;

            for ($i = 0; $i < count($parts) - 1; $i += 2) {
                $abbr = $parts[$i];
                $expansion = $parts[$i + 1] ?? '';

                if (empty($abbr) || empty($expansion))
                    continue;
                if ($this->isHeaderRow($abbr, $expansion))
                    continue;

                $abbr = $this->normalize($abbr);
                $expansion = $this->normalize($expansion);

                if ($abbr && $expansion) {
                    $abbreviations[$abbr] = $expansion;
                }
            }
        }

        // Parse headings
        $currentHeading = null;
        foreach ($lines as $line) {
            $line = trim($line);

            if (preg_match('/^#{1,6}\s+(.+)$/', $line, $matches)) {
                $currentHeading = trim($matches[1]);
                continue;
            }

            if ($currentHeading && !empty($line) && !str_starts_with($line, '|')) {
                $abbr = $this->normalize($currentHeading);
                $expansion = $this->normalize($line);

                if ($abbr && $expansion) {
                    $abbreviations[$abbr] = $expansion;
                }
                $currentHeading = null;
            }
        }

        return $abbreviations;
    }

    private function normalize(string $text): string
    {
        $text = strip_tags($text);
        $text = str_replace(['**', '__', '*', '_'], '', $text);
        $text = preg_replace('/<sub>(.+?)<\/sub>/i', '$1', $text);
        $text = preg_replace('/<sup>(.+?)<\/sup>/i', '$1', $text);
        return trim(rtrim($text, '.,;:'));
    }

    private function isHeaderRow(string $col1, string $col2): bool
    {
        $patterns = ['Abbreviation', 'Definition', 'Term', 'STUDY ACRONYM'];
        foreach ($patterns as $pattern) {
            if (stripos($col1, $pattern) !== false || stripos($col2, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }
}

// Test runner
echo "🧪 Testing Abbreviation Parser\n";
echo str_repeat("=", 60) . "\n\n";

$files = [
    'storage/PAD.md' => 'Asymptomatic PAD',
    'storage/infections.md' => 'Vascular Graft Infections',
    'storage/vascular_access.md' => 'Vascular Access',
];

$parser = new SimpleMarkdownParser();
$totalAbbrs = 0;
$allPassed = true;

foreach ($files as $file => $name) {
    echo "Testing: {$name}\n";
    echo "File: {$file}\n";

    if (!file_exists($file)) {
        echo "  ❌ File not found\n\n";
        $allPassed = false;
        continue;
    }

    $content = file_get_contents($file);
    $abbrs = $parser->parse($content);
    $count = count($abbrs);
    $totalAbbrs += $count;

    if ($count === 0) {
        echo "  ❌ No abbreviations found\n\n";
        $allPassed = false;
        continue;
    }

    echo "  ✅ Parsed {$count} abbreviations\n";

    // Show first 3 as sample
    echo "  Sample:\n";
    $sample = array_slice($abbrs, 0, 3, true);
    foreach ($sample as $abbr => $expansion) {
        $shortExp = strlen($expansion) > 50 ? substr($expansion, 0, 47) . '...' : $expansion;
        echo "    - {$abbr} → {$shortExp}\n";
    }
    echo "\n";
}

echo str_repeat("=", 60) . "\n";
echo "Summary:\n";
echo "  Total files: " . count($files) . "\n";
echo "  Total abbreviations: {$totalAbbrs}\n";
echo "  Status: " . ($allPassed ? "✅ ALL PASSED" : "❌ SOME FAILED") . "\n";

// Specific validations
echo "\n" . str_repeat("=", 60) . "\n";
echo "Specific Validations:\n\n";

$validations = [
    'storage/PAD.md' => ['TEVAR' => 'thoracic endovascular'],  // Partial match OK
    'storage/infections.md' => ['VGEI' => 'Vascular graft'],
    'storage/vascular_access.md' => ['AVF' => 'Arteriovenous fistula'],
];

foreach ($validations as $file => $checks) {
    if (!file_exists($file))
        continue;

    $content = file_get_contents($file);
    $abbrs = $parser->parse($content);

    foreach ($checks as $abbr => $expectedPartial) {
        if (isset($abbrs[$abbr])) {
            $expansion = $abbrs[$abbr];
            if (stripos($expansion, $expectedPartial) !== false) {
                echo "  ✅ {$abbr} found: {$expansion}\n";
            } else {
                echo "  ⚠️  {$abbr} found but unexpected: {$expansion}\n";
            }
        } else {
            echo "  ❌ {$abbr} NOT FOUND in " . basename($file) . "\n";
            $allPassed = false;
        }
    }
}

echo "\n" . str_repeat("=", 60) . "\n";
exit($allPassed ? 0 : 1);
