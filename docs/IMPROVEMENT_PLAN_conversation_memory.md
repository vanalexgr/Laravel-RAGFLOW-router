# Improvement Plan — Conversation Memory & Follow-up Handling

**Audience:** Codex execution agent (starts cold — this document is self-contained).
**Scope:** The same-case follow-up + conversation-memory subsystem spanning the OpenWebUI adapter and the Laravel backend.
**Status:** Phases 0–2 complete and DEPLOYED to prod (adapter v1.5.58, `STATE_BACKEND=laravel` live). Branch `codex/conversation-memory-improvements` merged to main 2026-07-22. Author: investigation dated 2026-07-21.

### Maintainer decisions (2026-07-22) — these override the original plan text
- **A2 architecture = Laravel-owned state** (NOT the adapter-Redis default). See the revised A2 below. **DONE + deployed.**
- **Workstream D (latency) = DEFERRED** this phase. Skip D1–D4 until real staging latency logs exist. Revisit later.
- Phase 2 = **B2, B3, A3, A2(Laravel-owned)** — all done.
- **New: A5 (gate-pending `pre_result` durability)** added post-deploy — see WS-A. Prompted by a restart-transient 422 on 2026-07-22 (an `open-webui` restart mid-gate wiped the in-memory session; the follow-up confirmation had no `pre_result`).

---

## 0. Orientation — read this first

### What the subsystem does
A clinician asks a question in OpenWebUI → the LLM calls the `consult_vascular_guidelines` tool (the **adapter**) → the adapter classifies the turn, manages a two-phase *gate → confirm* handshake, and calls the stateless **Laravel** API for retrieval. Follow-up turns ("what about surveillance?", "which option?") must be rewritten into standalone retrieval queries using remembered case context.

### The two memory layers (current state)
| Layer | Location | Lifetime | Notes |
|---|---|---|---|
| `_session_store` | module-level dict in adapter | TTL **300s** | in-flight case awaiting the gate reply; holds `pre_result` + background retrieval `asyncio.Task` |
| `_case_context_store` | module-level dict in adapter | TTL **900s** | completed case; holds `provisional_diagnosis`, `guidelines`, `retrieval_query` |
| chat transcript | OpenWebUI DB (`__messages__`) | durable | the real backstop; adapter reconstructs state from it when stores are cold |
| Laravel | — | **stateless** | `history[]` passed per request; used only as a planner/change-detection signal, never stored |

### Key files
| File | Role |
|---|---|
| `openwebui_tools/vascular_mcp_adapter.py` (v1.5.53, ~2560 lines) | **the adapter** — all in-memory state + turn classification + gate/confirm flow live here |
| `openwebui_tools/test_vascular_mcp_adapter.py` | existing pytest harness for the adapter |
| `openwebui_tools/push_adapter.py` | deploy script → writes adapter into OpenWebUI SQLite (`id=vascular_mcp_adapter`) |
| `app/Http/Controllers/ToolController.php` | Laravel entry; `confirmation_mode` handling |
| `app/Services/ChangeDetectionService.php` | reuse-vs-requery LLM decision on follow-up replies |
| `app/Services/PreRetrievalService.php` / `PreRetrievalPlannerService.php` | planner; consumes `history` as a signal |
| `tests/Unit/*`, `tests/Feature/*` | PHPUnit suites (incl. `ChangeDetectionServiceTest`, `PreRetrievalServiceTest`, `ConfirmationGuidelineRefreshTest`) |

### Adapter anchor points (current line numbers — verify, code may drift)
- Module stores + TTLs: `48-51`
- Turn-classification regexes: `88-165` (`_FRESH_CASE_INTRO_RE`, `_EXPLICIT_NEW_CASE_RE`, `_FOLLOW_UP_CUE_RE`, `_VAGUE_MANAGEMENT_RE`)
- `_get_session_key` (chat_id → `user:<id>` fallback): `423`
- `_extract_history`: `431`
- `_store_case_context` / `_get_case_context` / `_clear_case_context`: `819-838`
- `_is_vague_management_followup` / `_rewrite_with_case_context`: `840-855`
- `_get_session` / `_clear_session` / `_should_treat_as_new_query`: `857-908`
- `_is_answer_only_turn`: `1738`
- Main orchestration (`consult_vascular_guidelines`): `2330-2563`

### Infra available
- `docker-redis-1` (valkey) is **already running** on the Hetzner host at `127.0.0.1:6379` (used by RAGFlow; Laravel `SESSION_DRIVER=redis`). Usable as a durable shared store.
- The adapter runs **inside the `open-webui` container**; from there the host is `host.docker.internal`, not `127.0.0.1`. A Redis-backed store must resolve the host/port via a **Valve** (config field), defaulting to disabled.

---

## 1. Ground rules for execution

1. **Never modify** `openwebui_tools/vascular_expert.py` or `vascular_agent_adapter.py` (disabled fallbacks).
2. **Bump the adapter `version:` header** (line ~4) on every adapter change; keep the running commit/version note in `MEMORY.md` accurate is the maintainer's job, not yours.
3. **Behavior-preserving refactors must be provably behavior-preserving** — land them behind the existing test suite with new characterization tests added *first*.
4. **PHI discipline:** no raw question/answer text in logs. Log lengths, hashes (`sha1(question)[:8]`), and enum labels only. This system handles patient cases.
5. **Every task ships with tests** and a one-line rollback.
6. **Do not deploy to production.** Produce code + passing tests + a short `PLAN_EXECUTION_NOTES.md`. Deployment is the maintainer's manual step (commands in §6).
7. Work on a branch: `codex/conversation-memory-improvements`. One commit per task ID, message prefixed with the task ID.

### Test commands
```bash
# Laravel
vendor/bin/phpunit                 # or: php artisan test
vendor/bin/phpunit --filter ChangeDetectionServiceTest

# Adapter (Python) — from repo root
python -m pytest openwebui_tools/test_vascular_mcp_adapter.py -q
```

---

## 2. Sequencing (phases)

**Phase 0 — Foundation (do first; enables measuring everything else):** C1, C2, C3, C4.
**Phase 1 — Quick wins (low risk, high leverage):** A1, B1.
**Phase 2 — Core improvements:** B2, B3, A3, A2(Laravel-owned). **(D1–D3 DEFERRED — see maintainer decision.)**
**Phase 3 — Optional/advanced:** A4, B4. **(D4 DEFERRED.)**

Rationale: you cannot safely improve classification accuracy (WS-B) without the logging + regression corpus from WS-C. Build the ruler before cutting. Latency (WS-D) is deferred until real staging latency logs exist — local µs microbenchmarks are not a valid baseline for it.

**Phase 2 recommended order:** B2 (grow + PHI-sanitize corpus → true baseline) → B3 (fix the `FOLLOWUP_VAGUE` misroutes + the B1 reorder edge case) → A3 (transcript-recovery tests) → A2 (Laravel-owned durable state). Do them as separate commits; A2 is the largest and riskiest, so land the accuracy work first.

---

## 3. Workstream C — Observability & Testing (Phase 0, FOUNDATION)

### C1 — Structured per-turn decision log in the adapter
**Problem:** the adapter has no structured logging — only `print("[Adapter] ...")` (line ~412) and `_emit_status`. Turn-classification decisions and latencies are invisible.
**Change:** add a single helper `_log_turn(**fields)` that emits **one JSON line** to stdout (captured by `docker logs open-webui`) at the end of every `consult_vascular_guidelines` call. Fields (PHI-safe):
```json
{"evt":"turn","ts":..., "session_key_hash":"ab12cd34","chat_scoped":true,
 "turn_class":"FOLLOWUP_VAGUE","reason":"vague_mgmt_re",
 "had_session":false,"had_case_ctx":true,
 "phase":"confirm","change_decision":"reuse",
 "guidelines":["clti","antithrombotic_therapy"],
 "question_len":24,"question_sha1":"9f2a1c7b",
 "latency_ms":{"pre_retrieval":0,"change_detection":410,"retrieval":0,"total":905}}
```
- `session_key_hash = sha1(session_key)[:8]` — never log the raw chat_id.
- Gate everything behind a Valve `LOG_TURN_DECISIONS` (default `True`).
**DoD:** every code path that returns from `consult_vascular_guidelines` emits exactly one `evt:"turn"` line; a test asserts the line is emitted and contains no raw question text.
**Rollback:** set Valve `LOG_TURN_DECISIONS=False`.

### C2 — Change-detection timing + decision in the Laravel retrieval log
**Problem:** `[PRE-RETRIEVAL TIMING]` is logged, but change-detection latency + decision are not surfaced consistently.
**Change:** in `ToolController@...confirmation` path (around `app/Http/Controllers/ToolController.php:78`), log a `[CHANGE DETECTION]` line to the `retrieval` channel: `{decision, reason, llm_called:bool, elapsed_ms}`. Reuse the existing `Log::channel('retrieval')` pattern already in `ChangeDetectionService.php:66`.
**DoD:** a Feature test (extend `ConfirmationGuidelineRefreshTest`) asserts the log line is written with `decision` and `elapsed_ms`.
**Rollback:** remove the log statement.

### C3 — Adapter classification regression suite
**Problem:** turn classification is spread across ~6 regexes/functions with no scenario-level coverage.
**Change:** add `openwebui_tools/test_turn_classification.py`. Import the classifier (post-B1: the new `classify_turn`; pre-B1: test the existing `_should_treat_as_new_query` + helpers). Drive it from the corpus in C4.
**DoD:** `pytest openwebui_tools/test_turn_classification.py` passes; ≥40 labeled cases.

### C4 — Labeled conversation corpus + accuracy harness
**Change:** create `openwebui_tools/fixtures/turn_corpus.jsonl`. Each row = a multi-turn conversation + the expected class for the final turn:
```json
{"id":"clti_followup_vague","messages":[{"role":"user","content":"72yo, CLTI with rest pain and tissue loss, which revascularization?"},{"role":"assistant","content":"<gate question>"},{"role":"user","content":"unknown"},{"role":"assistant","content":"<answer>"},{"role":"user","content":"so what should I do?"}],"expected":"FOLLOWUP_VAGUE"}
```
Cover: NEW_CASE, GATE_REPLY (yes/no/"80%"/numbered-list), FOLLOWUP_VAGUE, FOLLOWUP_SUBSTANTIVE, KNOWLEDGE (definition/threshold), EXPLICIT_NEW_CASE, GUARDRAIL, and **Greek-language** cases (`ασθενής … ετών`). Minimum 40 rows, ≥4 per class.
Add `openwebui_tools/eval_turn_classification.py` that replays the corpus and prints a **confusion matrix + per-class accuracy**. This is the before/after ruler for WS-B and WS-D.
**DoD:** `python openwebui_tools/eval_turn_classification.py` prints a matrix and an overall accuracy %; baseline number recorded in `PLAN_EXECUTION_NOTES.md`.

---

## 4. Workstream A — Robustness & Durability

### A1 — Bounded eviction for both stores (Phase 1, QUICK WIN)
**Problem:** `_session_store` and `_case_context_store` are plain dicts. Expiry is only checked *on access* to a given key — abandoned chats are **never reclaimed** → unbounded growth (slow memory leak in a long-lived container).
**Change:** wrap both in a tiny `TTLStore` class (cap `MAX_ENTRIES=2000`, evict expired + oldest on every write; O(1) amortized). Preserve the existing `.get/.pop/.set` semantics used at lines 811/821/829/833/878. Keep TTLs (300 / 900).
**DoD:** unit test proves (a) expired entries are evicted on write, (b) size never exceeds cap, (c) existing behavior unchanged. `test_vascular_mcp_adapter.py` still green.
**Risk:** low. **Rollback:** revert the wrapper.

### A2 — Laravel-owned durable case state (survives adapter restart / multi-worker) (Phase 2) — REVISED per maintainer decision
**Decision:** Laravel becomes the owner of durable case state (keyed by `chat_id`), using its existing Redis. The adapter stops being the source of truth for the *durable* part; it keeps only the transient, non-serializable `asyncio.Task` locally.

**Problem being solved:** the adapter's `_case_context_store` (and the pre_result inside `_session_store`) are per-process and volatile — an `open-webui` restart or a second uvicorn worker loses/fragments them. The `asyncio` background-retrieval task cannot be serialized, so only the *context* moves; the task stays local and, on a cold adapter, is reconstructed via transcript recovery (A3) or a fresh retrieve.

**Laravel side (new):**
- `app/Services/CaseStateService.php` — get/put/forget for a compact, **already-PHI-scrubbed** case-state record: `{provisional_diagnosis, guidelines[], retrieval_query, ts}`. Persist in **Redis explicitly** (`Cache::store('redis')` — note the app default is `CACHE_STORE=file`, so name the store; Redis is available and `SESSION_DRIVER=redis`). Namespaced key `casestate:{chat_id}`, TTL **900s** (mirror `CASE_CONTEXT_TTL`).
- Endpoints under the existing `ValidateApiKey` middleware and the same rate-limit group:
  - `GET  /api/v1/case-state/{chatId}` → the record or `204`.
  - `PUT  /api/v1/case-state/{chatId}` → upsert (validated: strings, `guidelines` array max 6, `retrieval_query` max 2000; refreshes TTL).
  - `DELETE /api/v1/case-state/{chatId}` → forget (called when a new/explicit case supersedes).
- **PHI posture (must document):** this newly *persists* clinical context server-side for ≤15 min. Persist ONLY the scrubbed, normalized planner fields — **never** raw `question` or `history`. `chatId` in keys only, never logged in full. Reuse `PHIScrubberService` if any free-text slips in. Add this to the security notes.

**Adapter side:**
- Behind a Valve `STATE_BACKEND` (`memory` | `laravel`, default **`memory`** so nothing changes until enabled), route `_store_case_context` / `_get_case_context` / `_clear_case_context` through either the A1 `TTLStore` (memory) or the new Laravel endpoints (laravel). Keep the local `TTLStore` as a write-through cache + fallback when Laravel is unreachable (never fail a turn because state I/O failed — degrade to transcript recovery).
- The `asyncio.Task` and `_session_store` transient bits stay in-adapter regardless of backend.

**DoD:**
- Laravel: `CaseStateServiceTest` + a Feature test for the 3 endpoints (auth required, TTL set, PHI fields only) — use the array/redis cache faker; no live Redis needed in CI.
- Adapter: with `STATE_BACKEND=memory`, behavior byte-identical to today (corpus + suite unchanged). With `STATE_BACKEND=laravel`, an httpx-mocked test proves context round-trips through the endpoints and that a backend failure degrades gracefully (no exception surfaced to the turn).
**Risk:** medium (new endpoints + hot-path I/O). **Rollback:** Valve `STATE_BACKEND=memory` (and the endpoints simply go unused).

### A3 — Make transcript-recovery a first-class, tested fallback (Phase 2)
**Problem:** `_recover_pre_result_from_history` + `_conversation_entries` + `_pending_gate_context` are the correctness backstop when stores are cold, but they're under-tested for the store-miss path.
**Change:** add tests that run the full follow-up flow with **both stores forcibly empty**, asserting the adapter reconstructs guidelines + pre_result from `__messages__` and still routes correctly. Fix any gaps found (document them).
**DoD:** corpus-driven tests pass with stores disabled; any behavioral gap is either fixed or recorded as a known limitation.
**Risk:** low/medium.

### A4 — Document & enforce the worker assumption (Phase 3)
**Change:** add a module docstring stating the in-memory backend assumes a single worker; if `STATE_BACKEND=memory` and the server reports >1 worker, log a startup warning. (Detection best-effort.)
**DoD:** warning emitted under a simulated multi-worker env var. **Risk:** trivial.

### A5 — Gate-pending `pre_result` durability (Phase 3, added 2026-07-22 post-deploy)
**Problem (observed in prod):** A2 made the *completed-case context* durable in Laravel, but the **gate-pending session** — the `pre_result` awaiting the user's confirmation reply, held in the adapter's in-memory `_session_store` — is still per-process. An `open-webui` restart (e.g. a deploy) during the seconds/minutes between the gate and the "Ok" confirmation wipes it; the follow-up then sends `confirmation_mode=true` with no `pre_retrieval_result` and Laravel returns **422** (`ToolController@consult`, "confirmation_mode requires pre_retrieval_result"). Transcript-recovery (A3) covers most cold-start cases but not the exact restart instant. Observed as a single non-recurring 422 on 2026-07-22; low frequency but real.
**Change:** extend the A2 Laravel case-state record (or a sibling `pending:{chatId}` key, short TTL ~300s) to also persist the serializable `pre_result` when a gate is shown, keyed by `chat_id`. On a confirmation turn with no in-memory session, fetch it from Laravel before falling back to transcript recovery. The `asyncio` background-retrieval task stays in-process and is simply re-run on a cold adapter (the confirmation path already re-retrieves when the cached payload is absent), so only the *context* needs persisting.
**Guard:** behind the existing `STATE_BACKEND=laravel`; `memory` keeps today's behavior. All state I/O best-effort — a miss degrades to transcript recovery / fresh pre-retrieval, never a 422. Additionally, harden the adapter to **never send `confirmation_mode` without a non-empty `pre_result`** (fall through to a fresh pre-retrieval gate instead) so this class of 422 is impossible regardless of backend.
**DoD:** Laravel `CaseStateService` (or new service) round-trips a `pre_result`; adapter test simulates "gate shown → in-memory session cleared (restart) → confirmation reply" and proves it recovers `pre_result` from Laravel and completes without a 422; and a unit test proves the adapter never emits `confirmation_mode` with an empty `pre_result`.
**Risk:** low/medium. **Rollback:** `STATE_BACKEND=memory`.

---

## 5. Workstream B — Classification Accuracy

### B1 — Consolidate turn classification into one `classify_turn()` (Phase 1, QUICK WIN — behavior-preserving)
**Problem:** the new-case/follow-up/answer-only decision is spread across `_should_treat_as_new_query` (857), `_is_answer_only_turn` (1738), `_is_vague_management_followup` (840), `_looks_like_fresh_case_intro`, and 4 regexes (88-165), invoked ad hoc in the 2330-2563 orchestration. Hard to reason about, impossible to log a single "reason", easy to introduce ordering bugs.
**Change:** add a single pure function returning a typed result:
```python
class TurnClass(str, Enum):
    NEW_CASE="NEW_CASE"; EXPLICIT_NEW_CASE="EXPLICIT_NEW_CASE"
    GATE_REPLY="GATE_REPLY"; FOLLOWUP_VAGUE="FOLLOWUP_VAGUE"
    FOLLOWUP_SUBSTANTIVE="FOLLOWUP_SUBSTANTIVE"; KNOWLEDGE="KNOWLEDGE"; GUARDRAIL="GUARDRAIL"

@dataclass
class TurnDecision: turn_class: TurnClass; reason: str

def classify_turn(question, messages, has_session, has_case_ctx) -> TurnDecision: ...
```
Rules run in an **explicit documented priority order**, each returning a named `reason` (feeds C1's log). **This is a refactor: it must reproduce today's routing exactly** — the existing helper predicates are reused inside `classify_turn`, not rewritten. The orchestration then switches on `decision.turn_class` instead of calling helpers inline.
**DoD:** characterization tests written *before* the refactor (capture current outputs across the C4 corpus), then `classify_turn` reproduces them 100%. Full suite green. Baseline accuracy from C4 **unchanged**.
**Risk:** medium (touches hot path) — mitigated by characterization tests. **Rollback:** revert commit.

### B2 — Grow the corpus to expose real misroutes (Phase 2)
**Change:** expand `turn_corpus.jsonl` to ≥80 cases mined from real usage patterns in `docs/` transcripts and `attached_assets/` (the `Pasted-*ESVS-Vascular-Guidelines-RAG*` logs on the server are real sessions — sanitize/PHI-strip before committing). Label each. Re-run C4 to get the true baseline accuracy.
**DoD:** corpus ≥80 rows; confusion matrix committed to `PLAN_EXECUTION_NOTES.md`.

### B3 — Targeted accuracy fixes (Phase 2)
Using the confusion matrix, fix the top misroute classes. Known suspects to check:
- Vague follow-ups not caught by `_VAGUE_MANAGEMENT_RE` (e.g. "and then?", "is surgery better here?").
- Substantive follow-ups that *should* requery being treated as new cases (loss of case anchor).
- Numbered-list gate replies vs genuine new content (`_is_answer_only_turn` line 1749 heuristic).
- Greek-language follow-up cues (only `_FRESH_CASE_INTRO_RE` has Greek; `_FOLLOW_UP_CUE_RE`/`_VAGUE_MANAGEMENT_RE` are English-only).
**Each fix:** add the failing corpus case first (red), then fix, then green. **Never** regress another class — the whole corpus must stay green.
**DoD:** overall accuracy improves vs B2 baseline with zero per-class regressions.

### B4 — Optional low-confidence LLM tiebreaker (Phase 3)
**Change:** only when `classify_turn` returns a low-confidence `reason` (e.g. a generic catch-all), fall back to a single cheap LLM classification call (reuse the planner client / `gpt-5-mini`). Gate behind Valve `LLM_TURN_TIEBREAK` (default `False`). Measure accuracy delta and added latency on the corpus before recommending default-on.
**DoD:** measurable accuracy gain on ambiguous subset; latency cost quantified. **Risk:** medium (cost/latency) — stays off by default.

---

## 6. Workstream D — Latency — ⛔ DEFERRED (maintainer decision 2026-07-22)

**Do not implement D1–D4 this phase.** Reason: the only latency data available is local µs-scale microbenchmarks, which do not reflect real LLM/RAGFlow time and are not a valid baseline. Revisit WS-D only after Phase 0–1 logging (C1/C2) has run in a non-prod/staging environment long enough to sample real `pre_retrieval` / `change_detection` / `retrieval` latencies. The task definitions below are retained for that future work.

Baseline the current per-turn latencies from C1/C2 logs **before** changing anything; record in `PLAN_EXECUTION_NOTES.md`.

### D1 — Never call the change-detection LLM for pure gate replies (Phase 2)
**Problem:** `ChangeDetectionService::detect` already short-circuits "short confirmatory" replies (line 33), but the threshold may miss answer-only turns like a numbered parameter list.
**Change:** align the server-side short-circuit with the adapter's `_is_answer_only_turn` definition so any answer-only reply resolves to `reuse` **without** an LLM call. Pass an adapter-computed `answer_only:true` hint in the `confirmation_mode` payload; `ChangeDetectionService` honors it as a fast-path.
**DoD:** Feature test: answer-only reply → `llm_called:false`, `decision:reuse`. Latency log shows `change_detection≈0ms` for that case.
**Risk:** low — the hint only *skips toward reuse*, which is already the safe default.

### D2 — Serve completed background retrieval immediately on answer-only replies (Phase 2)
**Problem:** on gate replies the adapter runs change detection even when the background retrieval (started in turn 1) has already finished and the reply is answer-only — an avoidable round-trip.
**Change:** in the `if session:` branch (2429), if the prefetched payload is **done** AND `classify_turn == GATE_REPLY` with answer-only reason, return the payload directly, skipping `_call_confirmation_phase`. (The prefetch at 2449 already races this — make the skip explicit.)
**DoD:** test proves no confirmation call is made when background task is done + answer-only; response identical to the reuse path.
**Risk:** low/medium — ensure guideline/query correctness matches the reuse path.

### D3 — Reuse cached retrieval for vague follow-ups when context is unchanged (Phase 2)
**Problem:** `FOLLOWUP_VAGUE` (2349) always calls `_call_consult_backend` afresh even though `_case_context_store` may hold a still-valid `retrieval_query` + the prior payload.
**Change:** cache the last completed `retrieval_payload` in the case context (subject to A2 size limits — store a compact form or a reference). If a vague follow-up maps to the same normalized retrieval query, reuse it and skip RAGFlow.
**DoD:** test proves a repeated vague follow-up on an unchanged case does not hit the backend twice; correctness preserved.
**Risk:** medium — must invalidate on any case change (reuse `ChangeDetectionService` signal).

### D4 — Planner result cache keyed by normalized question within a chat (Phase 3)
**Change:** memoize `PreRetrievalService::analyse` output per `(session_key, normalized_question)` for the TTL window to avoid recomputation on retries/edits. Laravel-side, using its Redis cache.
**DoD:** cache hit path proven by test; TTL respected. **Risk:** low.

---

## 7. Deliverables checklist (for Codex)

- [ ] Branch `codex/conversation-memory-improvements`, one commit per task ID.
- [ ] Phase 0 (C1–C4) landed with baseline accuracy + latency recorded in `PLAN_EXECUTION_NOTES.md`.
- [ ] Phase 1 (A1, B1) landed; full suites green; classification accuracy unchanged after B1 refactor.
- [ ] Phase 2 tasks landed with per-task tests; accuracy up, no per-class regression; latency deltas recorded.
- [ ] Phase 3 tasks: implement only if Phase 2 is green and time remains; keep new behaviors **off by default** behind Valves.
- [ ] `PLAN_EXECUTION_NOTES.md` summarizing: what shipped, before/after accuracy matrix, before/after latency table, any known limitations, and the exact manual deploy steps below.
- [ ] **No production deploy.** Hand back for maintainer review.

### Manual deploy reference (maintainer only — do NOT run)
```bash
# Adapter → OpenWebUI SQLite (after editing vascular_mcp_adapter.py + bumping version:)
scp -i ~/.ssh/id_ed25519 openwebui_tools/vascular_mcp_adapter.py root@178.105.193.206:/tmp/vascular_expert_new.py
scp -i ~/.ssh/id_ed25519 openwebui_tools/push_adapter.py       root@178.105.193.206:/tmp/push_adapter.py
ssh -i ~/.ssh/id_ed25519 root@178.105.193.206 "docker cp /tmp/vascular_expert_new.py open-webui:/tmp/vascular_expert_new.py && docker cp /tmp/push_adapter.py open-webui:/tmp/push_adapter.py && docker exec open-webui python3 /tmp/push_adapter.py && docker restart open-webui"

# Laravel → Hetzner (rsync per MEMORY.md; EXCLUDE ragflow_service/.venv), then:
#   composer install --no-interaction --optimize-autoloader --no-dev
#   php artisan config:cache && systemctl restart php8.5-fpm.service
```

---

## 8. Explicitly out of scope
- OpenWebUI native follow-up **suggestions** (`task.follow_up.enable`) — deliberately disabled; leave off.
- The CLTI missing-assets issue and model access-grants (already resolved in a separate effort).
- Any change to `vascular_expert.py` / `vascular_agent_adapter.py`.
- Changing retrieval tuning params (`config/ragflow.php`) — different subsystem.
