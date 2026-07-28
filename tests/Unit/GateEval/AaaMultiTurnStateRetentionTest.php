<?php

namespace Tests\Unit\GateEval;

use App\Ai\Gate\State\ShadowStateRecorder;
use App\Ai\Gate\State\StateEventStore;
use Tests\TestCase;

/**
 * Replays the three-turn AAA case that the live pipeline mishandles.
 *
 * MEASURED on the real system (docs/eval/run11_aaa_multiturn_state_loss.txt): the
 * Orient stage re-derives the patient model from the latest turn alone, so turn 3
 * returned ONLY `fitness` and every earlier fact — age, sex, 5.8 cm diameter,
 * juxtarenal anatomy, EVAR unsuitability, eGFR 28, asymptomatic status — vanished.
 * Evidence coverage collapsed to not_covered as a direct result.
 *
 * These are the exact patient models Orient emitted at each turn.
 *
 * Assertions deliberately check structural key PRESENCE rather than exact prose:
 * the extraction is an LLM and may legitimately reword "74-year-old male" as
 * "Male, 74". Losing the field is the defect; rewording it is not.
 */
class AaaMultiTurnStateRetentionTest extends TestCase
{
    private const TURN_1 = [
        'demographics' => '74-year-old male',
        'lesion' => 'asymptomatic abdominal aortic aneurysm, 5.8 cm diameter, discovered on ultrasound',
        'symptom_status' => 'asymptomatic',
        'imaging' => 'abdominal ultrasound',
        'comorbidities' => 'active smoker, hypertension, dyslipidaemia',
    ];

    private const TURN_2 = [
        'demographics' => '74-year-old male',
        'lesion' => 'juxtarenal abdominal aortic aneurysm with inadequate infrarenal neck for standard EVAR',
        'symptom_status' => 'asymptomatic',
        'imaging' => 'computed tomography angiography (CTA)',
        'comorbidities' => 'active smoker, hypertension, dyslipidaemia, chronic kidney disease (eGFR 28 mL/min/1.73 m2)',
    ];

    /** Turn 3 as actually observed: the accumulated model is simply absent. */
    private const TURN_3 = [
        'fitness' => 'severe COPD, reduced functional capacity, significant frailty, independent in basic daily activities',
    ];

    private function inMemoryStore(): StateEventStore
    {
        return new class implements StateEventStore
        {
            /** @var array<string, array<int, array<string, mixed>>> */
            public array $memory = [];

            public function load(string $conversationId): array
            {
                return $this->memory[$conversationId] ?? [];
            }

            public function append(string $conversationId, int $expectedCount, array $events): void
            {
                $this->memory[$conversationId] = array_merge(
                    $this->memory[$conversationId] ?? [],
                    $events,
                );
            }
        };
    }

    /** @return array<string, mixed> the projection after replaying all three turns */
    private function replay(): array
    {
        $recorder = new ShadowStateRecorder($this->inMemoryStore());
        $result = [];
        foreach ([self::TURN_1, self::TURN_2, self::TURN_3] as $i => $model) {
            $result = $recorder->record(
                "turn {$i}",
                ['conversation_id' => 'aaa-retention', 'turn_index' => $i],
                ['patient_model' => $model],
            );
        }

        $projection = $result['ledger_projection'] ?? $result['projection'] ?? [];

        return (array) ($projection['patient_model'] ?? $projection['patientModel'] ?? $projection);
    }

    public function test_turn_three_does_not_erase_the_accumulated_patient_model(): void
    {
        $model = $this->replay();

        // The live pipeline loses ALL of these at turn 3. Absence of a field in a
        // later extraction must mean "no new information", never "delete".
        foreach (['demographics', 'lesion', 'symptom_status', 'imaging', 'comorbidities'] as $field) {
            $this->assertArrayHasKey(
                $field,
                $model,
                "'{$field}' was established in an earlier turn and must survive a turn that does not mention it.",
            );
        }

        // And the newly-introduced fact is present.
        $this->assertArrayHasKey('fitness', $model);
    }

    public function test_the_later_imaging_and_anatomy_supersede_the_earlier_ones(): void
    {
        $model = $this->replay();

        // Turn 2 genuinely updates these; retention must not mean staleness.
        $this->assertStringContainsStringIgnoringCase('juxtarenal', (string) ($model['lesion'] ?? ''));
        $this->assertStringContainsStringIgnoringCase('tomography', (string) ($model['imaging'] ?? ''));
        $this->assertStringContainsStringIgnoringCase('egfr', (string) ($model['comorbidities'] ?? ''));
    }

    /**
     * KNOWN GAP, asserted so it is visible rather than forgotten.
     *
     * Turn 2's lesion string drops "5.8 cm" while adding the juxtarenal anatomy, and
     * the reducer overwrites the whole string. Clinical facts are packed into free
     * prose, so an update to one clause silently discards another.
     *
     * The fix is to decompose lesion into atomic fields (diameter / anatomy /
     * suitability) so an unmentioned diameter is simply retained. When that lands,
     * this test should be inverted to assert the diameter SURVIVES.
     */
    public function test_documents_the_lossy_free_text_field_overwrite(): void
    {
        $lesion = (string) ($this->replay()['lesion'] ?? '');

        $this->assertStringNotContainsString(
            '5.8',
            $lesion,
            'If the diameter now survives, the atomic-field decomposition has landed — invert this test.',
        );
    }
}
