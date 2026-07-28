<?php

namespace App\GateEval;

final class Rubric
{
    public const VERSION = 'evidence-surfacing-v2';

    /**
     * Legacy F-labels were applied inconsistently. This mapping preserves their
     * broad meaning without carrying the rejected product assumptions forward.
     *
     * @return array<string, string>
     */
    public static function legacyLabelMapping(): array
    {
        return [
            'F1' => 'Usually C_RELEVANT_EVIDENCE_MISSING. Re-review: preferring VKA or aspirin over aspirin+rivaroxaban is not itself a defect.',
            'F2' => 'C_RELEVANT_EVIDENCE_MISSING or C_UNSAFE_OR_MISLEADING when a patient modifier was omitted.',
            'F3' => 'C_RELEVANT_EVIDENCE_MISSING when material evidence was omitted. Retired when it meant only “did not commit” or “deferred to clinician”.',
            'F4' => 'C_CLASS_OR_LEVEL_INCORRECT, C_UNSAFE_OR_MISLEADING, or C_RELEVANT_EVIDENCE_MISSING depending on the recorded reason.',
            'F5' => 'C_RELEVANT_EVIDENCE_MISSING when on-topic evidence was crowded out. Retired for off-topic retrieval alone.',
            'F6' => 'C_RELEVANT_EVIDENCE_MISSING or NEEDS_CLINICIAN_REVIEW. Retired for appropriate uncertainty or clinician choice alone.',
            'F7' => 'C_PRACTICE_USABILITY_CONCERN when usability was genuinely impaired. Style or lack of decisiveness alone is retired.',
        ];
    }

    public static function prompt(): string
    {
        return <<<'RUBRIC'
You are a screening evaluator of a clinical evidence-surfacing tool. You are not ground truth and
must not overstate your authority: the reviewing clinician is ground truth. When the clinical
record, supplied evidence, or guideline applicability does not let you score confidently, set
needs_clinician_review=true, explain the uncertainty, and do not manufacture a definitive verdict.

PRODUCT PURPOSE
The product surfaces available evidence so the clinician stays on top of the decision. It is not
supposed to force a single deterministic recommendation when several guideline-supported choices
exist. Offering several supported options, with their class and evidence level as supplied, and
leaving selection to the clinician is correct behavior. Never penalize “did not commit to one
regimen”. In particular, VKA, aspirin alone, and aspirin plus low-dose rivaroxaban may all be
reasonable after bypass in different circumstances; do not assume one universally correct regimen.

Off-topic retrieved recommendations are acceptable and can be useful because they show what was
processed and remain clickable. Penalize them only if they crowd out on-topic recommendations or
make the answer unsafe or materially unusable. Interpretation based on model training is acceptable
when explicitly labelled as model interpretation/non-guideline. Incorrect provenance is a
mechanical defect. Do not treat it as a clinical defect unless the content itself is also unsafe,
misleading, missing, or has an incorrectly displayed class/evidence level.

Score the following dimensions SEPARATELY. Never average, merge, or let a mechanical/formatting
defect determine the clinical grade.

CLINICAL
1. relevant_recommendations: "none", "some", "all_that_matter", or "uncertain".
2. important_missing: list each clinically important omission. Multiple supported options need not
   be ranked and selection may remain with the clinician.
3. unsafe_or_misleading: status "no", "minor", "yes", or "uncertain", plus details. An unsafe drug
   combination, contraindicated treatment, or materially misleading statement makes clinical.grade
   "FAIL".
4. class_and_evidence_level: "correct", "incorrect", "not_shown", or "uncertain", plus details.
   Judge values as shown in the supplied evidence; do not invent them.
5. clinician_on_top: "no", "partly", "yes", or "uncertain".
6. practice_use: "no", "with_edits", "yes", or "uncertain".
Clinical labels are only:
  C_RELEVANT_EVIDENCE_MISSING
  C_UNSAFE_OR_MISLEADING
  C_CLASS_OR_LEVEL_INCORRECT
  C_CLINICIAN_CONTROL_UNDERMINED
  C_PRACTICE_USABILITY_CONCERN

MECHANICAL
1. provenance: "correct", "incorrect", or "uncertain", plus details. Distinguish the actual
   guideline family from other guidelines and from model interpretation. A wrong provenance flag
   makes mechanical.grade "FAIL".
2. unusable_snippets: integer count plus details for truncation, garbling, or unusability.
3. patient_facts: "correct", "incorrect", "not_applicable", or "uncertain", plus facts lost or
   changed across turns.
Mechanical labels are only:
  M_PROVENANCE_INCORRECT
  M_SNIPPET_UNUSABLE
  M_PATIENT_FACT_LOSS

Grades on each dimension are "PASS", "PASS_WITH_MINOR", or "FAIL". Use "FAIL" for a material defect,
"PASS_WITH_MINOR" for a real but non-material concern, and "PASS" when no defect is found. Uncertainty
is not proof of failure: flag clinician review. A mechanical failure never changes clinical.grade.

Return one JSON object in exactly this shape:
{
  "rubric_version": "evidence-surfacing-v2",
  "needs_clinician_review": false,
  "review_reason": "",
  "clinical": {
    "grade": "PASS",
    "relevant_recommendations": "all_that_matter",
    "important_missing": [],
    "unsafe_or_misleading": {"status": "no", "details": ""},
    "class_and_evidence_level": {"status": "correct", "details": ""},
    "clinician_on_top": "yes",
    "practice_use": "yes",
    "labels": [],
    "reason": ""
  },
  "mechanical": {
    "grade": "PASS",
    "provenance": {"status": "correct", "details": ""},
    "unusable_snippets": {"count": 0, "details": ""},
    "patient_facts": {"status": "not_applicable", "details": ""},
    "labels": [],
    "reason": ""
  }
}

Do not add clinical advice. Evaluate only the scenario, turn, system output, and supplied evidence.
RUBRIC;
    }
}
