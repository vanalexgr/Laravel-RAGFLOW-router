<?php

namespace Tests\Unit\GateEval;

use App\Ai\Gate\Retrieval\GateRetrievalQueryBuilder;
use Tests\TestCase;

class GateRetrievalQueryBuilderTest extends TestCase
{
    /** @return array<string, mixed> */
    private function orient(): array
    {
        return [
            'core_question' => 'How should apixaban be managed perioperatively for urgent carotid endarterectomy?',
            'patient_model' => [
                'demographics' => '74-year-old man',
                'lesion' => 'symptomatic 80% left internal carotid artery stenosis',
                'symptom_status' => 'recent TIA 10 days ago',
                'comorbidities' => 'persistent atrial fibrillation, hypertension, type 2 diabetes',
                'medications' => 'apixaban 5 mg twice daily, atorvastatin',
                'imaging' => 'CTA and carotid duplex',
            ],
            'expansion_terms' => ['perioperative anticoagulation'],
            'interpretation_terms' => ['stroke risk'],
            'must_include_terms' => ['bridging', 'apixaban interruption'],
        ];
    }

    public function test_citation_query_stays_terse_while_narrative_keeps_full_context(): void
    {
        $built = (new GateRetrievalQueryBuilder)->build($this->orient());

        // Run 8 sent both datasets the same ~800-character blob and the
        // recommendations dataset returned nothing on most branches.
        $this->assertLessThanOrEqual(100, mb_strlen($built['citation']));
        $this->assertGreaterThan(300, mb_strlen($built['narrative']));
    }

    public function test_citation_query_drops_the_question_entirely(): void
    {
        $citation = (new GateRetrievalQueryBuilder)->build($this->orient())['citation'];

        // Question FORM is what fails against the recommendations documents, at any
        // length: 156- and 116-character question forms both returned zero
        // recommendations where 98- and 54-character term phrases returned 2-6.
        $this->assertStringNotContainsString('How should', $citation);
        $this->assertStringNotContainsString('?', $citation);
    }

    public function test_citation_query_omits_text_that_never_appears_in_a_recommendation_row(): void
    {
        $citation = (new GateRetrievalQueryBuilder)->build($this->orient())['citation'];

        $this->assertStringNotContainsString('Patient context', $citation);
        $this->assertStringNotContainsString('74-year-old', $citation);
        $this->assertStringNotContainsString('Scope anchors', $citation);
        $this->assertStringNotContainsString('atorvastatin', $citation);
        $this->assertStringNotContainsString('Return the directly applicable', $citation);
    }

    public function test_must_include_terms_win_the_citation_character_budget(): void
    {
        // Room for the two mandatory terms only ("bridging apixaban interruption"
        // is 30 chars), so this proves ordering rather than just the cap.
        config()->set('gate-v2.retrieval.citation_query_max_chars', 31);

        $citation = (new GateRetrievalQueryBuilder)->build($this->orient())['citation'];

        $this->assertLessThanOrEqual(31, mb_strlen($citation));
        $this->assertStringContainsString('bridging', $citation);
        $this->assertStringContainsString('apixaban interruption', $citation);
        $this->assertStringNotContainsString('stroke risk', $citation);
    }

    public function test_a_termless_plan_falls_back_to_a_declarative_question(): void
    {
        $orient = $this->orient();
        $orient['expansion_terms'] = [];
        $orient['interpretation_terms'] = [];
        $orient['must_include_terms'] = [];

        $citation = (new GateRetrievalQueryBuilder)->build($orient)['citation'];

        $this->assertNotSame('', $citation);
        $this->assertStringNotContainsString('?', $citation);
        $this->assertStringStartsNotWith('How should', $citation);
    }

    public function test_retry_queries_are_shaped_the_same_way(): void
    {
        $shaped = (new GateRetrievalQueryBuilder)->shapeCitationQuery(
            'What is the recommended perioperative management of apixaban before urgent carotid endarterectomy?',
        );

        $this->assertLessThanOrEqual(100, mb_strlen($shaped));
        $this->assertStringNotContainsString('?', $shaped);
        $this->assertStringStartsWith('perioperative management', $shaped);
    }

    public function test_narrative_query_is_unchanged_in_shape(): void
    {
        $narrative = (new GateRetrievalQueryBuilder)->build($this->orient())['narrative'];

        $this->assertStringContainsString('Clinical question:', $narrative);
        $this->assertStringContainsString('Patient context:', $narrative);
        $this->assertStringContainsString('Anatomical anchors:', $narrative);
    }

    public function test_deterministic_core_is_identical_across_different_orient_terms(): void
    {
        $patientModel = [
            'lesion' => 'peripheral arterial disease with lower limb ischemia requiring vein below-knee bypass',
            'prior_interventions' => ['vein BK bypass'],
            'symptom_status' => 'rest pain preoperative',
        ];
        $variantA = [
            'core_question' => 'What antithrombotic therapy is appropriate?',
            'patient_model' => $patientModel,
            'must_include_terms' => ['rest pain', 'bleeding risk'],
        ];
        $variantB = [
            'core_question' => 'What antithrombotic therapy is appropriate?',
            'patient_model' => $patientModel,
            'must_include_terms' => ['vein bypass', 'critical limb-threatening ischaemia'],
        ];

        $first = (new GateRetrievalQueryBuilder)->build($variantA);
        $second = (new GateRetrievalQueryBuilder)->build($variantB);

        $expected = [
            'antithrombotic therapy after vein bypass',
            'critical limb-threatening ischaemia revascularisation',
        ];
        $this->assertSame($expected, $first['citation_core_queries']);
        $this->assertSame($expected, $second['citation_core_queries']);
        $this->assertSame($expected, $first['citation_queries']);
        $this->assertSame($expected, $second['citation_queries']);
    }

    public function test_raw_turn_restores_vein_bypass_core_when_orient_drops_it(): void
    {
        $built = (new GateRetrievalQueryBuilder)->buildCitationQueries(
            ['lesion' => 'peripheral arterial disease'],
            [],
            'Clarifications: vein BK bypass, rest pain pre-op, no high bleeding risk.',
        );

        $this->assertSame([
            'antithrombotic therapy after vein bypass',
            'critical limb-threatening ischaemia revascularisation',
        ], $built['core']);
    }

    /**
     * @dataProvider suppressedRawTurnProvider
     */
    public function test_raw_turn_does_not_anchor_negated_or_family_attributed_vein_bypass(
        string $rawTurnText,
    ): void {
        $built = (new GateRetrievalQueryBuilder)->buildCitationQueries([], [], $rawTurnText);

        $this->assertNotContains('antithrombotic therapy after vein bypass', $built['core']);
        $this->assertNotContains('antithrombotic therapy after bypass', $built['core']);
    }

    /** @return array<string, array{string}> */
    public static function suppressedRawTurnProvider(): array
    {
        return [
            'no' => ['The patient has no vein bypass.'],
            'not' => ['This was not a vein bypass.'],
            'without' => ['The patient presented without a vein bypass.'],
            'denies' => ['The patient denies a vein bypass.'],
            'negative for' => ['The history is negative for vein bypass.'],
            'ruled out' => ['The team ruled out a vein bypass.'],
            'family history of' => ['There is a family history of vein bypass.'],
            'father' => ['Her father had a vein bypass.'],
            'mother' => ['His mother underwent a vein bypass.'],
            'sibling' => ['A sibling previously had a vein bypass.'],
        ];
    }

    public function test_every_multi_query_obeys_the_per_query_character_cap(): void
    {
        config()->set('gate-v2.retrieval.citation_multi_query_max_chars', 42);
        config()->set('gate-v2.retrieval.citation_multi_query_max', 4);
        $built = (new GateRetrievalQueryBuilder)->build([
            'core_question' => 'What treatment is appropriate?',
            'patient_model' => [
                'lesion' => 'peripheral arterial disease with lower limb ischemia requiring vein below-knee bypass',
                'prior_interventions' => ['vein BK bypass'],
            ],
            'must_include_terms' => [
                'an exceptionally long additional clinical concept that must be truncated',
                'duplex surveillance after bypass',
            ],
        ]);

        $this->assertCount(4, $built['citation_queries']);
        foreach ($built['citation_queries'] as $query) {
            $this->assertLessThanOrEqual(42, mb_strlen($query), $query);
        }
    }

    public function test_feature_flag_restores_the_single_legacy_query(): void
    {
        config()->set('gate-v2.retrieval.citation_multi_query', false);

        $built = (new GateRetrievalQueryBuilder)->build($this->orient());

        $this->assertSame([$built['citation']], $built['citation_queries']);
        $this->assertSame([], $built['citation_core_queries']);
    }
}
