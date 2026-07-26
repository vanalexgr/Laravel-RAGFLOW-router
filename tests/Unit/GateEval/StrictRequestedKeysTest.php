<?php

namespace Tests\Unit\GateEval;

use App\Services\RetrievalService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The gate retrieves one branch per routed guideline, so a branch must keep the
 * scope its caller chose. Without this, `applyGuardrails()` expanded each explicit
 * branch back into the full routed union: on the S2 case the `clti` branch and the
 * `antithrombotic_therapy` branch both retrieved BOTH recommendations documents and
 * returned the same Recommendation 39 and 43 bodies, differing only in the guideline
 * label the gate stamped on them.
 *
 * `retrieve()` itself is not exercised here — it runs the LLM query-type classifier
 * — so the two halves are tested directly: the expansion, and the re-pin.
 */
class StrictRequestedKeysTest extends TestCase
{
    private const S2_QUESTION = 'What antithrombotic therapy is recommended after vein below-knee bypass for critical limb-threatening ischaemia with rest pain?';

    private function service(): RetrievalService
    {
        return new class extends RetrievalService {};
    }

    /** @return array<string, array<string, mixed>> */
    private function registryFor(string $key): array
    {
        $build = new ReflectionMethod(RetrievalService::class, 'buildGuidelineRegistry');
        $registry = $build->invoke($this->service());

        $this->assertArrayHasKey($key, $registry, "Guideline '{$key}' must exist in config/guidelines.php.");

        return [$key => $registry[$key]];
    }

    public function test_guardrails_expand_an_explicit_branch_into_the_routed_union(): void
    {
        $method = new ReflectionMethod(RetrievalService::class, 'applyGuardrails');

        $expanded = $method->invoke($this->service(), $this->registryFor('clti'), self::S2_QUESTION);

        // This is the root cause of the identical-recommendations bug, and it is the
        // behaviour the adapter path still wants.
        $this->assertArrayHasKey('clti', $expanded);
        $this->assertArrayHasKey(
            'antithrombotic_therapy',
            $expanded,
            'Antithrombotic terms pull in the companion guideline.',
        );
    }

    public function test_explicit_scope_is_restored_after_expansion(): void
    {
        $method = new ReflectionMethod(RetrievalService::class, 'enforceExplicitScope');
        $service = $this->service();

        $explicit = $this->registryFor('clti');
        $expanded = $explicit + $this->registryFor('antithrombotic_therapy');

        $this->assertSame(
            ['clti'],
            array_keys($method->invoke($service, $expanded, $explicit)),
        );
    }

    public function test_a_caller_that_did_not_opt_in_keeps_the_expansion(): void
    {
        $method = new ReflectionMethod(RetrievalService::class, 'enforceExplicitScope');
        $service = $this->service();

        $expanded = $this->registryFor('clti') + $this->registryFor('antithrombotic_therapy');

        // null explicit scope == the adapter path, which must be unaffected.
        $this->assertSame(
            ['clti', 'antithrombotic_therapy'],
            array_keys($method->invoke($service, $expanded, null)),
        );
    }

    public function test_the_gate_tool_enables_strict_scope_and_restores_it(): void
    {
        // The flag is off by default so the adapter path is unchanged; the gate
        // opts in per call and must put it back.
        $this->assertFalse((bool) config('ragflow.retrieval.strict_requested_keys'));
    }
}
