<?php

namespace App\Services\Routing;

use Illuminate\Support\Facades\Log;

class ExpandResult
{
    public function __construct(
        public string $originalQuery,
        public string $expandedQuery,
        public array $detectedAcronyms,
        public array $appliedExpansions,
        public array $conflicts,
        public int $expansionTimeMs
    ) {
    }
}

class QueryExpander
{
    protected AbbreviationStore $store;

    public function __construct(AbbreviationStore $store)
    {
        $this->store = $store;
    }

    /**
     * Expand acronyms in query.
     *
     * @param string $query Original user query
     * @param string $format Format for expansion: 'append', 'inline', or 'dual'
     * @return ExpandResult
     */
    public function expand(string $query, string $format = null): ExpandResult
    {
        $startTime = microtime(true);
        $format = $format ?? config('router_abbreviations.expansion_format', 'append');

        // Detect acronyms
        $acronyms = $this->detectAcronyms($query);

        // Early return if no acronyms found
        if (empty($acronyms)) {
            return new ExpandResult(
                originalQuery: $query,
                expandedQuery: $query,
                detectedAcronyms: [],
                appliedExpansions: [],
                conflicts: [],
                expansionTimeMs: 0
            );
        }

        // Expand acronyms
        $expansions = $this->expandAcronyms($acronyms);
        $conflicts = [];

        // Build expanded query
        $expandedQuery = $this->buildExpandedQuery($query, $expansions, $format, $conflicts);

        $duration = round((microtime(true) - $startTime) * 1000);

        Log::channel('retrieval')->debug("[QUERY EXPANSION] Completed", [
            'detected' => $acronyms,
            'expansions_count' => count($expansions),
            'format' => $format,
            'duration_ms' => $duration,
        ]);

        return new ExpandResult(
            originalQuery: $query,
            expandedQuery: $expandedQuery,
            detectedAcronyms: $acronyms,
            appliedExpansions: $expansions,
            conflicts: $conflicts,
            expansionTimeMs: $duration
        );
    }

    /**
     * Detect acronyms in query using configured regex patterns.
     */
    public function detectAcronyms(string $query, ?int $maxAcronyms = null): array
    {
        $maxAcronyms = $maxAcronyms ?? config('router_abbreviations.max_acronyms', 8);
        $patterns = config('router_abbreviations.detection_patterns', []);

        $detected = [];

        foreach ($patterns as $pattern) {
            preg_match_all("/{$pattern}/u", $query, $matches);
            if (!empty($matches[0])) {
                $detected = array_merge($detected, $matches[0]);
            }
        }

        // Remove duplicates and limit
        $detected = array_unique($detected);
        $detected = array_slice($detected, 0, $maxAcronyms);

        return array_values($detected);
    }

    /**
     * Expand detected acronyms using abbreviation store.
     */
    public function expandAcronyms(array $acronyms): array
    {
        $globalMap = $this->store->getGlobalMap();
        $expansions = [];

        foreach ($acronyms as $abbr) {
            // Try exact match first
            if (isset($globalMap[$abbr])) {
                $expansions[$abbr] = $globalMap[$abbr];
                continue;
            }

            // Try uppercase match
            $upperAbbr = strtoupper($abbr);
            if (isset($globalMap[$upperAbbr])) {
                $expansions[$abbr] = $globalMap[$upperAbbr];
                continue;
            }

            // Try case-insensitive search
            foreach ($globalMap as $key => $value) {
                if (strcasecmp($key, $abbr) === 0) {
                    $expansions[$abbr] = $value;
                    break;
                }
            }
        }

        return $expansions;
    }

    /**
     * Build expanded query based on format.
     */
    protected function buildExpandedQuery(string $query, array $expansions, string $format, array &$conflicts): string
    {
        if (empty($expansions)) {
            return $query;
        }

        switch ($format) {
            case 'inline':
                return $this->buildInlineExpansion($query, $expansions);

            case 'dual':
                // Returns array of [original, expanded]
                return json_encode([
                    $query,
                    $this->buildFullyExpandedQuery($query, $expansions)
                ]);

            case 'append':
            default:
                return $this->buildAppendExpansion($query, $expansions, $conflicts);
        }
    }

    /**
     * Append format: "query. Abbreviations: TEVAR=...; CRP=..."
     */
    protected function buildAppendExpansion(string $query, array $expansions, array &$conflicts): string
    {
        $abbrParts = [];

        foreach ($expansions as $abbr => $expansion) {
            if (is_array($expansion)) {
                // Conflict: multiple expansions
                $conflicts[$abbr] = $expansion;
                $expansionStr = implode(' OR ', $expansion);
                $abbrParts[] = "{$abbr}={$expansionStr}";
            } else {
                $abbrParts[] = "{$abbr}={$expansion}";
            }
        }

        if (empty($abbrParts)) {
            return $query;
        }

        $abbrString = implode('; ', $abbrParts);
        return trim($query) . ". Abbreviations: {$abbrString}";
    }

    /**
     * Inline format: Replace acronyms inline with "expansion (ABBR)"
     */
    protected function buildInlineExpansion(string $query, array $expansions): string
    {
        $expandedQuery = $query;

        foreach ($expansions as $abbr => $expansion) {
            if (is_array($expansion)) {
                $expansion = $expansion[0]; // Use first expansion for inline
            }

            // Replace whole word only
            $pattern = '/\b' . preg_quote($abbr, '/') . '\b/u';
            $replacement = "{$expansion} ({$abbr})";
            $expandedQuery = preg_replace($pattern, $replacement, $expandedQuery, 1);
        }

        return $expandedQuery;
    }

    /**
     * Fully expanded: Replace all acronyms with their expansions.
     */
    protected function buildFullyExpandedQuery(string $query, array $expansions): string
    {
        $expandedQuery = $query;

        foreach ($expansions as $abbr => $expansion) {
            if (is_array($expansion)) {
                $expansion = implode(' ', $expansion);
            }

            $pattern = '/\b' . preg_quote($abbr, '/') . '\b/u';
            $expandedQuery = preg_replace($pattern, $expansion, $expandedQuery);
        }

        return $expandedQuery;
    }
}
