<?php

namespace Tests\Feature;

use App\Services\ConsultSessionIdentityService;
use Illuminate\Support\Collection;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Vizra\VizraADK\Services\StateManager;
use Vizra\VizraADK\System\AgentContext;

/**
 * End-to-end binding for POST /api/v1/agent-consult.
 *
 * The clarification path is used throughout: a first-turn patient case with no
 * history returns a clarification prompt before the agent is invoked, so these
 * exercise validation, derivation and the response contract without an LLM call.
 * StateManager is mocked so the assertions can see the session id the controller
 * actually addresses state with.
 */
class AgentConsultSessionBindingTest extends TestCase
{
    private const KEY = 'test-api-key-for-binding';

    private const HANDLE = 'a1b2c3d4e5f60718293a4b5c';

    private const CASE_QUESTION = 'My patient is a 72 year old man with an AAA, what should I do?';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.api.key', self::KEY);
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_state_is_addressed_by_the_derived_id_not_the_supplied_handle(): void
    {
        $seen = $this->captureSessionIds();

        $this->consult(self::HANDLE)->assertOk();

        $expected = (new ConsultSessionIdentityService)->resolve(
            self::HANDLE,
            hash('sha256', self::KEY),
        );

        $this->assertNotEmpty($seen->getArrayCopy(), 'The controller should have loaded agent state.');
        foreach ($seen as $sessionId) {
            $this->assertSame($expected, $sessionId);
            $this->assertNotSame(self::HANDLE, $sessionId);
        }
    }

    public function test_the_same_handle_under_a_different_credential_reaches_different_state(): void
    {
        $seen = $this->captureSessionIds();

        $this->consult(self::HANDLE)->assertOk();
        config()->set('services.api.key', 'a-different-api-key');
        $this->consult(self::HANDLE, 'a-different-api-key')->assertOk();

        $this->assertCount(
            2,
            array_unique($seen->getArrayCopy()),
            'One handle must not span two credentials.',
        );
    }

    public function test_the_response_echoes_the_callers_handle_not_the_internal_id(): void
    {
        $this->captureSessionIds();

        $this->consult(self::HANDLE)
            ->assertOk()
            ->assertJsonPath('session_key', self::HANDLE);
    }

    #[DataProvider('malformedHandles')]
    public function test_a_handle_that_is_not_an_opaque_token_is_rejected(string $handle): void
    {
        // Rejected during validation, before any state lookup.
        $this->consult($handle)->assertStatus(422);
    }

    /** @return array<string, array<int, string>> */
    public static function malformedHandles(): array
    {
        return [
            'path traversal' => ['../../other-session'],
            'separator' => ['caller|victim'],
            'whitespace' => ['handle with spaces'],
            'too short' => ['abc'],
            'too long' => [str_repeat('a', 65)],
            'empty' => [''],
        ];
    }

    public function test_the_endpoint_still_requires_a_valid_credential(): void
    {
        $this->postJson('/api/v1/agent-consult', [
            'question' => self::CASE_QUESTION,
            'session_key' => self::HANDLE,
        ], ['X-API-Key' => 'wrong-key'])->assertStatus(401);
    }

    public function test_a_server_without_an_app_key_refuses_rather_than_sharing_one_scope(): void
    {
        config()->set('app.key', '');

        $this->consult(self::HANDLE)
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'session_scope_unavailable');
    }

    /**
     * Swap StateManager for a recorder, so no database or agent run is needed
     * and every session id the controller uses is observable.
     *
     * @return \ArrayObject<int, string> filled as the controller resolves state
     */
    private function captureSessionIds(): \ArrayObject
    {
        /** @var \ArrayObject<int, string> $seen */
        $seen = new \ArrayObject;

        $context = Mockery::mock(AgentContext::class);
        $context->shouldReceive('getConversationHistory')->andReturn(new Collection);
        $context->shouldReceive('getState')->andReturn([]);

        $stateManager = Mockery::mock(StateManager::class);
        $stateManager->shouldReceive('loadContext')
            ->andReturnUsing(function (string $agent, ?string $sessionId = null) use ($seen, $context) {
                $seen[] = (string) $sessionId;

                return $context;
            });

        $this->instance(StateManager::class, $stateManager);

        return $seen;
    }

    private function consult(string $handle, string $key = self::KEY): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/agent-consult', [
            'question' => self::CASE_QUESTION,
            'session_key' => $handle,
        ], ['X-API-Key' => $key]);
    }
}
