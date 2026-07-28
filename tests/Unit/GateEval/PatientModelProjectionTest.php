<?php

namespace Tests\Unit\GateEval;

use App\Ai\Gate\State\PatientModelProjection;
use Tests\TestCase;

/**
 * Pins the fix for the diameter loss measured on the live 3-turn AAA case, where
 * turn 2 rewrote the whole lesion string and "5.8 cm" disappeared for good.
 */
class PatientModelProjectionTest extends TestCase
{
    private const TURN_1 = [
        'lesion' => 'asymptomatic abdominal aortic aneurysm, 5.8 cm diameter, discovered on ultrasound',
        'anatomy' => 'abdominal aortic aneurysm',
        'laterality' => 'n/a',
        'diameter_value' => '5.8',
        'diameter_unit' => 'cm',
    ];

    /** Turn 2 as Orient behaves: it restates the anatomy and says nothing about size. */
    private const TURN_2 = [
        'lesion' => 'juxtarenal abdominal aortic aneurysm with inadequate infrarenal neck for standard EVAR',
        'anatomy' => 'juxtarenal abdominal aortic aneurysm',
        'laterality' => '',
        'diameter_value' => '',
        'diameter_unit' => '',
    ];

    public function test_an_unmentioned_diameter_survives_the_next_turn(): void
    {
        $merged = PatientModelProjection::merge(self::TURN_1, self::TURN_2);

        $this->assertSame('5.8', $merged['diameter_value']);
        $this->assertSame('cm', $merged['diameter_unit']);
        $this->assertStringContainsString('5.8 cm', $merged['lesion']);
    }

    public function test_the_updated_anatomy_still_supersedes_the_old_one(): void
    {
        $merged = PatientModelProjection::merge(self::TURN_1, self::TURN_2);

        // Retention must not mean staleness.
        $this->assertSame('juxtarenal abdominal aortic aneurysm', $merged['anatomy']);
        $this->assertStringContainsString('juxtarenal', $merged['lesion']);
        // Detail the structured fields cannot hold is preserved rather than discarded.
        $this->assertStringContainsString('inadequate infrarenal neck', $merged['lesion']);
    }

    public function test_laterality_not_applicable_is_carried_as_a_fact(): void
    {
        $merged = PatientModelProjection::merge(self::TURN_1, self::TURN_2);

        // "n/a" is a stated fact about an aortic aneurysm, not an absence.
        $this->assertSame('n/a', $merged['laterality']);
    }

    public function test_an_explicit_new_value_overwrites_the_established_one(): void
    {
        $merged = PatientModelProjection::merge(self::TURN_1, [
            'lesion' => 'abdominal aortic aneurysm now 6.4 cm on surveillance',
            'anatomy' => 'abdominal aortic aneurysm',
            'diameter_value' => '6.4',
            'diameter_unit' => 'cm',
        ]);

        // Genuine growth must be recorded; this is not a case for retention.
        $this->assertSame('6.4', $merged['diameter_value']);
        $this->assertStringContainsString('6.4 cm', $merged['lesion']);
        $this->assertStringNotContainsString('5.8', $merged['lesion']);
    }

    public function test_explicitly_unknown_is_distinct_from_unmentioned(): void
    {
        $merged = PatientModelProjection::merge(self::TURN_1, [
            'lesion' => 'aneurysm, size not measured on this study',
            'anatomy' => 'abdominal aortic aneurysm',
            'diameter_value' => 'unknown',
            'diameter_unit' => '',
        ]);

        // "unknown" is a clinical statement and must overwrite, not be quietly
        // replaced by the earlier 5.8 cm.
        $this->assertSame('unknown', $merged['diameter_value']);
        $this->assertStringContainsString('diameter unknown', $merged['lesion']);
    }

    public function test_a_first_turn_with_no_prior_state_is_untouched(): void
    {
        $merged = PatientModelProjection::merge([], self::TURN_1);

        $this->assertSame('5.8', $merged['diameter_value']);
        $this->assertStringContainsString('5.8 cm', $merged['lesion']);
    }
}
