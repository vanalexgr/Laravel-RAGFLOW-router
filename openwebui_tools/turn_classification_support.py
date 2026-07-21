"""Characterize the adapter's pre-B1 turn-routing helpers for evaluation."""

from dataclasses import dataclass
import json
from pathlib import Path
from typing import Optional

from openwebui_tools.vascular_mcp_adapter import Tools


@dataclass(frozen=True)
class ObservedTurnDecision:
    turn_class: str
    reason: str


DEFAULT_CORPUS_PATH = Path(__file__).parent / "fixtures" / "turn_corpus.jsonl"


def load_turn_corpus(path: Optional[Path] = None) -> list[dict]:
    corpus_path = path or DEFAULT_CORPUS_PATH
    with corpus_path.open(encoding="utf-8") as handle:
        return [json.loads(line) for line in handle if line.strip()]


def classify_corpus_row(row: dict, tools: Optional[Tools] = None) -> ObservedTurnDecision:
    messages = list(row.get("messages") or [])
    question = str(messages[-1].get("content") or "") if messages else ""
    state = row.get("state", "none")
    return classify_existing_turn(
        question,
        messages,
        has_session=state == "session",
        has_case_ctx=state == "case",
        tools=tools,
    )


def classify_existing_turn(
    question: str,
    messages: Optional[list] = None,
    *,
    has_session: bool = False,
    has_case_ctx: bool = False,
    tools: Optional[Tools] = None,
) -> ObservedTurnDecision:
    """Expose current routing as one decision without changing production flow."""
    adapter = tools or Tools()
    messages = messages or []
    history = adapter._extract_history(messages, question)
    pending_gate = adapter._can_reuse_pending_gate(question, messages)
    guardrail_type = None if pending_gate else adapter._guardrail_type(question, messages)

    if guardrail_type is not None:
        return ObservedTurnDecision("GUARDRAIL", guardrail_type)
    if adapter._EXPLICIT_NEW_CASE_RE.search(question or ""):
        return ObservedTurnDecision("EXPLICIT_NEW_CASE", "explicit_new_case_re")
    if adapter._is_raw_guideline_knowledge_query(question, history):
        return ObservedTurnDecision("KNOWLEDGE", "raw_guideline_knowledge_re")
    if has_session:
        if adapter._is_answer_only_turn(question):
            return ObservedTurnDecision("GATE_REPLY", "answer_only")
        if adapter._should_treat_as_new_query(question, {"pre_result": {}}):
            return ObservedTurnDecision("NEW_CASE", "fresh_case_intro_re")
        return ObservedTurnDecision("FOLLOWUP_SUBSTANTIVE", "active_session")
    if pending_gate and adapter._is_answer_only_turn(question):
        return ObservedTurnDecision("GATE_REPLY", "recovered_gate_answer")
    if has_case_ctx and adapter._is_vague_management_followup(question):
        return ObservedTurnDecision("FOLLOWUP_VAGUE", "vague_mgmt_re")
    if has_case_ctx or pending_gate:
        return ObservedTurnDecision("FOLLOWUP_SUBSTANTIVE", "same_case_context")
    return ObservedTurnDecision("NEW_CASE", "no_prior_context")
