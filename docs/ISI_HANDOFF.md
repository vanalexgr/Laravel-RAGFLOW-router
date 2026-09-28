# Hand-off to ISI

_Written 2026-09-28. Audience: the ISI Athens team taking over development and
operation. Read this first; it points to everything else._

This repository is a clinical decision-support system for vascular surgery. A
clinician asks a question in OpenWebUI; the system retrieves the relevant
passages and graded recommendations from the ESVS (and related) guidelines held
in RAGFlow, and returns them with citations so the clinician can weigh the
evidence. **The product intent is to surface evidence and keep the clinician in
charge of the decision, not to issue a single deterministic recommendation.**

---

## 1. What you are receiving

| Item | Where | Notes |
|---|---|---|
| Code (public) | `github.com/vanalexgr/Laravel-RAGFLOW-router` | Two independent lines of work — see §2 |
| Server | Hetzner VM `178.105.193.206` (all-in-one) | Production OpenWebUI + Laravel + RAGFlow — see §3 |
| Guideline corpus | Inside RAGFlow on the server (~72k chunks) | Embedded with OpenAI models; must be re-embedded for local models — see §6 |
| Prototype router ("Gate v2") | Branch `claude/prototyping-summary-d597c2` | The line of work to continue — see §4 |

---

## 2. Two code lines — read this before you branch

The repository holds **two lines of work that do not share git history**.
`main` had its history rewritten to redact old (now revoked) credentials; the
prototype branch was started from the pre-rewrite history. Git therefore sees
no common ancestor, and the branches cannot be merged normally.

| | `main` | `claude/prototyping-summary-d597c2` |
|---|---|---|
| What it is | **What runs in production today** | **Gate v2** — the new router/answer engine |
| Last work | 2026-07-22 | 2026-07-29 |
| OpenWebUI tool | `openwebui_tools/vascular_mcp_adapter.py` **v1.5.59** (large, logic in Python) | `openwebui_tools/gate_adapter.py` (thin relay; logic in Laravel) |
| Endpoint | `POST /api/v1/vascular-consult` | `POST /api/v1/clinical-gate` + `GET /api/v1/gate-progress/{id}` (legacy endpoint still present) |
| Start here | `README.md`, `CLAUDE.md`, `docs/OPERATIONS.md` | `docs/CONTINUE_HERE.md` → `docs/DEVELOPMENT_PLAN.md` |

**The prototype is missing work that is live on `main`.** It forked before
these landed (all 20–22 July 2026):

- OpenAI provider migration: `app/Services/OpenAiLlmClient.php`, `docs/PROVIDER_MIGRATION.md`
- Durable case state: `CaseStateService`, `PendingCaseStateService`, their controllers and
  the `/api/v1/case-state` and `/api/v1/pending-case-state` routes
- Turn-classification work in the adapter (`turn_classification_support.py`, corpus, eval harness)
- Docs: `docs/OPERATIONS.md`, `docs/SELF_HOSTED_MODELS.md`

> ⚠️ **Deployment hazard.** The prototype's runbook
> (`docs/DEPLOY_OPENWEBUI_PROTOTYPE.md`) deploys with `rsync --delete` into the
> production app directory. Doing that from the prototype branch **deletes the
> files listed above from production** and breaks the production adapter
> (v1.5.59 depends on the case-state endpoints). Until the two lines are
> reconciled, deploy the prototype to a **separate app directory / PHP-FPM
> pool / port**, never over `/opt/cg/laravel/app`.

**Recommended first engineering task:** reconcile the two lines. Create a new
branch from `main`, bring over the prototype's `app/Ai/`, `config/gate-*.php`,
gate routes/controllers, commands, tests, `eval/` and `docs/`, then port the
`main`-only files above into it and resolve the conflicts in shared files
(`routes/api.php`, `app/Providers/AppServiceProvider.php`, `composer.json`,
`app/Services/RetrievalService.php`, `config/ragflow.php`). The prototype's
full commit history stays readable on its old branch for reference.

---

## 3. The server

Single Hetzner VM, everything on one host. Full runbook: `docs/OPERATIONS.md`
(deploy, restart matrix, health checks, logs, troubleshooting, backups).

| Component | Location |
|---|---|
| Laravel app | `/opt/cg/laravel/app/` — **rsync-deployed, not a git checkout** |
| Laravel API | PHP-FPM (`php8.5-fpm.service`), `127.0.0.1:8001`, fronted by Caddy on 80/443 |
| Caddy | `/etc/caddy/Caddyfile` — **allowlists individual `/api/v1/...` paths**; a new endpoint 404s until added here |
| RAGFlow bridge | FastAPI, `127.0.0.1:8000`, `ragflow-bridge.service`, config `ragflow_service/.env` |
| RAGFlow | Docker `docker-ragflow-cpu-1` (v0.25.5), MySQL `docker-mysql-1` (db `rag_flow`), Valkey `docker-redis-1` |
| OpenWebUI | Docker `open-webui`, database `/app/backend/data/webui.db` |

Two operational rules that have caused outages before:

- **opcache does not hot-reload PHP classes.** Config change → `php artisan config:cache`
  and reload FPM; code change → `systemctl restart php8.5-fpm.service`.
- **The live OpenWebUI tool runs from the SQLite database, not the file.** Editing
  the `.py` does nothing until it is pushed with `openwebui_tools/push_adapter.py`
  and the container is restarted (see `CLAUDE.md`).
- When rsyncing, **exclude `ragflow_service/.venv`** — deleting it takes the bridge down.

---

## 4. Gate v2 — the prototype to continue

### What it does

All clinical reasoning moves from the Python OpenWebUI tool into Laravel
(`app/Ai/Gate/`), built on `laravel/ai` with deterministic PHP orchestration
(each agent is a single structured-output call, not a tool loop). Per turn:

1. **Orient** — builds the patient model from the conversation (atomic fields:
   anatomy, laterality, diameter, …) and decides knowledge-question vs. case.
   Knowledge questions take a fast path (`KnowledgeAnswerAgent`).
2. **Routing + per-guideline branches** — each routed guideline is retrieved
   and assessed in its own branch (parallel via a process concurrency driver).
3. **Probe** — drafts the answer from numbered snippets, citing them inline as `[n]`.
4. **Critic** — scores the candidate; may trigger one revision within the deadline.
5. **Decision tail** — `decision=ask` (return only clarification questions) or answer.
6. **Presentation** — Laravel builds `citations[]` deterministically from the
   retrieved recommendation rows (a hallucinated citation is structurally
   impossible), strips unresolved `[n]` markers, and appends a deterministic
   **Evidence Used** section with class and level (`unparsed` rather than guessed).

A hard wall-clock deadline (90 s) returns best-so-far. Stage failures degrade
declaratively (listed in the answer), except Orient, which refuses rather than
guess.

### Configuration

`config/gate-v2.php` — everything is env-driven. Key flags:
`GATE_V2_ENABLED` (off by default), `GATE_V2_PROVIDER` / `GATE_V2_MODEL` and
per-stage `GATE_V2_{ORIENT,PATHWAY,PROBE,CRITIC,KNOWLEDGE}_MODEL`,
`GATE_V2_DEADLINE_SECONDS`, `GATE_V2_CITATION_MULTI_QUERY`,
`GATE_V2_RETRIEVAL_DEV_CACHE_TTL` (**development only — never for real users or
latency runs**). Also `config/gate-state.php` (state ledger, off) and
`config/gate-decision.php`.

### Tooling

- Tests: `vendor/bin/phpunit` (Gate tests under `tests/Unit/GateEval/`);
  adapter: `python -m pytest openwebui_tools/test_gate_adapter.py -q`.
- Artisan: `gate:probe2` (multi-turn runs, threads state), `gate:variance`
  (single-turn repeatability; needs `GATE_EVAL_SUT_URL` exported),
  `gate:latency`, `gate:eval`, `gate:probe-retrieval`.
- Evaluation records: `docs/eval/` (runs 6–13), benchmark `eval/benchmarks/aaa_evolving_context.md`.

### Where it stands (2026-07-29)

Working and measured: retrieval of recommendations fixed (0 → 12 citations on
the CLTI benchmark case); class/level normalisation; provenance no longer
labelled ESVS indiscriminately; atomic patient-model fields; graceful
degradation (4/4 turns complete on the reliability benchmark, p50 ≈ 55 s,
p95 ≈ 83 s); OpenWebUI adapter with live progress, inline clickable citations
and ask-first clarification.

Built but **off and unmeasured**: the event-sourced **state ledger**
(`app/Ai/Gate/State/`, `gate-state.shadow_enabled`) — a replay test shows it
fixes the largest failure class (multi-turn state loss), but its durable store
is untested on the server (the test needs `pdo_sqlite`); the **decision
contract** (`app/Ai/Gate/Decision/`, validator not wired — keep only the
completeness and contraindication checks).

Open defects and the ordered backlog are in `docs/DEVELOPMENT_PLAN.md` §4–5.
The planned next step was a **12-case clinician review round** (the clinician's
score is ground truth; the LLM judge is a screening tool only).

### Traps already paid for (details in `docs/CONTINUE_HERE.md`)

- Chunk counts in eval artifacts **sum across retrieval attempts**; they are not evidence delivered.
- RAGFlow `similarity` is an unbounded composite score × 100, not 0–1; narrative
  and citation scores are on different scales.
- Settings mutated via `config()` **do not survive into forked branch workers** — pass them explicitly.
- Recommendation rows are declarative; citation queries phrased as questions return nothing.
- Reranking is billed per call — lowering `top_k` saves nothing.
- An unexplained quality drop (4 PASS → 0 PASS) coincided with switching the
  reranker from Cohere to local; it was never isolated. **This matters directly
  for a local-model deployment** — run a matched reranker A/B early.

`MASTER_PLAN.md` and parts of `CLAUDE.md` on the prototype branch still
describe the decommissioned Azure VMs and the previous developer's personal
tooling (Codex, Antigravity, local worktree paths). Treat those as history.

---

## 5. Cloud keys and data on the server

The server currently calls cloud providers **on the previous owner's accounts**.
ISI will replace all of them with local models (§6). Until then, these are the
places a cloud credential lives — remove each one as its touchpoint moves local:

| Touchpoint | Provider now | Where the credential lives |
|---|---|---|
| Planner (Laravel) | OpenAI `gpt-5-mini` | `/opt/cg/laravel/app/.env` → `OPENAI_API_KEY` |
| Gate v2 stages (if deployed) | OpenAI (per `config/gate-v2.php`) | same `.env` |
| Answer writing | OpenAI `gpt-5-chat-latest` | OpenWebUI admin → Connections (stored in `webui.db`) |
| Embeddings | OpenAI `text-embedding-3-large` / `ada-002` | RAGFlow MySQL `rag_flow.tenant_llm` rows |
| Reranking | Cohere `rerank-english-v3.0` | RAGFlow `tenant_llm` (+ `BRIDGE_RERANK_API_KEY` in Laravel `.env`, standby) |

Backups made during the provider migration also contain keys:
`/opt/cg/laravel/app/.env.bak.*`, `ragflow_service/.env.bak.*`, and
`webui.db.bak.*` inside the `open-webui` container. Delete them once no longer needed.

**Patient data.** `webui.db` holds OpenWebUI user accounts (emails) and full
chat history, which may contain clinical details. Laravel logs
(`storage/logs/`) and Redis case state hold PHI-scrubbed, but still clinical,
content. See `docs/HIPAA_COMPLIANCE.md`. The open design question "PHI at rest
for the Gate v2 patient model in Redis" (`docs/AGENTIC_GATE_V2_PLAN.md` §0) is
now ISI's to decide.

---

## 6. Moving to local models

Walkthrough: `docs/SELF_HOSTED_MODELS.md`. Every touchpoint already speaks the
OpenAI-compatible API, so most of the move is changing base URL + model name.
Recommended order: chat models first (planner, Gate v2 stages, answer writing),
then reranker, then embeddings + a full re-index of the corpus (embeddings are
not interchangeable across models).

Gate v2 was designed for this: provider-agnostic via `laravel/ai`, structured
JSON output, and any `GATE_V2_DEEP_PATH_MODE` value other than `parallel` runs
guideline branches sequentially, for single-stream servers like Ollama. The planned local-model capability spike
(`qwen2.5:14b-instruct`) was **deferred until ISI GPU hardware exists** — it is
the gate for committing to a local model. The Hetzner VM has no GPU.

---

## 7. Decisions ISI now owns

From `docs/AGENTIC_GATE_V2_PLAN.md` §0 and `docs/DEVELOPMENT_PLAN.md`:

- Local model choice and hardware (after the capability spike).
- PHI-at-rest policy for the Gate v2 patient model.
- Who clinically signs off the audited snippet library and audits
  interpretive frames and `not_covered` verdicts.
- Progress transport for long turns (currently a polled progress endpoint).
- Cut-over criterion from the production adapter to Gate v2 (suggested:
  4 weeks stable, zero rollbacks, zero severe answer-quality reports).

---

## 8. Hand-over checklist (previous owner)

- [ ] Add ISI engineers as GitHub collaborators (or ISI forks the repo).
- [ ] Give each ISI engineer their **own SSH key** on the server; do not share the owner's key.
- [ ] Agree who pays for OpenAI/Cohere until the local move; set spend caps, or
      revoke the keys at hand-over and accept downtime until local models are in.
- [ ] Decide what happens to existing OpenWebUI accounts and chat history
      (transfer under a data-processing agreement, or export and purge).
- [ ] Transfer OpenWebUI admin and RAGFlow admin accounts.
- [ ] Hand over DNS/domain control for the Caddy site, if ISI keeps it.
- [ ] Confirm whether a Gate v2 instance is currently running on the server, and where.
