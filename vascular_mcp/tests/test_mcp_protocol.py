"""
Section 5.3 — MCP protocol compliance tests.

Tests the live vascular-mcp.service running at localhost:8080.
Requires the service to be running (sudo systemctl start vascular-mcp.service).

All requests go to /mcp via the streamable-HTTP transport (stateless mode).
Responses are in SSE format; we extract the 'data:' line.
"""

import json

import httpx
import pytest

MCP_URL    = "http://localhost:8080/"
MCP_HEADERS = {
    "Content-Type": "application/json",
    "Accept": "application/json, text/event-stream",
}
EXPECTED_TOOLS = {
    "vascular_consult_guidelines",
    "vascular_list_guidelines",
}

# ─── SSE helpers ─────────────────────────────────────────────────────────────

def _parse_sse(text: str) -> dict:
    """Extract the JSON payload from an SSE 'data:' line."""
    for line in text.splitlines():
        if line.startswith("data: "):
            return json.loads(line[6:])
    raise ValueError(f"No 'data:' line found in SSE response:\n{text[:400]}")


def _mcp(method: str, params: dict | None = None, req_id: int = 1) -> dict:
    """Send a JSON-RPC request to the MCP endpoint and return the parsed response."""
    payload: dict = {"jsonrpc": "2.0", "method": method, "id": req_id}
    if params is not None:
        payload["params"] = params
    resp = httpx.post(MCP_URL, json=payload, headers=MCP_HEADERS, timeout=30.0)
    assert resp.status_code == 200, (
        f"Expected HTTP 200, got {resp.status_code}.\nBody: {resp.text[:400]}"
    )
    return _parse_sse(resp.text)


def _mcp_tool_call(tool_name: str, arguments: dict, req_id: int = 10) -> dict:
    """Call tools/call and return the parsed response."""
    return _mcp(
        "tools/call",
        params={"name": tool_name, "arguments": arguments},
        req_id=req_id,
    )


# ─── Test 1: tools/list returns all 3 tools ──────────────────────────────────

class TestToolsList:
    def test_tools_list_returns_200(self):
        resp = httpx.post(MCP_URL, json={"jsonrpc": "2.0", "method": "tools/list", "id": 1},
                          headers=MCP_HEADERS, timeout=15.0)
        assert resp.status_code == 200

    def test_tools_list_returns_all_tools(self):
        """tools/list → exactly the 2 expected tool names."""
        data = _mcp("tools/list")
        assert "result" in data, f"No 'result' in response: {data}"
        tools = data["result"]["tools"]
        names = {t["name"] for t in tools}
        assert names == EXPECTED_TOOLS, \
            f"Expected tools {EXPECTED_TOOLS}, got {names}"

    def test_tools_list_tool_count(self):
        """Exactly 2 tools — vascular_assess_context_gaps is internal only."""
        data = _mcp("tools/list")
        tools = data["result"]["tools"]
        assert len(tools) == 2, f"Expected 2 tools, got {len(tools)}: {[t['name'] for t in tools]}"


# ─── Test 2: Tool schemas are valid ──────────────────────────────────────────

class TestToolSchemas:
    @pytest.fixture(scope="class")
    def tools_by_name(self) -> dict:
        data = _mcp("tools/list")
        return {t["name"]: t for t in data["result"]["tools"]}

    def test_consult_guidelines_has_required_query(self, tools_by_name):
        tool = tools_by_name["vascular_consult_guidelines"]
        schema = tool["inputSchema"]
        assert "query" in schema.get("required", []), \
            f"'query' should be required. Schema: {schema}"

    def test_consult_guidelines_optional_guidelines_and_history(self, tools_by_name):
        tool = tools_by_name["vascular_consult_guidelines"]
        props = tool["inputSchema"].get("properties", {})
        assert "guidelines" in props, "'guidelines' property missing"
        assert "history" in props, "'history' property missing"
        # guidelines and history should NOT be required
        required = tool["inputSchema"].get("required", [])
        assert "guidelines" not in required, "'guidelines' should be optional"
        assert "history" not in required, "'history' should be optional"

    def test_list_guidelines_has_no_required_fields(self, tools_by_name):
        tool = tools_by_name["vascular_list_guidelines"]
        required = tool["inputSchema"].get("required", [])
        assert len(required) == 0, f"Expected no required fields, got {required}"

    def test_all_tools_have_description(self, tools_by_name):
        for name, tool in tools_by_name.items():
            assert tool.get("description"), f"Tool '{name}' missing description"


# ─── Test 3: readOnlyHint annotations ────────────────────────────────────────

class TestAnnotations:
    @pytest.fixture(scope="class")
    def tools_by_name(self) -> dict:
        data = _mcp("tools/list")
        return {t["name"]: t for t in data["result"]["tools"]}

    def test_consult_guidelines_read_only(self, tools_by_name):
        ann = tools_by_name["vascular_consult_guidelines"].get("annotations", {})
        assert ann.get("readOnlyHint") is True, \
            f"vascular_consult_guidelines missing readOnlyHint=True. Got: {ann}"

    def test_list_guidelines_read_only(self, tools_by_name):
        ann = tools_by_name["vascular_list_guidelines"].get("annotations", {})
        assert ann.get("readOnlyHint") is True, \
            f"vascular_list_guidelines missing readOnlyHint=True. Got: {ann}"


# ─── Test 4: Invalid/empty input returns error, not HTTP 500 ─────────────────

class TestInputValidation:
    def test_empty_query_returns_error_not_500(self):
        """tools/call with query='' → HTTP 200 with error string, not HTTP 500."""
        data = _mcp_tool_call("vascular_consult_guidelines", {"query": ""})
        # Should not raise an assertion (status 200 already checked in _mcp_tool_call)
        # The result should contain an error message, not be empty
        result = data.get("result", {})
        # FastMCP wraps the return value: {"content": [{"type":"text","text":"..."}]}
        content = result.get("content", [])
        if content:
            text = content[0].get("text", "")
        else:
            # Some versions return result directly
            text = str(result)
        assert "error" in text.lower() or "empty" in text.lower(), \
            f"Expected error text for empty query, got: {text[:300]}"

    def test_list_guidelines_returns_all_datasets(self):
        """vascular_list_guidelines → JSON with 14 guideline objects, each with required fields."""
        data = _mcp_tool_call("vascular_list_guidelines", {})
        result = data.get("result", {})
        content = result.get("content", [])
        text = content[0].get("text", "") if content else str(result)
        parsed = json.loads(text)
        assert "guidelines" in parsed, f"Top-level 'guidelines' key missing: {parsed}"
        ids = {g["id"] for g in parsed["guidelines"]}
        expected_keys = [
            "aortic_arch", "descending_thoracic_aorta", "abdominal_aortic_aneurysm",
            "mesenteric_renal", "asymptomatic_pad", "clti", "acute_limb_ischaemia",
            "carotid_vertebral", "venous_thrombosis", "chronic_venous_disease",
            "antithrombotic_therapy", "vascular_trauma", "vascular_graft_infections",
            "vascular_access",
        ]
        for key in expected_keys:
            assert key in ids, f"Expected guideline key '{key}' missing from list_guidelines output"
        for g in parsed["guidelines"]:
            assert "description" in g, f"Missing 'description' on {g.get('id')}"


# ─── Tests 5–6: Agent mode protocol validation ────────────────────────────────

REQUIRED_AGENT_FIELDS = [
    "query_normalized", "question_type", "guidelines_used",
    "retrieval_mode", "answer", "recommendations", "supporting_narrative",
    "figures_or_tables", "confidence", "needs_clinical_judgment",
]

_AAA_QUERY = "threshold for elective AAA repair in fit patients"


class TestAgentModeProtocol:
    def test_agent_mode_output_is_valid_json(self):
        """Test 5: agent mode output (via MCP protocol) is valid JSON with all required fields."""
        data = _mcp_tool_call(
            "vascular_consult_guidelines",
            {"query": _AAA_QUERY, "response_mode": "agent"},
            req_id=20,
        )
        result = data.get("result", {})
        content = result.get("content", [])
        raw = content[0].get("text", "") if content else str(result)
        try:
            parsed = json.loads(raw)
        except json.JSONDecodeError:
            raise AssertionError(f"Agent mode output is not valid JSON: {raw[:300]}")
        for f in REQUIRED_AGENT_FIELDS:
            assert f in parsed, f"Missing required field: {f}"

    def test_default_mode_is_narrative_not_json(self):
        """Test 6: omitting response_mode → narrative Markdown, not a JSON object."""
        data = _mcp_tool_call(
            "vascular_consult_guidelines",
            {"query": _AAA_QUERY},
            req_id=21,
        )
        result = data.get("result", {})
        content = result.get("content", [])
        raw = content[0].get("text", "") if content else str(result)
        assert not raw.strip().startswith("{"), \
            f"Default mode must be narrative (Markdown), not JSON. Got: {raw[:200]}"
