# Conversation Memory Improvement Plan — Execution Notes

## Scope and status

- Branch: `codex/conversation-memory-improvements`
- Phase completed: **Phase 0 only (C1, C2, C3, C4)**
- Production deployment: **not performed**
- Adapter version after Phase 0: `1.5.54`
- Protected files `openwebui_tools/vascular_expert.py` and `vascular_agent_adapter.py`: unchanged

## Phase 0 shipped

| Task | Commit | Result | One-line rollback |
|---|---|---|---|
| C1 | `82e1b45` | One PHI-safe JSON turn record per adapter consultation, controlled by `LOG_TURN_DECISIONS` (default `True`), with decision and latency fields. | Set `LOG_TURN_DECISIONS=False` (or revert C1). |
| C2 | `e96b83c` | Retrieval-channel change-detection decision/timing log with safe reason labels and `llm_called`; old raw reply/LLM previews in this service were replaced by lengths and SHA-1 prefixes. | Revert C2 to remove the controller log and result metadata. |
| C3 | `74c6cb5` | Scenario-level regression characterization over the existing routing helpers. | Revert C3 to remove the characterization layer. |
| C4 | this commit | 42-case synthetic JSONL corpus, corpus-driven regression suite, and confusion-matrix/latency evaluator. | Revert C4 to remove the corpus and evaluator. |

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

Real `pre_retrieval`, `change_detection`, `retrieval`, and total latency must be sampled from the new logs in a non-production staging environment before Phase 2 latency decisions.

## Verification

```text
Laravel: 87 tests, 264 assertions — PASS
Adapter main suite: 35 passed — PASS
Classification suite: 43 passed — PASS
Evaluator: 40/42 (95.24%) baseline — completed
```

The requested `python` executable was absent, so `/usr/bin/python3` was used. PHP was also absent and Docker daemon access was denied; tests ran with a temporary PHP 8.3 static CLI under `/tmp` and the repository's existing Composer dependencies.

## Discrepancies and known limitations

- The workspace root was `/home/vga/LAVAREL`, but the repository and brief were nested under `/home/vga/LAVAREL/Laravel-RAGFLOW-router`.
- The plan's adapter orchestration line estimate had drifted: `consult_vascular_guidelines` began at line 2232 before Phase-0 edits, not around line 2330.
- The supplied improvement brief is untracked and intentionally excluded from task commits.
- Untouched `main` began with eight stale test failures across the adapter and Laravel suites. Test-only signatures/expectations were aligned with behavior already present on `main`; no production behavior was changed for those repairs.
- The Phase-0 corpus is synthetic and PHI-free. It contains 42 cases and Greek-language coverage, but it is not yet the ≥80-case, sanitized real-usage corpus planned for B2.
- C1's pre-retrieval/change-detection/retrieval buckets cover awaited calls in the main orchestration. Background retrieval that outlives the gate response is not attributed to that turn's completed log record.
- Until B1, the evaluation adapter composes the existing helper predicates outside the production orchestrator. `baseline_observed` freezes these Phase-0 outcomes so B1 can prove 100% behavioral equivalence.

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
