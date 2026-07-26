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
        $this->assertLessThanOrEqual(300, mb_strlen($built['citation']));
        $this->assertGreaterThan(300, mb_strlen($built['narrative']));
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

    public function test_must_include_terms_survive_the_citation_character_budget(): void
    {
        // The core question alone is 80 characters; this budget leaves room for
        // exactly one short term, so it proves the ordering rather than just the cap.
        config()->set('gate-v2.retrieval.citation_query_max_chars', 92);

        $citation = (new GateRetrievalQueryBuilder)->build($this->orient())['citation'];

        // Under a tight budget the planner's mandatory terms must win over the
        // optional expansion/interpretation terms.
        $this->assertLessThanOrEqual(92, mb_strlen($citation));
        $this->assertStringContainsString('bridging', $citation);
        $this->assertStringNotContainsString('stroke risk', $citation);
    }

    public function test_narrative_query_is_unchanged_in_shape(): void
    {
        $narrative = (new GateRetrievalQueryBuilder)->build($this->orient())['narrative'];

        $this->assertStringContainsString('Clinical question:', $narrative);
        $this->assertStringContainsString('Patient context:', $narrative);
        $this->assertStringContainsString('Anatomical anchors:', $narrative);
    }
}
