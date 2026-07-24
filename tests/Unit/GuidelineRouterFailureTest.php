<?php

namespace Tests\Unit;

use App\Services\GuidelineRouterService;
use Tests\TestCase;

class GuidelineRouterFailureTest extends TestCase
{
    public function test_pooled_connection_failure_is_not_treated_as_a_response(): void
    {
        $router = new class extends GuidelineRouterService
        {
            public function successful(mixed $response): bool
            {
                return $this->isSuccessfulHttpResponse($response);
            }
        };

        $this->assertFalse($router->successful(new \RuntimeException('DNS failure')));
        $this->assertFalse($router->successful(new \stdClass));
    }
}
