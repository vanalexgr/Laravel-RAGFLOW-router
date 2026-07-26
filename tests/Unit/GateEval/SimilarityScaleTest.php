<?php

namespace Tests\Unit\GateEval;

use App\Ai\Gate\Grounding\GatePathwayWorker;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class SimilarityScaleTest extends TestCase
{
    public function test_formatted_similarity_is_converted_back_to_raw_ragflow_score_units(): void
    {
        $method = new ReflectionMethod(GatePathwayWorker::class, 'rawRagflowScore');

        $this->assertSame(0.478, $method->invoke(null, 47.8));
        $this->assertSame(5.349, $method->invoke(null, 534.9));
        $this->assertSame(0.8, $method->invoke(null, 0.8));
        $this->assertNull($method->invoke(null, null));
    }

    public function test_genuinely_weak_first_pass_is_insufficient(): void
    {
        config()->set('gate-v2.retrieval.sufficient_ragflow_score', 0.20);

        $this->assertFalse($this->firstPassMethod()->invoke(
            $this->workerWithoutConstructor(),
            [
                'snippets' => array_fill(0, 4, ['text' => 'weak evidence']),
                'diagnostics' => ['max_similarity' => 19.9],
            ],
        ));
    }

    public function test_strong_first_pass_is_sufficient(): void
    {
        config()->set('gate-v2.retrieval.sufficient_ragflow_score', 0.20);

        $this->assertTrue($this->firstPassMethod()->invoke(
            $this->workerWithoutConstructor(),
            [
                'snippets' => array_fill(0, 4, ['text' => 'strong evidence']),
                'diagnostics' => ['max_similarity' => 40.0],
            ],
        ));
    }

    private function firstPassMethod(): ReflectionMethod
    {
        return new ReflectionMethod(GatePathwayWorker::class, 'firstPassEvidenceIsSufficient');
    }

    private function workerWithoutConstructor(): GatePathwayWorker
    {
        return (new ReflectionClass(GatePathwayWorker::class))
            ->newInstanceWithoutConstructor();
    }
}
