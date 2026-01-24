<?php

namespace App\Console\Commands;

use App\Services\Routing\MarkdownAbbreviationParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ImportAbbreviationsCommand extends Command
{
    protected $signature = 'guidelines:import-abbr
                            {path : Path to abbreviation file}
                            {--guideline= : Guideline slug (e.g., vascular_graft_infections)}
                            {--format=md : File format (md, csv, json)}
                            {--dry-run : Preview without saving}';

    protected $description = 'Import abbreviation table from MD/CSV/JSON file';

    public function handle(): int
    {
        $path = $this->argument('path');
        $guideline = $this->option('guideline');
        $format = $this->option('format');
        $dryRun = $this->option('dry-run');

        // Validate file exists
        if (!File::exists($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        // Auto-detect guideline slug from filename if not provided
        if (!$guideline) {
            $guideline = $this->guessGuidelineFromFilename($path);
            $this->info("Auto-detected guideline: {$guideline}");
        }

        // Read file content
        $content = File::get($path);

        // Parse based on format
        $abbreviations = match ($format) {
            'md' => $this->parseMd($content),
            'csv' => $this->parseCsv($content),
            'json' => $this->parseJson($content),
            default => throw new \InvalidArgumentException("Unsupported format: {$format}"),
        };

        if (empty($abbreviations)) {
            $this->warn("No abbreviations found in file");
            return self::FAILURE;
        }

        // Display results
        $this->info("Found " . count($abbreviations) . " abbreviations");

        if ($this->output->isVerbose()) {
            $this->table(
                ['Abbreviation', 'Expansion'],
                array_map(fn($k, $v) => [$k, $v], array_keys($abbreviations), $abbreviations)
            );
        } else {
            // Show first 5 as sample
            $sample = array_slice($abbreviations, 0, 5, true);
            $this->table(
                ['Abbreviation', 'Expansion'],
                array_map(fn($k, $v) => [$k, $v], array_keys($sample), $sample)
            );
            $remaining = count($abbreviations) - 5;
            if ($remaining > 0) {
                $this->line("  ... and {$remaining} more (use -v to see all)");
            }
        }

        if ($dryRun) {
            $this->warn("Dry run - no files saved");
            return self::SUCCESS;
        }

        // Save to normalized JSON
        $this->saveNormalized($guideline, $abbreviations);

        // Copy raw file to raw directory
        $this->copyRawFile($path, $guideline);

        $this->info("✓ Successfully imported abbreviations for guideline: {$guideline}");

        return self::SUCCESS;
    }

    protected function parseMd(string $content): array
    {
        $parser = new MarkdownAbbreviationParser();
        return $parser->parse($content);
    }

    protected function parseCsv(string $content): array
    {
        $abbreviations = [];
        $lines = str_getcsv($content, "\n");

        foreach ($lines as $line) {
            $parts = str_getcsv($line);

            if (count($parts) >= 2) {
                $abbr = trim($parts[0]);
                $expansion = trim($parts[1]);

                if ($abbr && $expansion) {
                    $abbreviations[$abbr] = $expansion;
                }
            }
        }

        return $abbreviations;
    }

    protected function parseJson(string $content): array
    {
        $decoded = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid JSON: " . json_last_error_msg());
        }

        return $decoded ?? [];
    }

    protected function saveNormalized(string $guideline, array $abbreviations): void
    {
        $normalizedPath = config('router_abbreviations.paths.normalized');

        if (!File::exists($normalizedPath)) {
            File::makeDirectory($normalizedPath, 0755, true);
        }

        $filePath = "{$normalizedPath}/{$guideline}.json";
        $json = json_encode($abbreviations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        File::put($filePath, $json);
        $this->line("  Saved: {$filePath}");
    }

    protected function copyRawFile(string $sourcePath, string $guideline): void
    {
        $rawPath = config('router_abbreviations.paths.raw');

        if (!File::exists($rawPath)) {
            File::makeDirectory($rawPath, 0755, true);
        }

        $extension = pathinfo($sourcePath, PATHINFO_EXTENSION);
        $destPath = "{$rawPath}/{$guideline}.{$extension}";

        File::copy($sourcePath, $destPath);
        $this->line("  Backed up raw file: {$destPath}");
    }

    protected function guessGuidelineFromFilename(string $path): string
    {
        $filename = pathinfo($path, PATHINFO_FILENAME);

        // Map common patterns
        $mappings = [
            'PAD' => 'asymptomatic_pad',
            'infections' => 'vascular_graft_infections',
            'vascular_access' => 'vascular_access',
            'VGEI' => 'vascular_graft_infections',
            'AAA' => 'abdominal_aortic_aneurysm',
            'carotid' => 'carotid_vertebral',
            'trauma' => 'vascular_trauma',
        ];

        foreach ($mappings as $pattern => $slug) {
            if (stripos($filename, $pattern) !== false) {
                return $slug;
            }
        }

        // Default: use filename as slug (convert to snake_case)
        return str_replace([' ', '-'], '_', strtolower($filename));
    }
}
