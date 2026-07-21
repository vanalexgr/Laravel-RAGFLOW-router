"""Characterize the adapter's pre-B1 turn-routing helpers for evaluation."""

import json
from pathlib import Path
from typing import Optional

from openwebui_tools.vascular_mcp_adapter import Tools, TurnDecision


DEFAULT_CORPUS_PATH = Path(__file__).parent / "fixtures" / "turn_corpus.jsonl"


def load_turn_corpus(path: Optional[Path] = None) -> list[dict]:
    corpus_path = path or DEFAULT_CORPUS_PATH
    with corpus_path.open(encoding="utf-8") as handle:
        return [json.loads(line) for line in handle if line.strip()]


def classify_corpus_row(row: dict, tools: Optional[Tools] = None) -> TurnDecision:
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
) -> TurnDecision:
    """Compatibility wrapper around the production classifier."""
    adapter = tools or Tools()
    return adapter.classify_turn(
        question,
        messages or [],
        has_session=has_session,
        has_case_ctx=has_case_ctx,
    )
