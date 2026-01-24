<?php

namespace App\Services\Routing;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class AbbreviationStore
{
    protected array $guidelineMap = [];
    protected ?array $globalMap = null;
    protected array $conflicts = [];

    public function __construct()
    {
        if (config('router_abbreviations.preload_on_boot')) {
            $this->preloadCache();
        }
    }

    /**
     * Get global abbreviation map (union of all guidelines).
     */
    public function getGlobalMap(): array
    {
        $cacheKey = 'abbreviations:global';
        $ttl = config('router_abbreviations.cache_ttl', 3600);

        return Cache::remember($cacheKey, $ttl, function () {
            return $this->buildGlobalMap();
        });
    }

    /**
     * Get abbreviations for specific guideline.
     */
    public function getGuidelineMap(string $slug): array
    {
        $cacheKey = "abbreviations:guideline:{$slug}";
        $ttl = config('router_abbreviations.cache_ttl', 3600);

        return Cache::remember($cacheKey, $ttl, function () use ($slug) {
            return $this->loadGuideline($slug);
        });
    }

    /**
     * Load abbreviations from normalized JSON file.
     */
    public function loadGuideline(string $slug): array
    {
        $normalizedPath = config('router_abbreviations.paths.normalized');
        $filePath = "{$normalizedPath}/{$slug}.json";

        if (!File::exists($filePath)) {
            Log::channel('retrieval')->warning("[ABBREVIATIONS] Guideline file not found", [
                'slug' => $slug,
                'path' => $filePath,
            ]);
            return [];
        }

        $json = File::get($filePath);
        return json_decode($json, true) ?? [];
    }

    /**
     * Build global map from all guideline JSON files.
     */
    protected function buildGlobalMap(): array
    {
        $normalizedPath = config('router_abbreviations.paths.normalized');

        if (!File::exists($normalizedPath)) {
            return [];
        }

        $files = File::glob("{$normalizedPath}/*.json");
        $globalMap = [];
        $sources = []; // Track which guidelines define each abbreviation

        foreach ($files as $file) {
            $slug = basename($file, '.json');

            // Skip the global map file itself
            if ($slug === '_global_map') {
                continue;
            }

            $abbrs = $this->loadGuideline($slug);

            foreach ($abbrs as $abbr => $expansion) {
                // Track source
                if (!isset($sources[$abbr])) {
                    $sources[$abbr] = [];
                }
                $sources[$abbr][$slug] = $expansion;

                // Add to global map
                if (!isset($globalMap[$abbr])) {
                    // First occurrence
                    $globalMap[$abbr] = $expansion;
                } elseif ($globalMap[$abbr] !== $expansion) {
                    // Conflict detected - store all variants
                    if (!is_array($globalMap[$abbr])) {
                        $globalMap[$abbr] = [$globalMap[$abbr]];
                    }
                    if (!in_array($expansion, $globalMap[$abbr])) {
                        $globalMap[$abbr][] = $expansion;
                    }
                }
            }
        }

        // Detect and log conflicts
        $this->detectConflicts($sources);

        return $globalMap;
    }

    /**
     * Detect abbreviations that appear in multiple guidelines with different meanings.
     */
    protected function detectConflicts(array $sources): void
    {
        $this->conflicts = [];

        foreach ($sources as $abbr => $guidelineExpansions) {
            if (count($guidelineExpansions) > 1) {
                $uniqueExpansions = array_unique(array_values($guidelineExpansions));
                if (count($uniqueExpansions) > 1) {
                    $this->conflicts[$abbr] = [
                        'expansions' => $guidelineExpansions,
                        'count' => count($guidelineExpansions),
                    ];
                }
            }
        }

        if (!empty($this->conflicts)) {
            Log::channel('retrieval')->info("[ABBREVIATIONS] Conflicts detected", [
                'count' => count($this->conflicts),
                'conflicts' => array_keys($this->conflicts),
            ]);
        }
    }

    /**
     * Check if abbreviation has conflicts.
     */
    public function hasConflict(string $abbr): bool
    {
        if (empty($this->conflicts)) {
            $this->getGlobalMap(); // This will populate conflicts
        }
        return isset($this->conflicts[$abbr]);
    }

    /**
     * Get all expansions for a conflicting abbreviation.
     */
    public function getConflictResolution(string $abbr): array
    {
        if (!$this->hasConflict($abbr)) {
            return [];
        }
        return $this->conflicts[$abbr]['expansions'];
    }

    /**
     * Get statistics about abbreviations.
     */
    public function getStats(): array
    {
        $globalMap = $this->getGlobalMap();
        $normalizedPath = config('router_abbreviations.paths.normalized');
        $guidelineCount = count(File::glob("{$normalizedPath}/*.json")) - 1; // Exclude _global_map

        return [
            'total_abbreviations' => count($globalMap),
            'conflicts' => count($this->conflicts),
            'conflicts_list' => array_keys($this->conflicts),
            'guidelines_loaded' => $guidelineCount,
        ];
    }

    /**
     * Clear all abbreviation caches.
     */
    public function clearCache(): void
    {
        Cache::forget('abbreviations:global');

        $normalizedPath = config('router_abbreviations.paths.normalized');
        $files = File::glob("{$normalizedPath}/*.json");

        foreach ($files as $file) {
            $slug = basename($file, '.json');
            Cache::forget("abbreviations:guideline:{$slug}");
        }

        Log::channel('retrieval')->info("[ABBREVIATIONS] Cache cleared");
    }

    /**
     * Preload all abbreviation maps into cache (for production).
     */
    public function preloadCache(): void
    {
        $startTime = microtime(true);

        // Preload global map
        $this->getGlobalMap();

        // Preload individual guideline maps
        $normalizedPath = config('router_abbreviations.paths.normalized');
        if (File::exists($normalizedPath)) {
            $files = File::glob("{$normalizedPath}/*.json");
            foreach ($files as $file) {
                $slug = basename($file, '.json');
                if ($slug !== '_global_map') {
                    $this->getGuidelineMap($slug);
                }
            }
        }

        $duration = round((microtime(true) - $startTime) * 1000);

        Log::channel('retrieval')->info("[ABBREVIATIONS] Cache preloaded", [
            'duration_ms' => $duration,
            'stats' => $this->getStats(),
        ]);
    }
}
