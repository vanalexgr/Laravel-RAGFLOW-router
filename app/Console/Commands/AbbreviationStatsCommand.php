<?php

namespace App\Console\Commands;

use App\Services\Routing\AbbreviationStore;
use Illuminate\Console\Command;

class AbbreviationStatsCommand extends Command
{
    protected $signature = 'guidelines:abbr-stats';
    protected $description = 'Display abbreviation statistics and conflicts';

    public function handle(AbbreviationStore $store): int
    {
        $stats = $store->getStats();

        $this->info("📊 Abbreviation Statistics");
        $this->line("");

        $this->table(['Metric', 'Value'], [
            ['Total Abbreviations', $stats['total_abbreviations']],
            ['Guidelines Loaded', $stats['guidelines_loaded']],
            ['Conflicts Detected', $stats['conflicts']],
        ]);

        if (!empty($stats['conflicts_list'])) {
            $this->line("");
            $this->warn("⚠️  Conflicts Detected:");
            foreach ($stats['conflicts_list'] as $abbr) {
                $resolutions = $store->getConflictResolution($abbr);
                $this->line("  {$abbr}:");
                foreach ($resolutions as $guideline => $expansion) {
                    $this->line("    - [{$guideline}] {$expansion}");
                }
            }
        }

        return self::SUCCESS;
    }
}
