from collections import Counter

import pytest

from openwebui_tools.turn_classification_support import (
    classify_corpus_row,
    load_turn_corpus,
)


CORPUS = load_turn_corpus()


@pytest.mark.parametrize("row", CORPUS, ids=lambda row: row["id"])
def test_existing_turn_classification_matches_phase_zero_observation(row):
    decision = classify_corpus_row(row)

    assert decision.turn_class == row["baseline_observed"]


def test_corpus_has_required_size_classes_and_greek_cases():
    expected_counts = Counter(row["expected"] for row in CORPUS)

    assert len(CORPUS) >= 40
    assert set(expected_counts) == {
        "NEW_CASE",
        "EXPLICIT_NEW_CASE",
        "GATE_REPLY",
        "FOLLOWUP_VAGUE",
        "FOLLOWUP_SUBSTANTIVE",
        "KNOWLEDGE",
        "GUARDRAIL",
    }
    assert all(count >= 4 for count in expected_counts.values())
    assert sum(
        any("ασθεν" in str(message.get("content", "")).lower() for message in row["messages"])
        for row in CORPUS
    ) >= 2
