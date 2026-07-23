<?php

namespace App\Ai\Gate;

use Illuminate\Support\Facades\File;
use RuntimeException;

class AuditedSnippetLibrary
{
    /**
     * Returns no assertions unless the clinician-sign-off flag is explicitly enabled.
     *
     * @return array<int, array{id:string, status:string, text:string}>
     */
    public function all(): array
    {
        if (! config('gate-v2.audited_snippets.enabled', false)) {
            return [];
        }

        // TODO(human): Replace candidate parsing with signed records after clinician approval.
        $path = (string) config('gate-v2.audited_snippets.path');
        if (! File::exists($path)) {
            throw new RuntimeException("Audited snippet candidate file not found: {$path}");
        }

        $snippets = [];
        $currentId = null;
        foreach (File::lines($path) as $line) {
            $line = trim($line);
            if (preg_match('/^## Candidate ([A-Z0-9-]+)$/', $line, $match) === 1) {
                $currentId = $match[1];
                continue;
            }
            if ($currentId !== null && str_starts_with($line, '> ')) {
                $snippets[] = [
                    'id' => $currentId,
                    'status' => 'UNVERIFIED',
                    'text' => substr($line, 2),
                ];
                $currentId = null;
            }
        }

        return $snippets;
    }
}
