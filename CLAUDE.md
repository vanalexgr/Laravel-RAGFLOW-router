# Laravel RAGFlow Router — Claude Session Guide

## What This Project Does

A Laravel 12 API that acts as a clinical decision-support router: it receives questions from OpenWebUI (via a Python tool), routes them to the correct ESVS (European Society for Vascular Surgery) guideline documents in RAGFlow, retrieves evidence chunks, and returns structured clinical responses for synthesis by an LLM.

---

## Infrastructure

**Production is a single all-in-one Hetzner VM.** (The old Azure VMs `135.237.148.105` and `48.211.217.69` are **decommissioned** — do not use.)

| Component | Location | Access |
|---|---|---|
| Hetzner VM | `178.105.193.206` | `ssh -i ~/.ssh/id_ed25519 root@178.105.193.206` |
| Laravel app root | `/opt/cg/laravel/app/` | on Hetzner |
| Laravel API | `127.0.0.1:8001` (fronted by Caddy on :80/:443) | PHP-FPM |
| RAGFlow bridge | `127.0.0.1:8000` — FastAPI in `ragflow_service/app.py` | `ragflow-bridge.service` (reads `ragflow_service/.env`) |
| RAGFlow | container `docker-ragflow-cpu-1` (v0.25.5, HTTP API `:9380`) | Docker |
| RAGFlow DB | container `docker-mysql-1` (MySQL, db `rag_flow`) | Docker |
| RAGFlow cache/queue | container `docker-redis-1` (valkey) | Docker |
| OpenWebUI | container `open-webui`, DB `/app/backend/data/webui.db` | Docker |

### Services (on Hetzner)
```bash
# After an .env change (config is cached):
php artisan config:cache && systemctl reload php8.5-fpm.service   # from /opt/cg/laravel/app
# After a PHP class/code change (opcache does NOT hot-reload classes):
systemctl restart php8.5-fpm.service
# Bridge (RAGFlow proxy) — after ragflow_service/.env change:
systemctl restart ragflow-bridge.service
# RAGFlow / OpenWebUI:
docker restart docker-ragflow-cpu-1
docker restart open-webui
```

---

## Key Files

### Laravel Backend
| File | Purpose |
|---|---|
| `routes/api.php` | Single route: `POST /api/v1/vascular-consult` |
| `app/Http/Controllers/ToolController.php` | Request entry point; input validation (max:2000, history max:20) |
| `app/Http/Middleware/ValidateApiKey.php` | API key auth via `config('services.api.key')`, `hash_equals()` |
| `app/Services/RetrievalService.php` | Orchestrates the full retrieval pipeline; uses merged planner when enabled |
| `app/Services/PreRetrievalPlannerService.php` | Merged pre-retrieval planner — single LLM call for normalize/route/interpret/expand |
| `app/Services/PlannerPrompt.php` | Six-section prompt contract for the merged planner |
| `app/ValueObjects/RetrievalPlan.php` | Typed value object returned by the planner |
| `app/Services/GuidelineRouterService.php` | LLM-based guideline selection + query expansion (legacy; bypassed when planner active) |
| `app/Services/RAGFlow/RAGFlowClient.php` | HTTP client for RAGFlow bridge |
| `app/Services/RAGFlow/DatasetResource.php` | `retrieve_dual` calls |
| `app/Services/PHIScrubberService.php` | PHI scrubbing before any external API calls |
| `app/Services/GapDetectionService.php` | Detects missing evidence fields; triggers second pass |
| `app/Services/GuidelineAssetService.php` | Maps retrieved guidelines to figures/tables |
| `config/ragflow.php` | All retrieval tuning parameters |
| `config/guideline_assets.php` | Figure/table assets per guideline (NOTE: `descending_thoracic_aorta` has no assets yet) |
| `bootstrap/app.php` | Middleware stack; proxy trust locked to `127.0.0.1` |
| `ragflow_service/app.py` | Python bridge between Laravel and RAGFlow API |

### OpenWebUI Tool
| File | Purpose |
|---|---|
| `openwebui_tools/vascular_mcp_adapter.py` | Production tool — stored in `webui.db` `tool` table, **id=`vascular_mcp_adapter`** |
| `openwebui_tools/push_adapter.py` | Deploy script — updates the correct DB record |
| `openwebui_tools/vascular_expert.py` | OLD fallback tool (id=`mcp`) — never modify |

**IMPORTANT**: The live tool runs from the SQLite DB, not from the filesystem. After editing `vascular_mcp_adapter.py` locally, always push to the DB:
```bash
scp -i ~/.ssh/id_ed25519 openwebui_tools/vascular_mcp_adapter.py root@178.105.193.206:/tmp/vascular_expert_new.py
scp -i ~/.ssh/id_ed25519 openwebui_tools/push_adapter.py root@178.105.193.206:/tmp/push_adapter.py
ssh -i ~/.ssh/id_ed25519 root@178.105.193.206 "
  docker cp /tmp/vascular_expert_new.py open-webui:/tmp/vascular_expert_new.py &&
  docker cp /tmp/push_adapter.py open-webui:/tmp/push_adapter.py &&
  docker exec open-webui python3 /tmp/push_adapter.py &&
  docker restart open-webui
"
```
`push_adapter.py` writes to **id=`vascular_mcp_adapter`** (the active production tool).
OpenWebUI caches the loaded tool module in memory — restart is required after every DB update.

---

## Architecture Flow

```
OpenWebUI (user message)
  → LLM decides to call consult_vascular_guidelines tool
  → vascular_expert.py (mcp tool in OpenWebUI)
      ├─ [CASE GATE] _assess_context_gaps() — asks for missing params once per case before retrieval
      ├─ [CASE STATE] builds compact same-case state from prior user turns, answered clarifications, attachments, and cited recommendations
      ├─ [QUERY REWRITE] rewrites same-case follow-ups into a standalone retrieval query
      ├─ POST /api/v1/vascular-consult (Laravel, Hetzner VM)
      │     ├─ ValidateApiKey middleware
      │     ├─ PHIScrubberService (scrub before external calls)
      │     ├─ RetrievalService
      │     │     ├─ [PLANNER] PreRetrievalPlannerService — 1 LLM call: normalize + route + interpret + expand
      │     │     │     └─ fallback: GuidelineRouterService (4 sequential LLM calls) if planner disabled/fails
      │     │     ├─ retrieveDualChunks() → RAGFlow bridge (port 8000) → RAGFlow API
      │     │     ├─ Quality pass (if < min_citation chunks returned)
      │     │     ├─ GapDetectionService (optional second pass)
      │     │     └─ GuidelineAssetService (figures/tables)
      │     └─ Returns: narrative_chunks, citation_chunks, assets, query_normalization
      └─ Formats llm_output with STRICT_TEMPLATE (Assessment/Imaging/Treatment/etc.)
  → LLM synthesises final answer from llm_output
```

---

## Retrieval Tuning (`.env` on Hetzner VM)

Key env vars affecting performance:
```
# Merged planner (enabled in production as of 2026-07-13)
RETRIEVAL_PLANNER_MERGED_ENABLED=true   # single LLM call replaces 4 legacy calls; ~3-6s saving
RAGFLOW_PLANNER_SHADOW=false            # set true to log plan without changing pipeline behaviour
RAGFLOW_PLANNER_MAX_TOKENS=1000
RAGFLOW_PLANNER_TEMPERATURE=0.0

# RAGFlow retrieval
RAGFLOW_QUALITY_PASS_ENABLED=true
RAGFLOW_QUALITY_PASS_MIN_CITATION=2     # trigger quality pass if < 2 citation chunks
RAGFLOW_QUALITY_PASS_TOP_K=80           # was 256 — reduced to prevent 60s timeouts
RAGFLOW_HIGH_RECALL_TOP_K_CEILING=128   # was 1024
RAGFLOW_QUALITY_PASS_MIN_NARRATIVE=8
```

3-guideline queries previously timed out (90–102s) because quality pass used `top_k=256` → 60s per RAGFlow call. Now capped at 80.

### Monitoring pre-retrieval latency
Every request now logs a `[PRE-RETRIEVAL TIMING]` line in the retrieval log:
```bash
tail -f /opt/cg/laravel/app/storage/logs/retrieval-$(date +%Y-%m-%d).log | grep 'PRE-RETRIEVAL TIMING'
# Planner path:  {"plan_applied":true,"total_ms":1240,"planner_ms":1240}
# Legacy path:   {"plan_applied":false,"total_ms":5830,"normalize_ms":1210,"route_ms":1820,...}
```

---

## OpenWebUI Tool: Key Behaviours

### Agentic Context Check
Before calling the backend, `_assess_context_gaps()` checks for missing clinical parameters in patient-case presentations.

- Raw knowledge questions such as definitions, thresholds, and population-level guideline questions skip the gate.
- Patient-case consultations trigger the gate.
- The gate opens once per case, not once per turn.
- Same-case follow-up replies do not reopen the gate.
- A clearly different patient/case reopens the gate.
- Uploaded documents contribute case context, but do not bypass patient-case handling.

**Covered scenarios** (in `_CONTEXT_GAP_RULES`):
- `aortic_thrombus` — anticoagulation status, thrombus mobility, stroke aetiology
- `carotid_stenosis` — symptomatic status, stenosis degree
- `aaa_treatment` — aneurysm diameter, patient fitness
- `dvt_pe` — provoking factors, first vs recurrent episode
- `clti` — anatomical workup (CTA/duplex), patient fitness
- `svt` — distance from SFJ/SPJ, risk stratification
- `type_b_dissection` — complicated vs uncomplicated, acute/subacute/chronic phase
- `ali` — Rutherford class + duration, thrombotic vs embolic
- `graft_infection` — clinical signs, prosthesis type + timing

**Generic catch-all**: if no specific scenario rule fits, the tool still asks at least one case-specific clarification question before retrieval.

### Same-Case Follow-up Retrieval

Same-case follow-up turns do not go to Laravel as raw short questions.

Instead, the tool:

1. builds a compact conversation state
2. rewrites the latest follow-up into one standalone retrieval query
3. sends that rewritten query to Laravel
4. avoids passing the raw chat-history blob as retrieval evidence

This is the intended pattern for turns such as:

- `What about surveillance?`
- `What if the patient mobilises after 10 days?`
- `What is best for this patient? CEA or CAS?`

### STRICT_TEMPLATE
Always enabled. When `llm_total_chunks > 0`, injects structured template into `llm_output`:
- Assessment / Imaging / Indication / Treatment options / Follow-up / Evidence used
- + Clinical Decision Summary + Perioperative Risk (when `_requires_clinical_decision_summary()` = True)

### Scope Filter
LLM is instructed not to cite recommendations for different procedures than the case (e.g., TEVAR recs for a mural thrombus question).

### Guideline Selection Rules
The LLM selects guidelines at tool-call time from the docstring.

- Add `antithrombotic_therapy` only when the user is actually asking about anticoagulation or antithrombotic decisions.
- Do not add `antithrombotic_therapy` just because the case mentions stroke or carotid disease.
- Laravel also prunes low-relevance `antithrombotic_therapy` companions before retrieval when a broader carotid-management question does not actually ask for anticoagulation guidance.

### Carotid Disabling-Stroke Focus

Laravel retrieval boosts now add explicit disabling-stroke terms for carotid cases that mention:

- major/disabling stroke
- mRS / modified Rankin
- failure to mobilise
- severe neurological deficit

This improves retrieval of the defer-intervention recommendation in severe post-stroke carotid cases.

---

## Logs

```bash
# On the Hetzner VM (/opt/cg/laravel/app):
tail -f storage/logs/laravel.log
tail -f storage/logs/ragflow-$(date +%Y-%m-%d).log
tail -f storage/logs/retrieval-$(date +%Y-%m-%d).log

# OpenWebUI (same VM):
docker logs open-webui --tail 100 -f
```

### Reading recent queries from OpenWebUI DB
```bash
sudo docker exec open-webui python3 -c "
import sqlite3, json
conn = sqlite3.connect('/app/backend/data/webui.db')
rows = conn.execute('''
  SELECT u.email, m.content, m.timestamp
  FROM message m JOIN user u ON m.user_id = u.id
  ORDER BY m.timestamp DESC LIMIT 20
''').fetchall()
for r in rows: print(r)
conn.close()
"
```

---

## Security Notes

- API key validated via `config('services.api.key')` (NOT `env()`) — works after `config:cache`
- `hash_equals()` used for timing-safe comparison
- Proxy trust locked to `127.0.0.1` (Caddy on same host)
- History sanitized before LLM injection (prompt injection prevention)
- PHI scrubbed before any external API call (RAGFlow, OpenAI, Cohere)
- Rate limit: 60 req/min per IP on `/api/v1/vascular-consult`

---

## Model Providers (as of 2026-07-21)

Migrated off Azure OpenAI. Four independent touchpoints — see [`docs/PROVIDER_MIGRATION.md`](docs/PROVIDER_MIGRATION.md):
- **Embeddings**: OpenAI `text-embedding-3-large` / `ada-002` (RAGFlow; bound via numeric `knowledgebase.tenant_embd_id`, NOT the `embd_id` string).
- **Reranking**: Cohere `rerank-english-v3.0` (RAGFlow-side; `RAGFLOW_RERANK_ID`). Bridge just forwards `rerank_id`; Laravel-side `BridgeRerankService` is a disabled standby.
- **Planner inference**: OpenAI `gpt-5-mini` via `App\Services\OpenAiLlmClient` (bound in `AppServiceProvider`), config in `config/services.php` `services.openai` (NOT `config/prism.php`). `reasoning_effort=minimal`, `RETRIEVAL_PLANNER_MAX_TOKENS=3000`.
- **Synthesis**: OpenAI `gpt-5-chat-latest` (OpenWebUI "ESVS expert" model `base_model_id`).
- GraphRag LLM disabled (`GRAPHRAG_LLM_ENABLED=false`). No live Azure dependency.
- **Going fully self-hosted / local models**: [`docs/SELF_HOSTED_MODELS.md`](docs/SELF_HOSTED_MODELS.md).
- **opcache**: config changes → `php artisan config:cache`; PHP class changes → full `systemctl restart php8.5-fpm.service`.

## Known Gaps / TODO

- `descending_thoracic_aorta` has **no figures/tables** configured in `config/guideline_assets.php`
- `PHIScrubberService` has 3 minor bugs (wrong counter key in `scrubAgesOver90`, token-skip in `scrubNames`, unhandled null from `preg_replace_callback`) — low risk, not yet fixed
- OpenWebUI follow-up question suggestions: disabled globally (`task.follow_up.enable = false` in config DB)

# context-mode — MANDATORY routing rules

You have context-mode MCP tools available. These rules are NOT optional — they protect your context window from flooding. A single unrouted command can dump 56 KB into context and waste the entire session.

## BLOCKED commands — do NOT attempt these

### curl / wget — BLOCKED
Any Bash command containing `curl` or `wget` is intercepted and replaced with an error message. Do NOT retry.
Instead use:
- `ctx_fetch_and_index(url, source)` to fetch and index web pages
- `ctx_execute(language: "javascript", code: "const r = await fetch(...)")` to run HTTP calls in sandbox

### Inline HTTP — BLOCKED
Any Bash command containing `fetch('http`, `requests.get(`, `requests.post(`, `http.get(`, or `http.request(` is intercepted and replaced with an error message. Do NOT retry with Bash.
Instead use:
- `ctx_execute(language, code)` to run HTTP calls in sandbox — only stdout enters context

### WebFetch — BLOCKED
WebFetch calls are denied entirely. The URL is extracted and you are told to use `ctx_fetch_and_index` instead.
Instead use:
- `ctx_fetch_and_index(url, source)` then `ctx_search(queries)` to query the indexed content

## REDIRECTED tools — use sandbox equivalents

### Bash (>20 lines output)
Bash is ONLY for: `git`, `mkdir`, `rm`, `mv`, `cd`, `ls`, `npm install`, `pip install`, and other short-output commands.
For everything else, use:
- `ctx_batch_execute(commands, queries)` — run multiple commands + search in ONE call
- `ctx_execute(language: "shell", code: "...")` — run in sandbox, only stdout enters context

### Read (for analysis)
If you are reading a file to **Edit** it → Read is correct (Edit needs content in context).
If you are reading to **analyze, explore, or summarize** → use `ctx_execute_file(path, language, code)` instead. Only your printed summary enters context. The raw file content stays in the sandbox.

### Grep (large results)
Grep results can flood context. Use `ctx_execute(language: "shell", code: "grep ...")` to run searches in sandbox. Only your printed summary enters context.

## Tool selection hierarchy

1. **GATHER**: `ctx_batch_execute(commands, queries)` — Primary tool. Runs all commands, auto-indexes output, returns search results. ONE call replaces 30+ individual calls.
2. **FOLLOW-UP**: `ctx_search(queries: ["q1", "q2", ...])` — Query indexed content. Pass ALL questions as array in ONE call.
3. **PROCESSING**: `ctx_execute(language, code)` | `ctx_execute_file(path, language, code)` — Sandbox execution. Only stdout enters context.
4. **WEB**: `ctx_fetch_and_index(url, source)` then `ctx_search(queries)` — Fetch, chunk, index, query. Raw HTML never enters context.
5. **INDEX**: `ctx_index(content, source)` — Store content in FTS5 knowledge base for later search.

## Subagent routing

When spawning subagents (Agent/Task tool), the routing block is automatically injected into their prompt. Bash-type subagents are upgraded to general-purpose so they have access to MCP tools. You do NOT need to manually instruct subagents about context-mode.

## Output constraints

- Keep responses under 500 words.
- Write artifacts (code, configs, PRDs) to FILES — never return them as inline text. Return only: file path + 1-line description.
- When indexing content, use descriptive source labels so others can `ctx_search(source: "label")` later.

## ctx commands

| Command | Action |
|---------|--------|
| `ctx stats` | Call the `ctx_stats` MCP tool and display the full output verbatim |
| `ctx doctor` | Call the `ctx_doctor` MCP tool, run the returned shell command, display as checklist |
| `ctx upgrade` | Call the `ctx_upgrade` MCP tool, run the returned shell command, display as checklist |
