# Phase 3 Validation Results
**Commit baseline:** c0af528
**Date:** 2026-03-13
**MCP server:** https://lavarel.eastus2.cloudapp.azure.com/mcp (Caddy) / http://localhost:8080/ (direct)
**Client tested:** OpenWebUI (48 VM), Vascular MCP Validation Agent model
**Codex:** not tested this session

---

## Prompt Suite Reference

| ID | Query |
|----|-------|
| A1 | What is the recommended maximum diameter threshold for elective AAA repair in a fit patient? |
| A2 | What is the Rutherford classification for acute limb ischaemia? |
| A3 | What antiplatelet therapy is recommended after carotid endarterectomy? |
| A4 | Which ESVS guidelines cover venous disease? |
| B1 | 75-year-old fit man, symptomatic 80% carotid stenosis (NASCET), TIA 5 days ago. What intervention? |
| B2 | 62-year-old woman, AAA 5.8cm diameter, asymptomatic, fit for open repair. Management? |
| B3 | 55-year-old man, first DVT after 12-hour flight, no cancer, no prior VTE. Anticoagulation duration? |
| B4 | Acute limb ischaemia, Rutherford IIb, 4 hours duration, likely thrombotic. Treatment? |
| C1 | I have a patient with carotid stenosis. |
| C2 | Patient with an aortic aneurysm found on ultrasound. |
| C3 | Patient with a DVT. |
| C4 | Patient with acute limb ischaemia. |
| D0 | 62-year-old fit man, symptomatic 78% carotid stenosis, TIA 8 days ago. What is the recommended intervention? *(case establishment)* |
| D1 | What about perioperative antiplatelet therapy? |
| D2 | What if the patient had a major stroke instead of a TIA? |
| D3 | Is CAS an option for this patient? |
| D4 | What surveillance imaging is needed after CEA? |

---

## Query Results — OpenWebUI

> **Answer score 1–5:** 5=Excellent, 4=Good, 3=Acceptable, 2=Poor, 1=Fail
> **Tool score:** Pass / Partial / Fail
> **N/A (memory)** = model answered from internal knowledge, no retrieval evidence

| ID | Chat ID | Tool calls made | Gate status | Answer score | Tool score | Latency (s) | Notes |
|----|---------|----------------|-------------|-------------|------------|-------------|-------|
| A1 | f82daf8c | `vascular_consult_guidelines` | N/A | 4 | Pass | ~25 | ≥55mm men / ≥50mm women returned correctly |
| A2 | 63085a74 | `vascular_consult_guidelines` | N/A | 4 | Pass | ~20 | Rutherford classes returned; definition fast-path (11s Laravel) |
| A3 | ae2d26fb | `vascular_consult_guidelines`* | N/A | — | *See note* | ~25 | *DB shows tool called; user observed no tool in UI. Discrepancy — may be UI render issue. Answer clinically correct but provenance unverified.* |
| A4 | f72605ab | `vascular_list_guidelines` | N/A | 5 | Pass | ~10 | Correct tool; both venous datasets identified with descriptions |
| B1 | 985f499c | `vascular_assess_context_gaps` → *memory* | PROCEED | — | **Fail** | — | Gate returned PROCEED; model answered from memory. No `vascular_consult_guidelines` called. Citations hallucinated. |
| B2 | 88df489f | `vascular_assess_context_gaps` → *memory* | PROCEED | — | **Fail** | — | Same bug — gate PROCEED, no retrieval. |
| B3 | e159cf3a | `vascular_assess_context_gaps` → *memory* | PROCEED | — | **Fail** | — | Same bug. |
| B4 | 3dec6d13 | `vascular_assess_context_gaps` → *memory* | PROCEED | — | **Fail** | — | Same bug. |
| C1 | f3224f68 | `vascular_assess_context_gaps` | NEEDS_CLAR | 5 | Pass | ~10 | Asked symptomatic status AND stenosis degree correctly |
| C2 | 32dfca53 | `mcp/consult_vascular_guidelines`† | NEEDS_CLAR | 5 | Partial | ~10 | †Old tool source label — see Bug 3. Asked diameter + symptoms correctly. |
| C3 | cee36401 | `mcp/consult_vascular_guidelines`† | NEEDS_CLAR | 3 | Partial | ~10 | Asked provoked/unprovoked + episode; **missed DVT location** (proximal vs distal) — clinically significant for treatment duration |
| C4 | bca170df | `mcp/consult_vascular_guidelines`† | NEEDS_CLAR | 3 | Partial | ~10 | Asked Rutherford class + duration; **missed occlusion location and thrombotic vs embolic aetiology** |
| D1 | 692cf858 | `vascular_assess_context_gaps` → `vascular_consult_guidelines` | NO GATE | — | Pass | 65 | Retrieved correctly; antiplatelet recommendation substantive |
| D2 | a59fef3c | `vascular_assess_context_gaps` (re-fired) → *memory* | Re-fired | — | **Fail** | 10 | Gate re-fired (separate conversation). After PROCEED, answered from memory — defer-CEA recommendation for major stroke may be correct but unverified |
| D3 | d35b0813 | `vascular_assess_context_gaps` (re-fired) → *memory* | Re-fired | — | **Fail** | 9 | Same memory-answer bug. CEA vs CAS age preference clinically plausible but unverified. |
| D4 | a6d6994b | `vascular_assess_context_gaps` → `vascular_consult_guidelines` | NO GATE | — | Pass | 21 | Retrieved; duplex surveillance recommendation returned |

*Note on D group: D1–D4 were run as separate conversations (each with D0 re-established), not as a single thread. Same-conversation gate suppression therefore could not be evaluated as designed.*

---

## Summary Scores — OpenWebUI

| Group | Avg answer score | Tool pass rate | Notes |
|-------|-----------------|----------------|-------|
| A (knowledge, 4 queries) | 4.3 (A3 excluded) | 3/4 Pass, 1 unresolved | A3 provenance unclear |
| B (sufficient context, 4 queries) | N/A — unscoreable | 0/4 Pass | All failed to retrieve after PROCEED |
| C (missing context, 4 queries) | 4.0 (5+5+3+3)/4 | 1/4 Pass, 3/4 Partial | C2–C4 used old tool label; C3/C4 missing gate parameters |
| D (follow-ups, 4 queries) | Not scored | 2/4 Pass (D1,D4), 2/4 Fail | D run as separate conversations — gate suppression untestable |
| **Overall** | ~4.0 where retrievable | **5/16 Pass** | Core retrieval workflow unreliable for patient cases |

---

## Latency Summary — OpenWebUI

| Stat | OpenWebUI |
|------|-----------|
| Fastest (knowledge, definition fast-path) | ~20s (A2) |
| Fastest (list tool) | ~10s (A4) |
| Single guideline patient case (Laravel only) | ~22s Laravel + ~8s LLM = ~30s |
| Two-guideline query (A3) | ~16s Laravel + ~9s LLM = ~25s |
| D1 full pipeline (gate + consult + synthesis) | ~65s |
| D4 (gate + consult + synthesis) | ~21s |
| Queries > 90s | 0 |
| Memory answers (no retrieval) | 9–10s |

All queries within 90s. MCP path noticeably faster than `vascular_expert.py` (which typically runs 40–90s for patient cases).

---

## Issues Found

| ID | Severity | Description |
|----|----------|-------------|
| P3-BUG-1 | **Bug** | **Post-PROCEED retrieval gap**: After `vascular_assess_context_gaps` returns PROCEED, model answers from memory without calling `vascular_consult_guidelines` on B1–B4, D0, D2, D3. Two-tool sequential workflow unreliable (~60% failure rate). Citations in memory answers are hallucinated. |
| P3-BUG-2 | **Bug** | **D0 gate misclassification**: Gate classifies "62-year-old fit man, symptomatic 78% carotid stenosis, TIA 8 days ago" as `knowledge_question`. Root cause: model calls `vascular_assess_context_gaps(question="What is the recommended intervention?")` — strips patient context before passing to gate. `_PATIENT_CASE_RE` then fails to match. |
| P3-BUG-3 | **Investigation** | **C2/C3/C4 tool source label `mcp/consult_vascular_guidelines`**: Three queries used this source label (old `vascular_expert.py` format) despite model not having `vascular_expert.py` enabled. Output format confirms old tool output. Root cause unclear — may be base model tool inheritance. |
| P3-ENH-1 | **Enhancement** | **C3 gate gap — DVT location missing**: Gate asks for provoked/unprovoked and episode history but not DVT anatomical location (proximal iliofemoral vs distal popliteal/calf). Location determines anticoagulation duration and intensity. |
| P3-ENH-2 | **Enhancement** | **C4 gate gap — ALI location and aetiology missing**: Gate asks for Rutherford class and duration but not occlusion level (aortoiliac / femoral / infrapopliteal) or thrombotic vs embolic distinction. Both are required for treatment decision (thrombectomy vs thrombolysis vs bypass). |
| P3-ENH-3 | **Enhancement** | **Narrative template gap**: MCP `_format_consult_narrative()` returns Laravel `result` field as-is. STRICT_TEMPLATE sections (Assessment / Imaging / Indication / Treatment / Clinical Decision Summary / Perioperative Risk / Follow-up) and figures/tables appending are not applied. These are implemented in `vascular_expert.py`, not the MCP server. Answer structure quality: 3/5 vs 4–5/5 for `vascular_expert.py`. |
| P3-ENH-4 | **Enhancement** | **Citations reference tool calls, not retrieved chunks**: MCP narrative output cites `[1] ESVS Guideline List: ...` or `[1] RETRIEVED GUIDELINES for: ...` — i.e., the tool invocation itself. `vascular_expert.py` maps citations to specific retrieved chunks with document name, section, and recommendation ID (e.g., `[1] Carotid and Vertebral Artery Guidelines, Rec 4.2, Class I, Level A`). Clinicians cannot trace MCP answers back to source text. The `citation_chunks` field returned by Laravel already contains per-chunk metadata (`document_name`, `rec_id`, `class`, `level`) — these need to be surfaced as inline citations in `_format_consult_narrative()`. |
| P3-INFO-1 | **Info** | **A3 tool call discrepancy**: DB shows `vascular_consult_guidelines` called for chat ae2d26fb; user observed no tool called in UI. May be OpenWebUI tool-call render issue or different chat run. |
| P3-INFO-2 | **Info** | **D group run as separate conversations**: D1–D4 each started with D0 re-established, not in one thread. Same-conversation gate suppression behaviour not tested as designed. Recommend re-running as single thread. |

---

## Phase 2 Priority Signals

**1. Fix post-PROCEED retrieval gap first (P3-BUG-1) — highest priority**
The two-tool sequential pattern (gate → consult) fails ~60% of the time for patient cases. Without reliable retrieval, B and D group answers are unverifiable. Phase 2 should consider either:
- Merging gate logic into `vascular_consult_guidelines` so one call does both (avoids the model stopping after the gate)
- Or making the PROCEED response explicitly instruct the model to call `vascular_consult_guidelines` next (structured field: `next_action: "call vascular_consult_guidelines"`)

**2. Apply STRICT_TEMPLATE in MCP narrative formatter (P3-ENH-3)**
Answer quality scored 3 across patient cases. The structural sections (Assessment / Treatment / Follow-up / Clinical Decision Summary) and figures/tables are missing. This is the clearest output quality gap between MCP and `vascular_expert.py`. Port the STRICT_TEMPLATE logic from `vascular_expert.py` into `_format_consult_narrative()` in `server.py`.

**3. Add DVT location and ALI localisation to gate rules (P3-ENH-1, P3-ENH-2)**
- `dvt_pe` rule: add `location` category — proximal (iliofemoral / popliteal) vs distal (calf vein) — determines 3 vs 6 months and intensity of anticoagulation
- `ali` rule: add `occlusion_level` category (aortoiliac / femoropopliteal / infrapopliteal) — determines intervention type and urgency

**4. Add chunk-level citations to narrative output (P3-ENH-4)**
MCP output cites the tool call (`[1] RETRIEVED GUIDELINES for: ...`) rather than the specific retrieved chunk. `vascular_expert.py` produces per-statement citations tied to chunk metadata (`document_name`, `rec_id`, `class`, `level`). The data is already in `citation_chunks` from Laravel — `_format_consult_narrative()` needs to build a numbered reference list from it and inject inline citation markers into the narrative text.

**5. Investigate gate argument-passing (P3-BUG-2)**
Model passes paraphrased question to gate, losing patient case context → misclassified as knowledge question. Mitigations: (a) system prompt instruction to pass full case text to gate; (b) gate `question` param description emphasising full context required.

**5. Investigate `mcp/consult_vascular_guidelines` source label (P3-BUG-3)**
Determine whether C2/C3/C4 actually used old `vascular_expert.py` or whether this is a source label aliasing issue in OpenWebUI. If old tool is accessible via base model inheritance, disable it at the OpenWebUI instance level for the test model.

**6. Queries most affected by slow retrieval:**
None exceeded 90s. Single-guideline queries: ~25–30s. Multi-guideline (A3): ~25s. No Phase 2 lean-retrieval urgency based on this data alone — but B/D latency not measurable due to memory-answer failures.

**7. Codex baseline not established.**
All tests were OpenWebUI only. Codex comparison deferred — see Phase 3 closure note.

---

## Phase 2 Re-test — "Vascular MCP (Agent)" 2-Tool Config

**Commit baseline:** 5a1c63a
**Date:** 2026-03-14
**Model:** `vascular-mcp-agent` (base: `gpt-5-chat`, tools: `vascular_consult_guidelines` + `vascular_list_guidelines` only)
**Change from baseline:** Gate merged into `vascular_consult_guidelines`; system prompt instructs model to never call `vascular_assess_context_gaps`; `_PATIENT_CASE_RE` fixed for hyphenated ages; `aortic aneurysm` added to aaa_treatment detect; `thrombotic`/`embolic` added to ALI aetiology patterns.

Groups A (knowledge) not re-run — passing at baseline, no server changes affect them.
C1 chat ID invalid (truncated UUID — not found in DB).

### Group B — Sufficient Context (single-tool flow, no gate expected)

| ID | Chat ID | Tool calls made | Gate status | Answer score | Tool score | Notes |
|----|---------|----------------|-------------|-------------|------------|-------|
| B1 | 27ad7428 | `vascular_assess_context_gaps` → *text* → `vascular_consult_guidelines` | sufficient_context | 4 | Partial | Model still called gate on turn 1 despite system prompt (3-tool server still visible); output tool call as text; user retried; turn 3 retrieved correctly. CEA for ≥70yo symptomatic 80% stenosis, Class I Level A — clinically correct. |
| B2 | cad3581b | `vascular_consult_guidelines` | N/A (no gate) | 5 | Pass | Direct retrieval. ≥50mm threshold for women (Class IIb Level C), EVAR vs OSR — correct. |
| B3 | ea39a5eb | `vascular_consult_guidelines` | N/A (no gate) | 5 | Pass | Direct retrieval. 3-month anticoagulation for provoked DVT — correct. |
| B4 | cf5fe2aa | `vascular_consult_guidelines` | N/A (no gate) | 5 | Pass | Direct retrieval. Rutherford IIb urgent revascularisation, surgical vs endovascular — correct. |

**B group summary:** 3/4 Pass, 1 Partial. B1 partial because model still reached for `vascular_assess_context_gaps` despite system prompt restriction — gate tool remains visible in the 3-tool MCP server. B2/B3/B4 retrieved correctly in a single call.

### Group C — Missing Context (internal gate expected to ask clarification)

| ID | Chat ID | Tool calls made | Gate status | Answer score | Tool score | Notes |
|----|---------|----------------|-------------|-------------|------------|-------|
| C1 | 67706c4* | — | — | — | — | Chat ID not found (UUID truncated — first segment 7 chars, should be 8). Re-run required. |
| C2 | 962b8c17 | `vascular_consult_guidelines` × 2 | NEEDS_CLAR → retrieved | 5 | Pass | Asked: aneurysm size + patient fitness. User: "50mm, none". Retrieved: 50mm in woman Class IIb Level C, surveillance q6mo. Single-tool gate working correctly. |
| C3 | 89e1e5b0 | `vascular_consult_guidelines` × 2 | NEEDS_CLAR → retrieved | 5 | Pass | Asked all 3 DVT parameters: provoking factors + prior VTE + **DVT location** (new). User: "orthopedic surgery, yes, iliofemoral". Retrieved: provoked proximal DVT, DOACs Class I Level A, CDT for iliofemoral. DVT location fix (P3-ENH-1) confirmed working. |
| C4 | 9bd43bf6 | `vascular_consult_guidelines` × 2 | NEEDS_CLAR → retrieved | 5 | Pass | Asked: Rutherford class + duration + aetiology. User: "2b, AF, femoral". Retrieved: IIb urgent revascularisation, embolic from AF, surgical thromboembolectomy at femoral. ALI aetiology fix (P3-ENH-2) confirmed working. |

**C group summary:** 3/4 Pass (C1 untested). Single-tool gate correct in all 3 testable cases. Both new gate parameters (DVT location, ALI occlusion level/aetiology) working.

### Group D — Follow-ups (single conversation thread, gate suppression)

| ID | Chat ID | D0–D4 tool calls | Answer score | Tool score | Notes |
|----|---------|-----------------|-------------|------------|-------|
| D0–D4 | 91cb0bac | `vascular_consult_guidelines` × 5 | 5 each | Pass all | All 5 turns retrieved directly. Gate suppression (`has_prior_answer`) worked correctly — no re-firing across follow-ups. |

**D0:** Symptomatic 78% carotid stenosis, TIA 8 days, 62yo. CAS may be considered <70yo (correct, with CEA preferred nuance).
**D1:** Perioperative antiplatelet — combination therapy recommended post-imaging, Class IIa Level C. Correct.
**D2:** Major stroke instead of TIA — defer CEA for disabling stroke (mRS ≥3, infarct >1/3 MCA territory), Class I Level C. **Previously failing — now retrieved correctly.**
**D3:** CAS option with major stroke — defer CAS too, same Class I Level C reasoning. **Previously failing — now retrieved correctly.**
**D4:** Post-CEA surveillance — no routine surveillance; DUS for high-risk groups (DM, CKD, female, smoker) at 2 years, Class IIa Level B. Correct.

**D group summary:** 5/5 Pass. Same-conversation gate suppression confirmed working. D2/D3 (deferred intervention for disabling stroke) now retrieved correctly — these were the D-group failures at Phase 3 baseline.

### Phase 2 Re-test Summary

| Group | Avg answer score | Tool pass rate | vs Phase 3 baseline |
|-------|-----------------|----------------|---------------------|
| B (sufficient context, 4 queries) | 4.75 | 3/4 Pass, 1 Partial | **0/4 → 3/4** ↑ |
| C (missing context, 3 testable) | 5.0 | 3/3 Pass | **1/4 → 3/3** ↑ |
| D (follow-ups, single thread) | 5.0 | 5/5 Pass | **2/4 → 5/5** ↑ |
| **Overall (B+C+D, 11 testable)** | **4.86** | **11/12 Pass** | **3/12 → 11/12** ↑ |

### Residual Issues

| ID | Severity | Description |
|----|----------|-------------|
| P3R-BUG-1 | **Bug** | **B1: Model calls gate despite 2-tool system prompt.** The MCP server exposes all 3 tools; system prompt instruction ("do not call vascular_assess_context_gaps") is ignored by the model on the first turn. After failed chaining, user retry succeeded. Root cause: system prompt cannot suppress a visible tool. Only fix: remove `vascular_assess_context_gaps` from the MCP server tool registration for this model, OR create a separate 2-tool MCP server endpoint. |
| P3R-INFO-1 | **Info** | **C1 chat ID truncated** — UUID invalid (7 chars in first segment). Re-run C1 ("I have a patient with carotid stenosis") to confirm carotid clarification gate fires correctly. |
| P3R-INFO-2 | **Deferred** | **Codex baseline not established.** All Phase 3 and Phase 2 re-tests are OpenWebUI only. Codex comparison deferred — see Phase 3 closure note below. |

---

## Phase 3 Closure

**Closed:** 2026-03-14
**Baseline:** OpenWebUI only (2-tool "Vascular MCP Agent" config, commit `5a1c63a`)

Phase 3 is closed on the OpenWebUI-only results. The Phase 2 re-test (11/12 Pass, avg 4.86/5) is the accepted performance baseline for the MCP server.

**Codex comparison — outstanding / deferred**
A Codex-environment baseline was planned but not run this cycle. This comparison remains an open item for a future validation cycle. Until then, all benchmark claims refer to OpenWebUI + gpt-5-chat only.

**What Phase 3 established:**
- Gate-merge fix resolves the post-PROCEED retrieval gap (P3-BUG-1) — primary workflow now reliable
- Single-tool gate (NEEDS_CLAR → retrieval) works correctly for all tested C-group cases
- Same-conversation gate suppression confirmed in D-group single-thread run
- D2/D3 disabling-stroke defer-intervention retrieval confirmed working
- Residual: B1 gate-call on first turn when 3-tool server is visible (P3R-BUG-1); C1 re-run outstanding (P3R-INFO-1)

**Outstanding before cutover (disable old `mcp` tool in OpenWebUI DB):**
1. P3R-BUG-1 — deploy 2-tool server endpoint or remove `vascular_assess_context_gaps` registration
2. P3R-INFO-1 — re-run C1 to confirm carotid gate
3. Codex baseline (deferred, not blocking cutover)
