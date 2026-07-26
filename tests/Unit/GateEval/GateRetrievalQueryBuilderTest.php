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
}
