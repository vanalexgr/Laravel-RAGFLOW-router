<?php

namespace Tests\Unit;

use App\Services\ConsultSessionIdentityService;
use RuntimeException;
use Tests\TestCase;

/**
 * The consult endpoint used the request body's session_key directly as the
 * agent session id, so a caller chose which stored conversation to load. These
 * tests pin the properties that stop a handle from being an address.
 */
class ConsultSessionIdentityServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    public function test_the_same_handle_and_caller_resolve_to_a_stable_id(): void
    {
        $service = new ConsultSessionIdentityService;

        // A conversation must survive across turns, or state is lost every reply.
        $this->assertSame(
            $service->resolve('chat-abc12345', 'caller-fingerprint'),
            $service->resolve('chat-abc12345', 'caller-fingerprint'),
        );
    }

    public function test_the_same_handle_under_different_callers_cannot_collide(): void
    {
        $service = new ConsultSessionIdentityService;

        $this->assertNotSame(
            $service->resolve('chat-abc12345', 'caller-one'),
            $service->resolve('chat-abc12345', 'caller-two'),
        );
    }

    public function test_different_handles_under_one_caller_stay_separate(): void
    {
        $service = new ConsultSessionIdentityService;

        $this->assertNotSame(
            $service->resolve('chat-abc12345', 'caller-one'),
            $service->resolve('chat-def67890', 'caller-one'),
        );
    }

    public function test_the_resolved_id_is_never_the_supplied_handle(): void
    {
        $handle = 'chat-abc12345';

        $resolved = (new ConsultSessionIdentityService)->resolve($handle, 'caller-one');

        $this->assertNotSame($handle, $resolved);
        $this->assertStringNotContainsString($handle, $resolved);
    }

    public function test_the_id_depends_on_the_server_secret(): void
    {
        // Without this, a caller who knows the derivation could compute another
        // conversation's id and the binding would be decorative.
        $service = new ConsultSessionIdentityService;
        $first = $service->resolve('chat-abc12345', 'caller-one');

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('z', 32)));

        $this->assertNotSame($first, $service->resolve('chat-abc12345', 'caller-one'));
    }

    public function test_an_unauthenticated_caller_is_refused_rather_than_defaulted(): void
    {
        // Reachable only if the endpoint loses its auth middleware. Falling back
        // to one shared scope would silently merge every caller's sessions.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('authenticated caller');

        (new ConsultSessionIdentityService)->resolve('chat-abc12345', '');
    }

    public function test_an_empty_handle_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        (new ConsultSessionIdentityService)->resolve('   ', 'caller-one');
    }

    public function test_a_missing_app_key_fails_closed(): void
    {
        config()->set('app.key', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_KEY');

        (new ConsultSessionIdentityService)->resolve('chat-abc12345', 'caller-one');
    }
}
