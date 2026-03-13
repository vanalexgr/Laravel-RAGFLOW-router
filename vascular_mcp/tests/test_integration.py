"""
Section 5.2 — Integration tests against the live Laravel API.

Requires:
  - laravel-api.service running on the 135 VM
  - LARAVEL_API_KEY and LARAVEL_BASE_URL set in the environment (source .env)

These tests call vascular_consult_guidelines() directly (bypasses the running
HTTP service) and also call Laravel directly for raw response inspection.
"""

import asyncio
import json
import os
import time

import httpx
import pytest

import server

# ─── helpers ─────────────────────────────────────────────────────────────────

LARAVEL_BASE_URL = os.environ.get("LARAVEL_BASE_URL", "http://127.0.0.1")
LARAVEL_API_KEY  = os.environ.get("LARAVEL_API_KEY", "")
LARAVEL_TIMEOUT  = 90.0


def _laravel_post(query: str, guidelines: list[str] | None = None) -> dict:
    """Call Laravel directly and return the raw JSON response dict."""
    payload: dict = {"question": query, "history": []}
    if guidelines:
        payload["guidelines"] = guidelines
    resp = httpx.post(
        f"{LARAVEL_BASE_URL}/api/v1/vascular-consult",
        json=payload,
        headers={
            "Authorization": f"Bearer {LARAVEL_API_KEY}",
            "Content-Type": "application/json",
            "Accept": "application/json",
        },
        timeout=LARAVEL_TIMEOUT,
    )
    return resp


def tool(
    query: str,
    guidelines: list[str] | None = None,
    response_mode: str = "narrative",
) -> str:
    """Synchronous wrapper for vascular_consult_guidelines."""
    return asyncio.run(
        server.vascular_consult_guidelines(
            query=query, guidelines=guidelines, response_mode=response_mode
        )
    )


def gate(question: str, history: list[str] | None = None) -> dict:
    """Synchronous wrapper for vascular_assess_context_gaps; returns parsed dict."""
    raw = asyncio.run(server.vascular_assess_context_gaps(question=question, history=history))
    return json.loads(raw)


def list_guidelines() -> dict:
    """Synchronous wrapper for vascular_list_guidelines; returns parsed dict."""
    raw = asyncio.run(server.vascular_list_guidelines())
    return json.loads(raw)


# ─── Tests ───────────────────────────────────────────────────────────────────

class TestKnowledgeRetrieval:
    def test_aaa_threshold_returns_evidence(self):
        """Knowledge question → result text is non-empty and mentions AAA/diameter/repair."""
        result = tool("What is the recommended diameter threshold for elective AAA repair in fit patients?")
        assert not result.startswith("Error:"), f"Got error: {result}"
        assert len(result) > 100, "Expected substantive response, got: " + result[:200]
        lower = result.lower()
        assert any(kw in lower for kw in ["5.5", "55", "diameter", "aaa", "aneurysm", "repair"]), \
            f"Expected AAA content in response, got: {result[:400]}"

    def test_aaa_query_hits_aaa_dataset(self):
        """AAA query → selected_guidelines includes aortic/AAA dataset."""
        resp = _laravel_post(
            "What is the recommended diameter threshold for elective AAA repair in fit patients?"
        )
        assert resp.status_code == 200, f"HTTP {resp.status_code}: {resp.text[:200]}"
        data = resp.json()
        selected = data.get("selected_guidelines", [])
        assert any(
            "aneurysm" in g.lower() or "aaa" in g.lower() or "aortic" in g.lower()
            for g in selected
        ), f"Expected aortic/AAA guideline in selected_guidelines, got: {selected}"


class TestGuidelineSelection:
    def test_carotid_query_hits_carotid_dataset(self):
        """Carotid management query → selected_guidelines includes carotid dataset."""
        resp = _laravel_post("Management of symptomatic 80% carotid stenosis")
        assert resp.status_code == 200, f"HTTP {resp.status_code}: {resp.text[:200]}"
        data = resp.json()
        selected = data.get("selected_guidelines", [])
        assert any(
            "carotid" in g.lower() or "vertebral" in g.lower()
            for g in selected
        ), f"Expected carotid guideline in selected_guidelines, got: {selected}"

    def test_explicit_guideline_override_respected(self):
        """Explicit guidelines list → both appear in selected_guidelines."""
        resp = _laravel_post(
            query="Antithrombotic therapy after EVAR",
            guidelines=["abdominal_aortic_aneurysm", "antithrombotic_therapy"],
        )
        assert resp.status_code == 200, f"HTTP {resp.status_code}: {resp.text[:200]}"
        data = resp.json()
        # Laravel may use selected_guidelines or the requested keys directly
        result_text = data.get("result", "")
        selected = data.get("selected_guidelines", [])
        # Accept if either: both guidelines appear in selected_guidelines,
        # or both appear in the result text (guideline names cited)
        both_in_selected = (
            any("aneurysm" in g.lower() or "aortic" in g.lower() for g in selected) and
            any("antithrombotic" in g.lower() or "antiplatelet" in g.lower() for g in selected)
        )
        both_in_text = (
            any(kw in result_text.lower() for kw in ["evar", "aneurysm", "aortic"]) and
            any(kw in result_text.lower() for kw in ["antithrombotic", "antiplatelet", "anticoag"])
        )
        assert both_in_selected or both_in_text, (
            f"Expected both guidelines to appear.\n"
            f"selected_guidelines: {selected}\n"
            f"result snippet: {result_text[:300]}"
        )


class TestPerformance:
    def test_two_guideline_query_within_90_seconds(self):
        """2-guideline query must return within 90 seconds (no timeout)."""
        start = time.time()
        resp = _laravel_post(
            query="Management of symptomatic 80% carotid stenosis",
            guidelines=["carotid_vertebral", "antithrombotic_therapy"],
        )
        elapsed = time.time() - start
        assert elapsed < 90.0, f"Query took {elapsed:.1f}s — exceeds 90s limit"
        assert resp.status_code == 200, f"HTTP {resp.status_code} after {elapsed:.1f}s"
        data = resp.json()
        assert data.get("result") or data.get("narrative_chunks"), \
            "Empty response within time limit"


class TestErrorHandling:
    def test_invalid_api_key_returns_error_string(self):
        """Invalid API key → error string starting with 'Error: Invalid API key'."""
        original = server.LARAVEL_API_KEY
        server.LARAVEL_API_KEY = "definitely_invalid_key_xxxx_1234"
        try:
            result = tool("What is the threshold for AAA repair?")
        finally:
            server.LARAVEL_API_KEY = original  # always restore

        assert result.startswith("Error:"), f"Expected Error: prefix, got: {result[:200]}"
        assert "invalid" in result.lower() or "api key" in result.lower() or "401" in result, \
            f"Expected invalid API key message, got: {result[:200]}"

    def test_empty_query_returns_error_not_crash(self):
        """Empty query → returns error string (no exception raised)."""
        result = tool("")
        assert result.startswith("Error:"), f"Expected Error: prefix, got: {result[:200]}"
        assert "empty" in result.lower(), f"Expected 'empty' in error, got: {result}"


# ─── Tests 6–9: Agent mode (Phase 1) ─────────────────────────────────────────

_AAA_QUERY = "What is the threshold for elective AAA repair in fit patients?"

REQUIRED_AGENT_FIELDS = [
    "query_normalized", "question_type", "guidelines_used",
    "retrieval_mode", "answer", "recommendations", "supporting_narrative",
    "figures_or_tables", "confidence", "needs_clinical_judgment",
]


class TestAgentMode:
    def test_agent_mode_returns_all_required_fields(self):
        """Test 6: agent mode returns a JSON object with all required top-level fields."""
        result = tool(_AAA_QUERY, response_mode="agent")
        try:
            data = json.loads(result)
        except json.JSONDecodeError:
            raise AssertionError(f"Agent mode output is not valid JSON: {result[:300]}")
        for f in REQUIRED_AGENT_FIELDS:
            assert f in data, f"Missing field: {f}"

    def test_agent_mode_knowledge_question_fields(self):
        """Test 7: knowledge question → question_type=knowledge, needs_clinical_judgment=False."""
        data = json.loads(tool(_AAA_QUERY, response_mode="agent"))
        assert data["question_type"] == "knowledge"
        assert data["needs_clinical_judgment"] is False
        assert data["confidence"] in ("high", "medium", "low")
        assert isinstance(data["guidelines_used"], list)

    def test_agent_mode_recommendations_have_required_subfields(self):
        """Test 8: each recommendation has 'statement' and 'guideline' subfields."""
        data = json.loads(tool(_AAA_QUERY, response_mode="agent"))
        for rec in data["recommendations"]:
            assert "statement" in rec, f"Recommendation missing 'statement': {rec}"
            assert "guideline" in rec, f"Recommendation missing 'guideline': {rec}"

    def test_narrative_mode_still_works(self):
        """Test 9: narrative mode (default) returns Markdown, not JSON — OpenWebUI compat."""
        result = tool(_AAA_QUERY, response_mode="narrative")
        assert isinstance(result, str)
        assert not result.strip().startswith("{"), \
            "narrative mode must return Markdown, not JSON"
        assert len(result) > 50, f"Narrative response too short: {result!r}"

    def test_default_mode_is_narrative(self):
        """Omitting response_mode must default to narrative (not JSON)."""
        result = asyncio.run(server.vascular_consult_guidelines(query=_AAA_QUERY))
        assert not result.strip().startswith("{"), \
            "Default mode must be narrative, not JSON"

    def test_agent_mode_empty_evidence_returns_valid_json(self):
        """Agent mode with a very niche query — must return valid JSON even with no evidence."""
        result = tool(
            "What is the ESVS recommendation for aortic management in Marfan syndrome?",
            response_mode="agent",
        )
        try:
            data = json.loads(result)
        except json.JSONDecodeError:
            raise AssertionError(f"Agent mode must always return valid JSON: {result[:300]}")
        for f in REQUIRED_AGENT_FIELDS:
            assert f in data, f"Missing field even with no evidence: {f}"
        assert data["answer"] is not None


# ─── Test 10: Gate returns suggested_guidelines ───────────────────────────────

class TestGateSuggestedGuidelines:
    def test_gate_carotid_returns_suggested_guidelines(self):
        """Test 10: context gate for carotid case includes suggested_guidelines."""
        data = gate("Patient with carotid stenosis")
        assert "suggested_guidelines" in data, \
            f"'suggested_guidelines' missing from gate response: {data}"
        assert "carotid_vertebral" in data["suggested_guidelines"], \
            f"Expected carotid_vertebral in suggested_guidelines, got: {data['suggested_guidelines']}"

    def test_gate_aaa_returns_suggested_guidelines(self):
        """Gate for AAA case suggests abdominal_aortic_aneurysm."""
        data = gate("Patient with abdominal aortic aneurysm")
        assert "abdominal_aortic_aneurysm" in data.get("suggested_guidelines", []), \
            f"Expected abdominal_aortic_aneurysm, got: {data.get('suggested_guidelines')}"

    def test_gate_proceed_has_suggested_guidelines_field(self):
        """PROCEED responses also carry suggested_guidelines (empty list)."""
        data = gate("What is the diameter threshold for AAA repair?")
        assert "suggested_guidelines" in data, \
            f"PROCEED response missing suggested_guidelines: {data}"
        assert isinstance(data["suggested_guidelines"], list)


# ─── Test 11: list_guidelines returns description field ───────────────────────

class TestListGuidelinesStructured:
    def test_list_guidelines_returns_description_field(self):
        """Test 11: every guideline object has an 'id', 'label', 'has_assets', 'description'."""
        data = list_guidelines()
        assert "guidelines" in data, f"Top-level 'guidelines' key missing: {data}"
        for g in data["guidelines"]:
            assert "id" in g, f"Missing 'id': {g}"
            assert "label" in g, f"Missing 'label': {g}"
            assert "has_assets" in g, f"Missing 'has_assets': {g}"
            assert "description" in g, f"Missing 'description' on {g.get('id')}"
            assert isinstance(g["description"], str) and g["description"], \
                f"Empty description on {g.get('id')}"

    def test_descending_thoracic_has_no_assets(self):
        """descending_thoracic_aorta.has_assets must be False (known gap)."""
        data = list_guidelines()
        entry = next(g for g in data["guidelines"] if g["id"] == "descending_thoracic_aorta")
        assert entry["has_assets"] is False, \
            f"descending_thoracic_aorta should have has_assets=False, got {entry['has_assets']}"
