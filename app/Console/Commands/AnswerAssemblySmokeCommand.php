<?php

namespace App\Console\Commands;

use App\Ai\AnswerAssembly\AnswerAssemblyService;
use Illuminate\Console\Command;
use Throwable;

final class AnswerAssemblySmokeCommand extends Command
{
    protected $signature = 'answer:assemble-smoke
        {--exercise-laravel : Temporarily exercise the non-default Laravel valve in this CLI process}';

    protected $description = 'Exercise the S0 one-call AnswerAssembly cloud path without changing defaults';

    public function handle(AnswerAssemblyService $assembly): int
    {
        if (! $this->option('exercise-laravel')) {
            $this->error('Pass --exercise-laravel; the default SYNTHESIS_OWNER=adapter is intentionally unchanged.');

            return self::FAILURE;
        }

        config()->set('gate-v2.synthesis_owner', 'laravel');
        config()->set('gate-v2.synthesis_model', 'cloud');

        try {
            $result = $assembly->assemble([
                'question' => 'Demonstrate the answer structure without making a clinical recommendation.',
                'response_mode' => 'knowledge',
                'planner' => ['query_type' => 'knowledge', 'intent' => 'template_smoke'],
                'patient_facts' => [],
                'retrieved_evidence' => [],
                'evidence_status' => [
                    'coverage' => 'not_covered',
                    'core_question' => 'this structure-only smoke test',
                    'covered_components' => [],
                    'gap_summary' => 'No clinical evidence was supplied by design.',
                ],
                'assets' => [],
            ]);
        } catch (Throwable $exception) {
            $this->error($exception::class.': '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode(
            $result,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return self::SUCCESS;
    }
}
