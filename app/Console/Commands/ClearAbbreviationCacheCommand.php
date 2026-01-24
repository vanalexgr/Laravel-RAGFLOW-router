<?php

namespace App\Console\Commands;

use App\Services\Routing\AbbreviationStore;
use Illuminate\Console\Command;

class ClearAbbreviationCacheCommand extends Command
{
    protected $signature = 'guidelines:clear-abbr-cache';
    protected $description = 'Clear abbreviation cache';

    public function handle(AbbreviationStore $store): int
    {
        $store->clearCache();
        $this->info("✓ Abbreviation cache cleared");

        return self::SUCCESS;
    }
}
