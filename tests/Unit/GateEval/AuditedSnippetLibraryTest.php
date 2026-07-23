<?php

namespace Tests\Unit\GateEval;

use App\Ai\Gate\AuditedSnippetLibrary;
use Tests\TestCase;

class AuditedSnippetLibraryTest extends TestCase
{
    public function test_candidates_are_disabled_by_default(): void
    {
        config(['gate-v2.audited_snippets.enabled' => false]);

        $this->assertSame([], app(AuditedSnippetLibrary::class)->all());
    }

    public function test_enabled_candidate_file_is_explicitly_unverified(): void
    {
        config(['gate-v2.audited_snippets.enabled' => true]);

        $snippets = app(AuditedSnippetLibrary::class)->all();

        $this->assertCount(4, $snippets);
        $this->assertSame(['UNVERIFIED'], array_values(array_unique(array_column($snippets, 'status'))));
    }
}
