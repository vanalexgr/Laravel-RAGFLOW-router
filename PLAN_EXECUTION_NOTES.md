# Conversation Memory Improvement Plan — Execution Notes

## Scope and status

- Branch: `codex/conversation-memory-improvements`
- Phases completed: **Phase 0 (C1–C4), Phase 1 (A1, B1), and Phase 2 (B2, B3, A3, A2)**
- Current stop point: **Phase 2 complete; stopped for maintainer review before Phase 3**
- Production deployment: **not performed**
- Adapter version after A2: `1.5.58`
- Protected files `openwebui_tools/vascular_expert.py` and `vascular_agent_adapter.py`: unchanged

## Phase 0 shipped

| Task | Commit | Result | One-line rollback |
|---|---|---|---|
| C1 | `82e1b45` | One PHI-safe JSON turn record per adapter consultation, controlled by `LOG_TURN_DECISIONS` (default `True`), with decision and latency fields. | Set `LOG_TURN_DECISIONS=False` (or revert C1). |
| C2 | `e96b83c` | Retrieval-channel change-detection decision/timing log with safe reason labels and `llm_called`; old raw reply/LLM previews in this service were replaced by lengths and SHA-1 prefixes. | Revert C2 to remove the controller log and result metadata. |
| C3 | `74c6cb5` | Scenario-level regression characterization over the existing routing helpers. | Revert C3 to remove the characterization layer. |
| C4 | `8b9dc77` | 42-case synthetic JSONL corpus, corpus-driven regression suite, and confusion-matrix/latency evaluator. | Revert C4 to remove the corpus and evaluator. |

## Phase 1 shipped

| Task | Commit | Result | One-line rollback |
|---|---|---|---|
| A1 | `a633dbf` | Replaced both unbounded dictionaries with `TTLStore` instances using their existing 300s/900s TTLs, a 2,000-entry cap, expired-on-write cleanup, and oldest-write eviction. | Revert A1 to restore plain dictionaries. |
| B1 | this commit | Added typed `TurnClass`/`TurnDecision`, one documented `classify_turn`, and routed both orchestration decisions and C1 logging through the same result. | Revert B1 to restore the separate routing predicates and shadow log classifier. |

A1 tests prove expired entries are reclaimed on a subsequent write, capacity never exceeds the configured cap, the oldest entry is evicted, and the existing `get`/`set`/`pop`/overwrite/clear behavior remains available. Existing adapter flow tests stayed green.

B1 characterization tests were added and run red before implementation: all 42 failed solely because `classify_turn` did not yet exist. After implementation, all 42 reproduce `baseline_observed`. An existing rewritten-pending-gate flow test additionally preserved the real orchestration priority that the former shadow logger had mislabeled.

## Phase 2 progress

### B2 — expanded corpus baseline

- Corpus expanded from 42 to **84** labeled turns.
- New cases are de-identified clinical shapes only. No names, MRNs, dates, contact details, network metadata, or copied session prose were committed.
- Added Greek new-case, gate-reply, vague-follow-up, and substantive-follow-up shapes.
- Added both pending-gate reorder shapes: explicit new case and standalone knowledge.
- Rollback: revert the B2 commit to restore the 42-case corpus and Phase-1 notes.

B2 confusion matrix (rows expected, columns observed):

| Expected \\ Observed | NEW_CASE | EXPLICIT_NEW_CASE | GATE_REPLY | FOLLOWUP_VAGUE | FOLLOWUP_SUBSTANTIVE | KNOWLEDGE | GUARDRAIL |
|---|---:|---:|---:|---:|---:|---:|---:|
| NEW_CASE | 12 | 0 | 0 | 0 | 0 | 0 | 0 |
| EXPLICIT_NEW_CASE | 0 | 10 | 0 | 0 | 1 | 0 | 0 |
| GATE_REPLY | 0 | 0 | 11 | 0 | 1 | 0 | 0 |
| FOLLOWUP_VAGUE | 0 | 0 | 0 | 6 | 7 | 0 | 1 |
| FOLLOWUP_SUBSTANTIVE | 0 | 0 | 0 | 0 | 14 | 0 | 0 |
| KNOWLEDGE | 2 | 0 | 0 | 0 | 0 | 9 | 0 |
| GUARDRAIL | 3 | 0 | 0 | 0 | 0 | 1 | 6 |

| Class | B2 accuracy |
|---|---:|
| NEW_CASE | 100.00% (12/12) |
| EXPLICIT_NEW_CASE | 90.91% (10/11) |
| GATE_REPLY | 91.67% (11/12) |
| FOLLOWUP_VAGUE | 42.86% (6/14) |
| FOLLOWUP_SUBSTANTIVE | 100.00% (14/14) |
| KNOWLEDGE | 81.82% (9/11) |
| GUARDRAIL | 60.00% (6/10) |
| **Overall** | **80.95% (68/84)** |

Representative B2 classifier timing: median 38.34 µs, p95 70.96 µs, max 97.50 µs (local, no I/O; Workstream D remains deferred).

### B3 — corpus-driven misroute fixes

- Added `vague_operation_preferred` as a failing characterization before changing production rules. The red run exposed 17 expected-label failures across the 85-case corpus.
- Extended vague-management recognition for the surfaced English and Greek cues, including a contextual vague cue that formerly fell into the general guardrail.
- Added Greek gate-answer and explicit-new-case recognition.
- Classified the current question independently of transcript patient context for standalone guideline-knowledge detection, preserving the pending-gate reorder rule.
- Extended the existing guideline-knowledge and guardrail patterns only for corpus-demonstrated misses.
- All expected classes are now green, with no per-class regression from B2 and overall accuracy increased by 19.05 percentage points.
- Rollback: revert the B3 commit to restore the B2 classifier and adapter version `1.5.56`.

B3 confusion matrix (rows expected, columns observed):

| Expected \ Observed | NEW_CASE | EXPLICIT_NEW_CASE | GATE_REPLY | FOLLOWUP_VAGUE | FOLLOWUP_SUBSTANTIVE | KNOWLEDGE | GUARDRAIL |
|---|---:|---:|---:|---:|---:|---:|---:|
| NEW_CASE | 12 | 0 | 0 | 0 | 0 | 0 | 0 |
| EXPLICIT_NEW_CASE | 0 | 11 | 0 | 0 | 0 | 0 | 0 |
| GATE_REPLY | 0 | 0 | 12 | 0 | 0 | 0 | 0 |
| FOLLOWUP_VAGUE | 0 | 0 | 0 | 15 | 0 | 0 | 0 |
| FOLLOWUP_SUBSTANTIVE | 0 | 0 | 0 | 0 | 14 | 0 | 0 |
| KNOWLEDGE | 0 | 0 | 0 | 0 | 0 | 11 | 0 |
| GUARDRAIL | 0 | 0 | 0 | 0 | 0 | 0 | 10 |

| Class | B2 baseline | After B3 |
|---|---:|---:|
| NEW_CASE | 100.00% (12/12) | 100.00% (12/12) |
| EXPLICIT_NEW_CASE | 90.91% (10/11) | 100.00% (11/11) |
| GATE_REPLY | 91.67% (11/12) | 100.00% (12/12) |
| FOLLOWUP_VAGUE | 42.86% (6/14) | 100.00% (15/15) |
| FOLLOWUP_SUBSTANTIVE | 100.00% (14/14) | 100.00% (14/14) |
| KNOWLEDGE | 81.82% (9/11) | 100.00% (11/11) |
| GUARDRAIL | 60.00% (6/10) | 100.00% (10/10) |
| **Overall** | **80.95% (68/84)** | **100.00% (85/85)** |

B3 representative classifier timing: median 34.07 µs, p95 67.23 µs, max 84.34 µs (local, no I/O). The median delta versus B2 is -4.27 µs and remains microbenchmark noise; Workstream D is deferred by maintainer decision.

### A3 — transcript-recovery fallback

- Both end-to-end recovery scenarios now forcibly clear `_session_store` and `_case_context_store` before exercising the follow-up.
- The answer-only scenario enters through the public capabilities fallback with no guideline arguments. It reconstructs the guideline set from the checkpoint transcript, regenerates `pre_result`, runs confirmation, and builds the final response with the recovered guidelines.
- The rewritten substantive scenario proves the same empty-store fallback preserves the three-guideline planner result and retrieval query through confirmation and response construction.
- No production gap was found, so A3 changes tests and execution notes only; adapter version remains `1.5.57`.
- Known limitation: transcript recovery depends on the checkpoint marker and a recoverable preceding user turn remaining present in `messages`. If the client truncates both, the adapter safely falls back to a new consultation.
- Rollback: revert the A3 commit to remove the stronger empty-store recovery assertions; production behavior is unchanged.

### A2 — Laravel-owned durable case state

- Added `CaseStateService`, explicitly backed by `Cache::store('redis')`, using `casestate:{chatId}` keys and a 900-second TTL.
- Only `provisional_diagnosis`, up to six guideline keys, `retrieval_query`, and server-generated `ts` are persisted. Unknown request fields are discarded, and both free-text planner fields pass through `PHIScrubberService` before storage.
- Added authenticated, rate-limited GET/PUT/DELETE `/api/v1/case-state/{chatId}` endpoints. GET returns 204 when absent; DELETE is idempotent.
- Added `STATE_BACKEND` (`memory` or `laravel`, default `memory`) to adapter version `1.5.58`. Memory mode performs no state HTTP. Laravel mode uses the local 900-second `TTLStore` as a write-through cache, retrieves durable state on a local miss, and clears durable state when a new case supersedes it.
- State HTTP errors are swallowed without logging request/state text. A full consultation test proves an unavailable state backend does not surface an error to the turn; transcript recovery and fresh retrieval remain available.
- Added the 15-minute scrubbed-planner-state posture to `docs/HIPAA_COMPLIANCE.md`.
- Rollback: set `STATE_BACKEND=memory`; the new endpoints remain unused. Revert A2 to remove the endpoints and adapter integration.

A2 verification:

| Suite | Before A2 | After A2 |
|---|---:|---:|
| Adapter | 38 passed | 41 passed |
| Classification | 171 passed | 171 passed |
| Laravel | 87 tests / 264 assertions | 92 tests / 290 assertions |
| Corpus accuracy | 85/85 (100.00%) | 85/85 (100.00%) |

The A2 representative classifier timing was median 32.18 µs, p95 65.27 µs, max 129.87 µs, versus B3 median 34.07 µs / p95 67.23 µs / max 84.34 µs. Classification has no state I/O, so the small distribution change is local microbenchmark noise. Laravel state-request latency was not benchmarked because Workstream D is explicitly deferred and no staging or production calls were made.

## Classification baseline

Command:

```bash
/usr/bin/python3 openwebui_tools/eval_turn_classification.py
```

Confusion matrix (rows are expected labels; columns are observed labels):

| Expected \\ Observed | NEW_CASE | EXPLICIT_NEW_CASE | GATE_REPLY | FOLLOWUP_VAGUE | FOLLOWUP_SUBSTANTIVE | KNOWLEDGE | GUARDRAIL |
|---|---:|---:|---:|---:|---:|---:|---:|
| NEW_CASE | 6 | 0 | 0 | 0 | 0 | 0 | 0 |
| EXPLICIT_NEW_CASE | 0 | 6 | 0 | 0 | 0 | 0 | 0 |
| GATE_REPLY | 0 | 0 | 6 | 0 | 0 | 0 | 0 |
| FOLLOWUP_VAGUE | 0 | 0 | 0 | 4 | 1 | 0 | 1 |
| FOLLOWUP_SUBSTANTIVE | 0 | 0 | 0 | 0 | 6 | 0 | 0 |
| KNOWLEDGE | 0 | 0 | 0 | 0 | 0 | 6 | 0 |
| GUARDRAIL | 0 | 0 | 0 | 0 | 0 | 0 | 6 |

Phase 0 adds measurement and logging but intentionally makes no routing fix, so before and after are identical:

| Class | Before Phase 0 | After Phase 0 |
|---|---:|---:|
| NEW_CASE | 100.00% (6/6) | 100.00% (6/6) |
| EXPLICIT_NEW_CASE | 100.00% (6/6) | 100.00% (6/6) |
| GATE_REPLY | 100.00% (6/6) | 100.00% (6/6) |
| FOLLOWUP_VAGUE | 66.67% (4/6) | 66.67% (4/6) |
| FOLLOWUP_SUBSTANTIVE | 100.00% (6/6) | 100.00% (6/6) |
| KNOWLEDGE | 100.00% (6/6) | 100.00% (6/6) |
| GUARDRAIL | 100.00% (6/6) | 100.00% (6/6) |
| **Overall** | **95.24% (40/42)** | **95.24% (40/42)** |

B1 is behavior-preserving, so the Phase-1 before/after matrix is also identical to the matrix above: every per-class value is unchanged and overall accuracy remains **95.24% (40/42)**. The two known `FOLLOWUP_VAGUE` errors remain intentionally unfixed. The logged `turn_class` and `reason` now come from the exact `TurnDecision` used by orchestration.

The two errors are both expected `FOLLOWUP_VAGUE` turns: one routes to `GUARDRAIL`, and one routes to `FOLLOWUP_SUBSTANTIVE`. They are preserved for Phase 2 rather than corrected during foundation work.

## Latency baseline

No production logs or remote backends were accessed. These are local, no-network baselines on the current workspace and are not estimates of real LLM/RAGFlow latency.

| Measurement | Before Phase 0 | Phase-0 baseline | Notes |
|---|---:|---:|---|
| Classification | unavailable (no harness) | median 33.88 µs; p95 54.47 µs; max 106.19 µs | 4,200 corpus classifications, warm process, no I/O; representative final run |
| Adapter guardrail turn, wall clock | unavailable (no turn log) | median 56.35 µs; p95 63.26 µs; max 1,310.59 µs | 1,000 local calls, stdout captured, no backend |
| Adapter guardrail `latency_ms.total` | unavailable | median/p95/max 0 ms | Millisecond rounding cannot resolve these sub-ms local calls |
| Change detection fast path | unavailable (not logged consistently) | median 0.96 µs; p95 0.98 µs; max 155.83 µs | 1,000 direct service calls |
| Change detection with immediate fake LLM | unavailable | median 6.66 µs; p95 6.80 µs; max 92.42 µs | 1,000 calls; excludes network and controller logging |

Phase-1 classifier timing from the final local run was median **34.14 µs**, p95 **53.69 µs**, max **89.32 µs**, versus the Phase-0 representative median 33.88 µs / p95 54.47 µs. The small local delta is within microbenchmark noise; no backend or staging latency was measured.

Real `pre_retrieval`, `change_detection`, `retrieval`, and total latency must be sampled from the new logs in a non-production staging environment before Phase 2 latency decisions.

## Verification

```text
Adapter main suite after A2: 41 passed — PASS
Classification suite after A2: 171 passed — PASS
Laravel suite after A2: 92 tests, 290 assertions — PASS
Evaluator after A2: 85/85 (100.00%) — completed
```

The requested `python` executable was absent, so `/usr/bin/python3` was used. PHP was also absent and Docker daemon access was denied; tests ran with a temporary PHP 8.3 static CLI under `/tmp` and the repository's existing Composer dependencies.

## Discrepancies and known limitations

- The workspace root was `/home/vga/LAVAREL`, but the repository and brief were nested under `/home/vga/LAVAREL/Laravel-RAGFLOW-router`.
- The plan's adapter orchestration line estimate had drifted: `consult_vascular_guidelines` began at line 2232 before Phase-0 edits, not around line 2330.
- After A1/B1, `TTLStore`, `classify_turn`, and `consult_vascular_guidelines` are at approximately lines 61, 533, and 2387 respectively; the plan's original anchors no longer apply.
- After Phase 2, `STATE_BACKEND`, the async case-context methods, and `consult_vascular_guidelines` are at approximately lines 294, 1029–1069, and 2474. The revised A2 brief named files and behavior rather than stale line anchors.
- Workstream D (D1–D4) was deferred by maintainer decision and was not implemented.
- The supplied improvement brief is untracked and intentionally excluded from task commits.
- Untouched `main` began with eight stale test failures across the adapter and Laravel suites. Test-only signatures/expectations were aligned with behavior already present on `main`; no production behavior was changed for those repairs.
- The Phase-0 corpus is synthetic and PHI-free. It contains 42 cases and Greek-language coverage, but it is not yet the ≥80-case, sanitized real-usage corpus planned for B2.
- C1's pre-retrieval/change-detection/retrieval buckets cover awaited calls in the main orchestration. Background retrieval that outlives the gate response is not attributed to that turn's completed log record.
- `baseline_observed` remains in the corpus as the immutable Phase-0/B1 characterization target. The evaluation compatibility wrapper now delegates directly to production `classify_turn`; it contains no independent classification rules.

## Manual deploy reference (maintainer only — not run)

```bash
# Adapter → OpenWebUI SQLite (after editing vascular_mcp_adapter.py + bumping version:)
scp -i ~/.ssh/id_ed25519 openwebui_tools/vascular_mcp_adapter.py root@178.105.193.206:/tmp/vascular_expert_new.py
scp -i ~/.ssh/id_ed25519 openwebui_tools/push_adapter.py       root@178.105.193.206:/tmp/push_adapter.py
ssh -i ~/.ssh/id_ed25519 root@178.105.193.206 "docker cp /tmp/vascular_expert_new.py open-webui:/tmp/vascular_expert_new.py && docker cp /tmp/push_adapter.py open-webui:/tmp/push_adapter.py && docker exec open-webui python3 /tmp/push_adapter.py && docker restart open-webui"

# Laravel → Hetzner (rsync per MEMORY.md; EXCLUDE ragflow_service/.venv), then:
#   composer install --no-interaction --optimize-autoloader --no-dev
#   php artisan config:cache && systemctl restart php8.5-fpm.service
```
