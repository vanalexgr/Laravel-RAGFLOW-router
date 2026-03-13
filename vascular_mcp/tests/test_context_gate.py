"""
Section 5.1 — Unit tests for the clinical context gate.

Tests vascular_assess_context_gaps in isolation — no Laravel calls required.
All expected statuses are derived from the document test matrix plus
the gate logic in server.py.
"""

import asyncio
import json

import server  # injected via conftest.py sys.path


def gate(question: str, history: list[str] | None = None) -> dict:
    """Synchronous wrapper around the async gate tool."""
    raw = asyncio.run(
        server.vascular_assess_context_gaps(
            question=question,
            history=history or [],
        )
    )
    return json.loads(raw)


# ─── Knowledge questions (should always PROCEED without hitting scenario rules) ─

class TestKnowledgeQuestions:
    def test_aaa_threshold(self):
        """'What is the threshold for AAA repair?' → PROCEED (knowledge question)."""
        r = gate("What is the threshold for AAA repair?")
        assert r["status"] == "PROCEED"
        assert r.get("reason") == "knowledge_question"

    def test_esvs_guidelines_generic(self):
        """'What are ESVS guidelines?' → PROCEED (not a patient case)."""
        r = gate("What are ESVS guidelines?")
        assert r["status"] == "PROCEED"

    def test_rutherford_classification(self):
        """'What is the Rutherford classification for acute limb ischaemia?' → PROCEED."""
        r = gate("What is the Rutherford classification for acute limb ischaemia?")
        assert r["status"] == "PROCEED"

    def test_cea_timing_after_tia(self):
        """'What are the ESVS recommendations for CEA timing after TIA?' → PROCEED."""
        r = gate("What are the ESVS recommendations for CEA timing after TIA?")
        assert r["status"] == "PROCEED"

    def test_diameter_threshold_definition(self):
        """Diameter threshold question → PROCEED."""
        r = gate("What is the diameter threshold for elective AAA repair in fit patients?")
        assert r["status"] == "PROCEED"

    def test_anticoag_after_dvt_in_cancer(self):
        """Population-level anticoag question → PROCEED."""
        r = gate("What anticoagulation is recommended after DVT in cancer patients?")
        assert r["status"] == "PROCEED"


# ─── Patient cases missing context (should return NEEDS_CLARIFICATION) ──────

class TestNeedsClarification:
    def test_carotid_stenosis_no_context(self):
        """'Patient with carotid stenosis' → NEEDS_CLARIFICATION (symptomatic + degree missing)."""
        r = gate("Patient with carotid stenosis")
        assert r["status"] == "NEEDS_CLARIFICATION"
        assert r["scenario"] == "carotid_stenosis"
        assert len(r["clarification_questions"]) >= 1

    def test_dvt_no_context(self):
        """'Patient with DVT' → NEEDS_CLARIFICATION (provoking factors + history missing)."""
        r = gate("Patient with DVT")
        assert r["status"] == "NEEDS_CLARIFICATION"
        assert r["scenario"] == "dvt_pe"
        assert len(r["clarification_questions"]) >= 1

    def test_ali_no_context(self):
        """'Patient with ALI' → NEEDS_CLARIFICATION (Rutherford class + aetiology missing)."""
        r = gate("Patient with ALI")
        assert r["status"] == "NEEDS_CLARIFICATION"
        assert r["scenario"] == "ali"
        assert len(r["clarification_questions"]) >= 1

    def test_aaa_no_context(self):
        """'Patient with AAA found on ultrasound' → NEEDS_CLARIFICATION (diameter + fitness missing)."""
        r = gate("I have a patient with AAA found on ultrasound")
        assert r["status"] == "NEEDS_CLARIFICATION"
        assert r["scenario"] == "aaa_treatment"

    def test_carotid_partial_context_needs_clarification(self):
        """Carotid with neither symptomatic status nor degree → NEEDS_CLARIFICATION."""
        # No "symptomatic"/"asymptomatic"/"TIA" and no "%" → both categories absent
        r = gate("Patient with carotid stenosis referred for evaluation")
        assert r["status"] == "NEEDS_CLARIFICATION"
        assert r["scenario"] == "carotid_stenosis"

    def test_clti_no_context(self):
        """Patient with CLTI, no workup info → NEEDS_CLARIFICATION.
        Note: must use _PATIENT_CASE_RE-matching language (e.g. 'patient with').
        'presenting with' doesn't match the regex; 'patient with' does.
        """
        r = gate("Patient with rest pain and tissue loss, CLTI")
        assert r["status"] == "NEEDS_CLARIFICATION"
        assert r["scenario"] == "clti"

    def test_graft_infection_no_context(self):
        """Infected graft, no signs or timing info → NEEDS_CLARIFICATION.
        Note: avoid 'aortic' in query because it matches category 2 present_if,
        which would reduce absent count below min_absent=2.
        """
        r = gate("Patient with infected bypass graft, no other information available")
        assert r["status"] == "NEEDS_CLARIFICATION"
        assert r["scenario"] == "graft_infection"

    def test_type_b_dissection_no_context(self):
        """Type B dissection without complicated/phase info → NEEDS_CLARIFICATION."""
        r = gate("Patient with type B aortic dissection")
        assert r["status"] == "NEEDS_CLARIFICATION"
        assert r["scenario"] == "type_b_dissection"


# ─── Patient cases with sufficient context (should PROCEED) ──────────────────

class TestSufficientContext:
    def test_carotid_with_symptomatic_and_degree(self):
        """'75-year-old man with 70% carotid stenosis, recent TIA' → PROCEED."""
        r = gate("75-year-old man with 70% carotid stenosis, recent TIA")
        assert r["status"] == "PROCEED"

    def test_complicated_type_b_acute(self):
        """'Complicated type B dissection, acute phase' → PROCEED."""
        r = gate("Complicated type B dissection, acute phase")
        assert r["status"] == "PROCEED"

    def test_dvt_with_provoked_and_first_episode(self):
        """'Patient with DVT after long-haul flight, first episode' → PROCEED."""
        r = gate("Patient with DVT after long-haul flight, first episode, no cancer")
        assert r["status"] == "PROCEED"

    def test_ali_with_rutherford_and_aetiology(self):
        """Patient with ALI, Rutherford class II, suspected embolism → PROCEED."""
        r = gate(
            "72-year-old man with acute limb ischaemia, Rutherford class II, "
            "known atrial fibrillation (cardiac source), 4 hours duration"
        )
        assert r["status"] == "PROCEED"

    def test_aaa_with_diameter_and_fitness(self):
        """Patient with 5.8 cm AAA, fit for surgery → PROCEED."""
        r = gate("Patient with 5.8 cm AAA, no significant cardiac comorbidity, fit for surgery")
        assert r["status"] == "PROCEED"


# ─── Follow-up turns (gate must not re-fire) ─────────────────────────────────

class TestFollowUpTurns:
    def test_follow_up_with_long_assistant_history(self):
        """When a prior long assistant response is in history, gate returns PROCEED."""
        long_prior = (
            "Based on ESVS guidelines, for a 75-year-old man with symptomatic 70% carotid "
            "stenosis and a recent TIA, CEA is recommended within 14 days of the index event. "
            "Grade A recommendation. Consider patient fitness and anatomical suitability. "
            "The risk of stroke without intervention is approximately 10-15% at 90 days. " * 5
        )
        r = gate("What about surveillance after CEA?", history=[long_prior])
        assert r["status"] == "PROCEED"
        assert r.get("reason") == "follow_up_turn"

    def test_same_case_medication_followup(self):
        """Follow-up about medication on same case → PROCEED without re-gating."""
        prior = "ESVS recommends CEA within 14 days for symptomatic high-grade stenosis. " * 15
        r = gate("What if the patient is on warfarin?", history=[prior])
        assert r["status"] == "PROCEED"

    def test_no_history_short_followup_cue_without_patient_language(self):
        """Short follow-up cue with no patient-case language and empty history → PROCEED."""
        r = gate("What about surveillance?", history=[])
        # No patient-case RE match → not_a_patient_case
        assert r["status"] == "PROCEED"
