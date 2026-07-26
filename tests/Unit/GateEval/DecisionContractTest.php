<?php

namespace Tests\Unit\GateEval;

use App\Ai\Gate\Decision\DecisionContractValidator;
use App\Ai\Gate\Decision\DecisionValidationResult;
use PHPUnit\Framework\TestCase;

final class DecisionContractTest extends TestCase
{
    private DecisionContractValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var array<string, mixed> $rules */
        $rules = require dirname(__DIR__, 3).'/config/gate-decision.php';
        $this->validator = new DecisionContractValidator($rules);
    }

    public function test_urgent_carotid_af_recent_gi_bleed_rejects_evasion_and_accepts_committed_plan(): void
    {
        $old = $this->decision([
            'baseline_pathway' => 'Symptomatic carotid stenosis is considered for CEA.',
            'patient_deviations' => ['Atrial fibrillation treated with apixaban', 'Recent GI bleed'],
            'actionable_plan' => [
                'timing' => 'Discuss timing at the MDT.',
                'pharmacotherapy_regimen' => '',
                'what_not_to_do' => [],
                'deferral_justification' => 'NOT_DEFERRED',
                'antithrombotic_combination_justification' => 'NOT_APPLICABLE',
            ],
        ]);

        $this->assertRejectedWith($old, 'UNWARRANTED_DEFERRAL');
        $this->assertRejectedWith($old, 'CHECKLIST_ITEM_MISSING');

        $committed = $this->decision([
            'baseline_pathway' => 'Urgent CEA is the default for recently symptomatic carotid stenosis.',
            'patient_deviations' => ['Atrial fibrillation treated with apixaban', 'Recent GI bleed'],
            'actionable_plan' => [
                'timing' => 'Perform urgent CEA within days.',
                'pharmacotherapy_regimen' => 'Interrupt apixaban before CEA; use perioperative aspirin, then resume apixaban after haemostasis is secure.',
                'what_not_to_do' => [
                    'Do not use routine LMWH bridging for ordinary atrial fibrillation.',
                    'Do not prescribe long-term triple therapy.',
                ],
                'deferral_justification' => 'NOT_DEFERRED',
                'antithrombotic_combination_justification' => 'NOT_APPLICABLE',
            ],
            'escalation_and_reassessment' => [
                'trigger_event' => 'Recurrent GI bleeding or failure to achieve postoperative haemostasis.',
                'action_on_trigger' => 'Delay apixaban restart and reassess the bleeding source.',
            ],
        ]);

        $this->assertTrue($this->validator->validate($committed, 'carotid stenosis on a DOAC')->accepted());
    }

    public function test_post_infrainguinal_vein_bypass_rejects_evasion_and_accepts_primary_regimen(): void
    {
        $old = $this->decision([
            'baseline_pathway' => 'A vitamin K antagonist could be considered.',
            'patient_deviations' => [],
            'actionable_plan' => [
                'timing' => 'Follow local protocol.',
                'pharmacotherapy_regimen' => 'Consider warfarin after MDT review.',
                'what_not_to_do' => [],
                'deferral_justification' => 'NOT_DEFERRED',
                'antithrombotic_combination_justification' => 'NOT_APPLICABLE',
            ],
        ]);

        $this->assertRejectedWith($old, 'UNWARRANTED_DEFERRAL');
        $this->assertRejectedWith($old, 'CHECKLIST_ITEM_MISSING');

        $committed = $this->decision([
            'baseline_pathway' => 'After infrainguinal vein bypass, use aspirin 75-100 mg daily plus rivaroxaban 2.5 mg twice daily where bleeding risk allows.',
            'patient_deviations' => [],
            'actionable_plan' => [
                'timing' => 'Start the post-bypass regimen postoperatively when surgical haemostasis permits.',
                'pharmacotherapy_regimen' => 'Aspirin 75-100 mg daily plus rivaroxaban 2.5 mg twice daily.',
                'what_not_to_do' => ['Do not continue clopidogrel for a prolonged course without another indication.'],
                'deferral_justification' => 'NOT_DEFERRED',
                'antithrombotic_combination_justification' => 'Post-lower-limb revascularisation vascular protection.',
            ],
        ]);

        $this->assertTrue($this->validator->validate($committed, 'antithrombotic therapy after infrainguinal vein bypass')->accepted());
    }

    public function test_urgent_carotid_on_apixaban_rejects_unsafe_old_shape_and_accepts_no_bridging_plan(): void
    {
        $old = $this->decision([
            'baseline_pathway' => 'CEA may be considered after review.',
            'patient_deviations' => ['Takes apixaban'],
            'actionable_plan' => [
                'timing' => 'Individualise the timing.',
                'pharmacotherapy_regimen' => 'Continue long-term apixaban plus aspirin indefinitely.',
                'what_not_to_do' => [],
                'deferral_justification' => 'NOT_DEFERRED',
                'antithrombotic_combination_justification' => 'NOT_APPLICABLE',
            ],
        ]);

        $this->assertRejectedWith($old, 'UNWARRANTED_DEFERRAL');
        $this->assertRejectedWith($old, 'UNJUSTIFIED_LONG_TERM_ANTICOAGULANT_ANTIPLATELET');

        $committed = $this->decision([
            'baseline_pathway' => 'Proceed to urgent CEA for recently symptomatic carotid stenosis.',
            'patient_deviations' => ['Chronic apixaban for atrial fibrillation'],
            'actionable_plan' => [
                'timing' => 'Perform urgent CEA within 14 days.',
                'pharmacotherapy_regimen' => 'Interrupt apixaban preoperatively, give perioperative aspirin, and restart apixaban once postoperative haemostasis is secure.',
                'what_not_to_do' => [
                    'Do not use routine heparin bridging.',
                    'Do not continue long-term aspirin with apixaban without a separate indication.',
                ],
                'deferral_justification' => 'NOT_DEFERRED',
                'antithrombotic_combination_justification' => 'NOT_APPLICABLE',
            ],
        ]);

        $this->assertTrue($this->validator->validate($committed, 'urgent carotid endarterectomy while taking apixaban')->accepted());
    }

    public function test_clti_bypass_with_itp_rejects_silence_and_accepts_default_then_modification(): void
    {
        $old = $this->decision([
            'baseline_pathway' => 'The guideline does not directly cover ITP.',
            'patient_deviations' => ['Immune thrombocytopenia'],
            'actionable_plan' => [
                'timing' => 'EVIDENCE_ABSENT',
                'pharmacotherapy_regimen' => 'EVIDENCE_ABSENT',
                'what_not_to_do' => [],
                'deferral_justification' => 'NOT_DEFERRED',
                'antithrombotic_combination_justification' => 'NOT_APPLICABLE',
            ],
        ]);

        $this->assertRejectedWith($old, 'UNCODED_EVIDENCE_ABSENCE');
        $this->assertRejectedWith($old, 'EMPTY_ACTIONABLE_PLAN_WITH_COVERAGE');

        $committed = $this->decision([
            'baseline_pathway' => 'The normal post-bypass regimen is aspirin 75-100 mg daily plus rivaroxaban 2.5 mg twice daily where bleeding risk allows.',
            'patient_deviations' => ['Immune thrombocytopenia increases bleeding risk and may modify the default regimen.'],
            'actionable_plan' => [
                'timing' => 'Start after bypass once haemostasis and the current platelet trend permit.',
                'pharmacotherapy_regimen' => 'Use aspirin 75-100 mg daily; add rivaroxaban 2.5 mg twice daily only when the bleeding risk permits.',
                'what_not_to_do' => ['Do not apply the dual-pathway regimen without reassessing active bleeding and platelet trend.'],
                'deferral_justification' => 'NOT_DEFERRED',
                'antithrombotic_combination_justification' => 'Post-lower-limb bypass vascular protection when bleeding risk permits.',
            ],
            'escalation_and_reassessment' => [
                'trigger_event' => 'Active bleeding or a clinically important platelet decline.',
                'action_on_trigger' => 'Withhold rivaroxaban and reassess with haematology.',
            ],
        ]);

        $this->assertTrue($this->validator->validate($committed, 'CLTI bypass antithrombotic plan with immune thrombocytopenia')->accepted());
    }

    public function test_unwarranted_mdt_deferral_is_rejected(): void
    {
        $decision = $this->decision([
            'actionable_plan' => [
                'timing' => 'Refer to the MDT.',
                'pharmacotherapy_regimen' => 'No plan pending multidisciplinary review.',
                'what_not_to_do' => ['Avoid treatment until review.'],
                'deferral_justification' => 'NOT_DEFERRED',
                'antithrombotic_combination_justification' => 'NOT_APPLICABLE',
            ],
        ]);

        $result = $this->validator->validate($decision);

        $this->assertRejectedWithResult($result, 'UNWARRANTED_DEFERRAL');
        $this->assertStringContainsString('DECISION CONTRACT REJECTED', $result->revisionPrompt());
    }

    public function test_typed_legitimate_deferral_accepts_evidence_absent_without_fabrication(): void
    {
        $decision = $this->decision([
            'baseline_pathway' => 'The retrieved guideline supplies no treatment pathway for this condition.',
            'actionable_plan' => [
                'timing' => 'EVIDENCE_ABSENT',
                'pharmacotherapy_regimen' => 'EVIDENCE_ABSENT',
                'what_not_to_do' => ['EVIDENCE_ABSENT'],
                'deferral_justification' => 'UNRESOLVABLE_CONTRAINDICATION',
                'antithrombotic_combination_justification' => 'NOT_APPLICABLE',
            ],
            'evidence_status' => [
                'coverage' => 'not_covered',
                'core_question' => 'Treatment',
                'covered_components' => [],
                'gap_summary' => 'No secondary option is present in retrieved evidence.',
            ],
        ]);

        $this->assertTrue($this->validator->validate($decision)->accepted());
    }

    public function test_long_term_anticoagulant_plus_antiplatelet_without_indication_is_flagged(): void
    {
        $decision = $this->decision([
            'actionable_plan' => [
                'timing' => 'After discharge.',
                'pharmacotherapy_regimen' => 'Continue apixaban and clopidogrel long-term.',
                'what_not_to_do' => ['Do not miss doses.'],
                'deferral_justification' => 'NOT_DEFERRED',
                'antithrombotic_combination_justification' => 'NOT_APPLICABLE',
            ],
        ]);

        $this->assertRejectedWith($decision, 'UNJUSTIFIED_LONG_TERM_ANTICOAGULANT_ANTIPLATELET');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function decision(array $overrides = []): array
    {
        return array_replace([
            'baseline_pathway' => 'Use the guideline default.',
            'patient_deviations' => [],
            'actionable_plan' => [
                'timing' => 'Routine follow-up.',
                'pharmacotherapy_regimen' => 'No pharmacotherapy change.',
                'what_not_to_do' => ['Do not delay indicated care.'],
                'deferral_justification' => 'NOT_DEFERRED',
                'antithrombotic_combination_justification' => 'NOT_APPLICABLE',
            ],
            'escalation_and_reassessment' => [
                'trigger_event' => 'Clinical deterioration.',
                'action_on_trigger' => 'Reassess the plan.',
            ],
            'unknowns' => [],
            'questions' => [],
            'evidence_status' => [
                'coverage' => 'covered',
                'core_question' => 'Management',
                'covered_components' => ['Default treatment'],
                'gap_summary' => '',
            ],
            'guideline_grounded_answer' => 'Guideline-grounded answer.',
            'interpretive_frame' => 'Interpretive frame.',
            'assumptions' => [],
            'confidence' => 0.8,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $decision
     */
    private function assertRejectedWith(array $decision, string $code): void
    {
        $this->assertRejectedWithResult($this->validator->validate($decision), $code);
    }

    private function assertRejectedWithResult(DecisionValidationResult $result, string $code): void
    {
        $this->assertTrue($result->rejected(), 'Expected the decision contract to reject the fixture.');
        $this->assertContains($code, array_column($result->violations, 'code'));
    }
}
