<?php

namespace App\Console\Commands;

use App\Services\Routing\QueryExpander;
use Illuminate\Console\Command;

class TestExpansionCommand extends Command
{
    protected $signature = 'guidelines:test-expansion
                            {query : Query to test}
                            {--format=append : Expansion format (append|inline|dual)}';

    protected $description = 'Test query expansion with abbreviations';

    public function handle(QueryExpander $expander): int
    {
        $query = $this->argument('query');
        $format = $this->option('format');

        $this->info("🔍 Testing Query Expansion");
        $this->line("");

        $result = $expander->expand($query, $format);

        $this->line("<fg=cyan>Original Query:</>");
        $this->line("  {$result->originalQuery}");
        $this->line("");

        $this->line("<fg=green>Detected Acronyms:</>");
        if (empty($result->detectedAcronyms)) {
            $this->line("  (none)");
        } else {
            foreach ($result->detectedAcronyms as $abbr) {
                $this->line("  - {$abbr}");
            }
        }
        $this->line("");

        $this->line("<fg=green>Applied Expansions:</>");
        if (empty($result->appliedExpansions)) {
            $this->line("  (none)");
        } else {
            foreach ($result->appliedExpansions as $abbr => $expansion) {
                if (is_array($expansion)) {
                    $this->line("  - {$abbr} → [CONFLICT]");
                    foreach ($expansion as $e) {
                        $this->line("      * {$e}");
                    }
                } else {
                    $this->line("  - {$abbr} → {$expansion}");
                }
            }
        }
        $this->line("");

        if (!empty($result->conflicts)) {
            $this->warn("⚠️  Conflicts:");
            foreach ($result->conflicts as $abbr => $expansions) {
                $this->line("  {$abbr}: " . implode(' OR ', $expansions));
            }
            $this->line("");
        }

        $this->line("<fg=yellow>Expanded Query ({$format}):</>");
        $this->line("  {$result->expandedQuery}");
        $this->line("");

        $this->info("⏱️  Expansion Time: {$result->expansionTimeMs}ms");

        return self::SUCCESS;
    }
}
