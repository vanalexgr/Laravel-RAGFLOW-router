<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GateEvalCommandTest extends TestCase
{
    public function test_stub_subject_and_judge_run_end_to_end(): void
    {
        Storage::fake('local');

        $this->artisan('gate:eval', ['--sut' => 'stub', '--judge' => 'stub'])
            ->expectsOutputToContain('Artifact:')
            ->assertSuccessful();

        $this->assertCount(1, Storage::disk('local')->allFiles('gate-eval/runs'));
    }
}
