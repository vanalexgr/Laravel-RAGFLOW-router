# OpenWebUI Tool — `vascular_mcp_adapter.py`

The production client that connects OpenWebUI to the Laravel API. It is the source
file `openwebui_tools/vascular_mcp_adapter.py`, but the **live copy runs from the
OpenWebUI database** (`webui.db`, `tool` table, id `vascular_mcp_adapter`) — edits
to the file take effect only after deploying to the DB (see §6).

The chat model (`gpt-5-chat-latest`, the "ESVS expert" workspace model) calls this
tool; the tool talks to Laravel; the model then synthesises the final answer from
what the tool returns.

---

## 1. What the adapter does (per turn)

```
1. Decide if this is a patient CASE or a raw KNOWLEDGE question.
2. CASE GATE — if a case is missing key clinical details, return a clarification
   request instead of retrieving (once per case, not per turn).
3. Build compact same-case STATE from prior user turns, answered clarifications,
   attachments, and previously cited recommendations.
4. QUERY REWRITE — turn a same-case follow-up ("what about surveillance?") into one
   standalone retrieval query.
5. Call POST /api/v1/vascular-consult (Laravel) with the query + history.
6. Format the returned evidence into `llm_output` using the STRICT_TEMPLATE.
7. Declare an answer MODE (COMPACT / STANDARD / FULL) to control answer length.
```

The heavy retrieval logic lives in Laravel; the adapter handles **conversation
state, the clinical gate, query shaping, and output formatting**.

---

## 2. Context gate (`_assess_context_gaps`)

Before retrieving, the adapter checks patient-case questions for missing clinical
parameters and asks for them once.

- Raw knowledge questions (definitions, thresholds, population-level) **skip** the gate.
- Patient cases **trigger** it; it opens **once per case**, not once per turn.
- Same-case follow-ups do not reopen it; a clearly different patient/case does.
- Uploaded documents contribute case context but do not bypass the gate.

Scenario rules (`_CONTEXT_GAP_RULES` — authoritative list in the file):
`aortic_thrombus`, `carotid_stenosis`, `aaa_treatment`, `dvt_pe`, `clti`, `svt`,
`type_b_dissection`, `ali`, `graft_infection`, plus a **generic catch-all** that
asks at least one case-specific question when no scenario matches.

A clarification response is a Markdown string beginning with
`**Additional information needed**`. The client presents the questions, then calls
the tool again with the completed info (all prior turns passed in `history`).

---

## 3. Same-case follow-ups

Follow-up turns are **not** sent to Laravel as raw short questions. The adapter:
1. builds a compact conversation state,
2. rewrites the latest follow-up into one standalone retrieval query,
3. sends that rewritten query to Laravel,
4. avoids passing the raw chat-history blob as retrieval evidence.

Intended for turns like *"What about surveillance?"*, *"What if the patient
mobilises after 10 days?"*, *"CEA or CAS for this patient?"*.

---

## 4. STRICT_TEMPLATE (output formatting)

Always on. When retrieval returns chunks, the adapter injects a structured template
into `llm_output`, so the model’s answer follows a consistent clinical shape:
**Assessment / Imaging / Indication / Treatment options / Follow-up / Evidence
used**, plus a **Clinical Decision Summary** and **Perioperative Risk** section when
the question warrants a management decision.

A scope filter instructs the model not to cite recommendations for a different
procedure than the case (e.g. no TEVAR recs for a mural-thrombus question).

---

## 5. Answer modes

Every answer self-declares a mode on its first line
(`**Mode:** COMPACT|STANDARD|FULL — Rule N — reason`), chosen by a 6-rule
classifier (priority order): true gap → FULL, negative indication → COMPACT, single
path → COMPACT, restricted → STANDARD, modifier → STANDARD, multi-interaction →
FULL. This keeps simple questions short and complex management questions complete.

**Synthesis rules** (accumulated from live-case review) enforce clinical
correctness — e.g. decision-first ordering, dominant-modifier-in-first-sentence,
urgency/timing, life-threatening priority, and a **clinical sequence rule**
(anticoagulation before catheter-directed thrombolysis in ALI). The authoritative,
versioned rule set is in the adapter file’s prompt constants.

---

## 6. Deploy & versioning

The live tool is in `webui.db`, cached in OpenWebUI’s memory — a restart is
required after every DB update. Deploy command in
[`OPERATIONS.md`](OPERATIONS.md#1-deploy) (`push_adapter.py` writes id
`vascular_mcp_adapter`).

- **Never** modify `openwebui_tools/vascular_expert.py` (old `mcp`-id fallback) —
  kept disabled for reference.
- All new development happens on `vascular_mcp_adapter.py` + the Laravel services.
- `ChunkSelectionService` (Laravel) is authoritative for chunk selection/scoring;
  the adapter does not re-rank evidence.
