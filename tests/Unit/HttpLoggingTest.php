<?php

namespace Tests\Unit;

use App\Http\Middleware\HttpLogging;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\TestCase;

class HttpLoggingTest extends TestCase
{
    public function test_state_route_identifier_is_hashed_before_logging(): void
    {
        $middleware = new HttpLogging;
        $method = new ReflectionMethod($middleware, 'sanitizeStateIdentifier');

        $sanitized = $method->invoke(
            $middleware,
            'https://example.test/api/v1/pending-case-state/private-chat-id',
        );

        $this->assertStringNotContainsString('private-chat-id', $sanitized);
        $this->assertStringContainsString('pending-case-state/sha1:', $sanitized);
    }

    public function test_state_request_body_is_always_reduced_to_lengths_and_counts(): void
    {
        config()->set('logging.http_log_bodies', true);
        $request = Request::create(
            '/api/v1/pending-case-state/private-chat-id',
            'PUT',
            [
                'provisional_diagnosis' => 'CLTI, MRN 123456',
                'guidelines' => ['clti'],
                'retrieval_query' => 'limb salvage patient@example.com',
                'clarification_questions' => ['Does the patient have tissue loss?'],
                'confirmation_message' => 'Reply to confirm.',
            ],
        );
        $middleware = new HttpLogging;
        $method = new ReflectionMethod($middleware, 'getRequestBody');

        $metadata = $method->invoke($middleware, $request);
        $serialized = json_encode($metadata, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('123456', $serialized);
        $this->assertStringNotContainsString('patient@example.com', $serialized);
        $this->assertSame(1, $metadata['guidelines_count']);
        $this->assertSame(1, $metadata['clarification_questions_count']);
    }
}
