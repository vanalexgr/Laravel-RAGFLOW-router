<?php

namespace App\Services\Routing;

use Illuminate\Support\Str;

class MarkdownAbbreviationParser
{
    /**
     * Parse MD abbreviation file and return normalized array.
     *
     * Handles three formats:
     * 1. Pipe-delimited 2-column: | ABBR | Expansion |
     * 2. Heading + description: ### ABBR\nExpansion
     * 3. Pipe-delimited 4-column: | ABBR1 | Exp1 | ABBR2 | Exp2 |
     */
    public function parse(string $content): array
    {
        $abbreviations = [];
        $lines = explode("\n", $content);

        // Try format 1 & 3: Pipe-delimited tables
        $tableAbbrs = $this->parsePipeTables($lines);
        $abbreviations = array_merge($abbreviations, $tableAbbrs);

        // Try format 2: Heading + description
        $headingAbbrs = $this->parseHeadings($lines);
        $abbreviations = array_merge($abbreviations, $headingAbbrs);

        return $abbreviations;
    }

    /**
     * Parse pipe-delimited table format (2-column or 4-column).
     */
    protected function parsePipeTables(array $lines): array
    {
        $abbreviations = [];

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip if not a pipe-delimited line
            if (!str_starts_with($line, '|')) {
                continue;
            }

            // Skip header separators (|---|---|)
            if (preg_match('/^\|[\s\-\|]+\|$/', $line)) {
                continue;
            }

            // Split by pipes and clean up
            $parts = array_map('trim', explode('|', $line));
            // Remove first and last empty elements (usually result of | start and end |)
            $parts = array_values(array_filter($parts, fn($p) => $p !== ''));

            $count = count($parts);

            // Detection logic:
            // 2 Columns: | Abbr | Expansion |
            // 3 Columns: | Orphan | Abbr | Expansion | (Carotid.md special case)
            // 4 Columns: | Abbr | Expansion | Abbr | Expansion |

            if ($count === 2) {
                $this->addPair($abbreviations, $parts[0], $parts[1]);
            } elseif ($count === 3) {
                // Try pairing Col 2 + Col 3 (ignore Col 1 which appears to be orphaned index)
                $this->addPair($abbreviations, $parts[1], $parts[2]);
            } elseif ($count === 4) {
                $this->addPair($abbreviations, $parts[0], $parts[1]);
                $this->addPair($abbreviations, $parts[2], $parts[3]);
            }
        }

        return $abbreviations;
    }

    protected function addPair(array &$abbreviations, string $abbr, string $expansion): void
    {
        if (empty($abbr) || empty($expansion))
            return;
        if ($this->isHeaderRow($abbr, $expansion))
            return;

        $normAbbr = $this->normalizeAbbreviation($abbr);
        $normExp = $this->normalizeExpansion($expansion);

        if ($normAbbr && $normExp) {
            $abbreviations[$normAbbr] = $normExp;
        }
    }

    /**
     * Parse heading + description format (### ABBR\nExpansion).
     */
    protected function parseHeadings(array $lines): array
    {
        $abbreviations = [];
        $currentHeading = null;

        foreach ($lines as $line) {
            $line = trim($line);

            // Check if it's a heading (###)
            if (preg_match('/^#{1,6}\s+(.+)$/', $line, $matches)) {
                $currentHeading = trim($matches[1]);
                continue;
            }

            // If we have a heading and current line is not empty, it's the expansion
            if ($currentHeading && !empty($line) && !str_starts_with($line, '|')) {
                $abbr = $this->normalizeAbbreviation($currentHeading);
                $expansion = $this->normalizeExpansion($line);

                if ($abbr && $expansion) {
                    $abbreviations[$abbr] = $expansion;
                }

                $currentHeading = null; // Reset after processing
            }
        }

        return $abbreviations;
    }

    /**
     * Normalize abbreviation (keep original case, trim).
     */
    protected function normalizeAbbreviation(string $abbr): string
    {
        $abbr = trim($abbr);

        // Remove markdown formatting
        $abbr = strip_tags($abbr);
        $abbr = str_replace(['**', '__', '*', '_'], '', $abbr);

        // Handle subscript/superscript in text (e.g., CO2, SUVmax)
        $abbr = preg_replace('/<sub>(.+?)<\/sub>/i', '$1', $abbr);
        $abbr = preg_replace('/<sup>(.+?)<\/sup>/i', '$1', $abbr);

        return trim($abbr);
    }

    /**
     * Normalize expansion (clean HTML, trim punctuation).
     */
    protected function normalizeExpansion(string $expansion): string
    {
        $expansion = trim($expansion);

        // Remove HTML tags but keep content
        $expansion = strip_tags($expansion);

        // Handle synonyms in parentheses - keep them
        // e.g., "Arteriovenous fistula (Synonym: Autogenous or native fistula)"

        // Remove trailing punctuation (but not internal)
        $expansion = rtrim($expansion, '.,;:');

        return trim($expansion);
    }

    /**
     * Check if row looks like a header row.
     */
    protected function isHeaderRow(string $col1, string $col2): bool
    {
        $headerPatterns = [
            'Abbreviation',
            'Definition',
            'Term',
            'STUDY ACRONYM',
            'ABBREVIATION',
            'Synonym',
        ];

        foreach ($headerPatterns as $pattern) {
            if (stripos($col1, $pattern) !== false || stripos($col2, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}
