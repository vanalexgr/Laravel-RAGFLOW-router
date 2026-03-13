"""
Section 5.2 — Integration tests against the live Laravel API.

Requires:
  - laravel-api.service running on the 135 VM
  - LARAVEL_API_KEY and LARAVEL_BASE_URL set in the environment (source .env)

These tests call vascular_consult_guidelines() directly (bypasses the running
HTTP service) and also call Laravel directly for raw response inspection.
"""

import asyncio
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


def tool(query: str, guidelines: list[str] | None = None) -> str:
    """Synchronous wrapper for vascular_consult_guidelines."""
    return asyncio.run(
        server.vascular_consult_guidelines(query=query, guidelines=guidelines)
    )


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
