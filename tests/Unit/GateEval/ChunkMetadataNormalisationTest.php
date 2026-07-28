<?php

namespace Tests\Unit\GateEval;

use App\Ai\Gate\Retrieval\GateChunkCleaner;
use Tests\TestCase;

/**
 * A clinician reads recommendation class and evidence level to weigh options, so a
 * WRONG value is worse than an absent one — showing III where the source says IIa
 * inverts the strength of the recommendation.
 *
 * The OCR variants pinned here are real values observed in production metadata.
 */
class ChunkMetadataNormalisationTest extends TestCase
{
    public static function classCases(): array
    {
        return [
            'already canonical IIa' => ['IIa', 'IIa'],
            'already canonical IIb' => ['IIb', 'IIb'],
            'already canonical III' => ['III', 'III'],
            'already canonical I' => ['I', 'I'],
            // Observed in production: capital I, capital I, lowercase b.
            'observed Iib' => ['Iib', 'IIb'],
            'observed Iia' => ['Iia', 'IIa'],
            // Observed in production: capital I then a LOWERCASE L, an OCR confusion.
            'observed Ila' => ['Ila', 'IIa'],
            'lowercase iia' => ['iia', 'IIa'],
            'double lowercase L' => ['lla', 'IIa'],
            'padded' => ['  IIb  ', 'IIb'],
        ];
    }

    /** @dataProvider classCases */
    public function test_roman_classes_including_ocr_variants_are_canonicalised(string $in, string $out): void
    {
        $this->assertSame($out, GateChunkCleaner::normaliseClass($in));
    }

    public function test_numeric_grade_classes_are_preserved_and_never_mapped_to_roman(): void
    {
        // GVG GRADE class 2 is a WEAK recommendation. It is not ESVS IIa, and
        // silently equating the two systems would misrepresent recommendation strength.
        $this->assertSame('1', GateChunkCleaner::normaliseClass('1'));
        $this->assertSame('2', GateChunkCleaner::normaliseClass('2'));
        $this->assertSame('3', GateChunkCleaner::normaliseClass('3'));
    }

    public function test_an_unrecognised_class_is_reported_as_unparsed_never_guessed(): void
    {
        foreach (['IV', 'IIc', 'A', '4', 'strong', '??', 'Class IIa (see text)'] as $junk) {
            $this->assertSame(
                'unparsed',
                GateChunkCleaner::normaliseClass($junk),
                "'{$junk}' must degrade to unparsed rather than be guessed.",
            );
        }
    }

    public function test_evidence_levels_are_canonicalised_or_marked_unparsed(): void
    {
        $this->assertSame('A', GateChunkCleaner::normaliseLevel('a'));
        $this->assertSame('B', GateChunkCleaner::normaliseLevel(' B '));
        $this->assertSame('C', GateChunkCleaner::normaliseLevel('C'));
        $this->assertSame('unparsed', GateChunkCleaner::normaliseLevel('D'));
        $this->assertSame('unparsed', GateChunkCleaner::normaliseLevel('Level B'));
    }

    public function test_normalisation_is_applied_when_cleaning_a_real_chunk(): void
    {
        $cleaned = (new GateChunkCleaner)->clean([
            'content' => 'rec_id:38; class:Ila; level:b; rec_text_verbatim:Aspirin with rivaroxaban.',
        ], 3000);

        $this->assertSame('IIa', $cleaned['metadata']['recommendation_class']);
        $this->assertSame('B', $cleaned['metadata']['evidence_level']);
        // Ids are arbitrary strings across guideline families (38 vs 6.35) — untouched.
        $this->assertSame('38', $cleaned['metadata']['recommendation_id']);
    }
}
