# System Architecture & Operations

A clinical decision-support retrieval system for ESVS (European Society for
Vascular Surgery) guidelines. A clinician asks a question in OpenWebUI; the system
selects the right guideline document(s), retrieves graded evidence chunks, and
returns structured evidence for the chat model to synthesise into an answer.

Companion docs: [`CONFIGURATION.md`](CONFIGURATION.md) (every setting),
[`SYSTEM_PIPELINE.md`](SYSTEM_PIPELINE.md) (retrieval internals),
[`VASCULAR_MCP_ADAPTER.md`](VASCULAR_MCP_ADAPTER.md) (the OpenWebUI tool),
[`PROVIDER_MIGRATION.md`](PROVIDER_MIGRATION.md) (model providers),
[`OPERATIONS.md`](OPERATIONS.md) (deploy/restart/troubleshoot).

---

## 1. Deployment topology

Everything runs on **one Hetzner VM**: `178.105.193.206`
(`ssh -i ~/.ssh/id_ed25519 root@178.105.193.206`).

```
                    Internet ── ufw allows only 22, 80, 443
                                     │
                              Caddy (:80/:443, TLS)
                                     │  reverse proxy
                    ┌────────────────┴───────────────────┐
                    │                                     │
         Laravel API (PHP-FPM, :8001)            OpenWebUI (Docker, :8080→proxied)
         /opt/cg/laravel/app                     container: open-webui
                    │                            DB: /app/backend/data/webui.db
                    │ HTTP (X-Bridge-Secret)
         RAGFlow bridge (FastAPI, 127.0.0.1:8000)
         ragflow_service/app.py · ragflow-bridge.service
                    │ HTTP (Bearer RAGFlow token)
         RAGFlow (Docker, :9380)  container: docker-ragflow-cpu-1 (v0.25.5)
            ├─ MySQL      container: docker-mysql-1  (db rag_flow)
            ├─ valkey     container: docker-redis-1  (cache + task queue)
            └─ Elasticsearch (doc engine, :1200)
```

| Process | Unit / container | Bind | Restart |
|---|---|---|---|
| Laravel API | `php8.5-fpm.service` | `127.0.0.1:8001` | `systemctl restart php8.5-fpm.service` (also clears opcache) |
| RAGFlow bridge | `ragflow-bridge.service` | `127.0.0.1:8000` | `systemctl restart ragflow-bridge.service` |
| Reverse proxy | `caddy.service` | `:80/:443` | `systemctl reload caddy` |
| RAGFlow | `docker-ragflow-cpu-1` | `:9380` | `docker restart docker-ragflow-cpu-1` |
| RAGFlow DB | `docker-mysql-1` | internal | — |
| RAGFlow cache/queue | `docker-redis-1` (valkey) | internal | — |
| OpenWebUI | `open-webui` | proxied | `docker restart open-webui` |

Only 22/80/443 are internet-exposed; RAGFlow, MySQL, valkey, ES, and the bridge
are firewalled / bound to localhost.

---

## 2. Request lifecycle

```
1. Clinician types a question in OpenWebUI ("ESVS expert" model = gpt-5-chat-latest).
2. The model invokes the tool `vascular_mcp_adapter` (stored in webui.db).
   • Context gate: for a patient case missing key details, the adapter returns a
     clarification request instead of retrieving. The model asks the user, then
     re-calls with the completed info (prior turns in `history`).
   • Same-case follow-ups are rewritten into a standalone retrieval query.
3. Adapter → POST /api/v1/vascular-consult (Laravel), header X-API-Key / Bearer.
4. Laravel:
   a. ValidateApiKey middleware (hash_equals) + rate limit (60/min/IP).
   b. PHIScrubberService — strip PHI before any external call.
   c. PreRetrievalPlannerService — ONE OpenAI call: normalize + route (which
      guidelines) + interpret (clinical framing) + expand (retrieval terms).
      Falls back to the legacy multi-call chain only if the planner fails.
   d. RetrievalService → RAGFlow bridge (retrieve_dual): narrative + citation
      branches, each vector-searched and reranked (Cohere) inside RAGFlow.
   e. Quality pass (if too few citation chunks) + GapDetectionService (optional
      second pass) + GuidelineAssetService (attach figures/tables).
   f. ChunkSelectionService scores/selects the final chunk sets.
5. Laravel returns narrative_chunks, citation_chunks, assets, query_normalization.
6. Adapter formats an llm_output block (STRICT_TEMPLATE) and the chat model
   synthesises the final clinical answer.
```

**Two LLM roles, deliberately different models** — the *planner* (routing, cheap:
`gpt-5-mini`) and the *synthesiser* (patient-facing answer: `gpt-5-chat-latest`,
in OpenWebUI). See [`PROVIDER_MIGRATION.md`](PROVIDER_MIGRATION.md).

---

## 3. Components

**Layer 1 — OpenWebUI tool** `openwebui_tools/vascular_mcp_adapter.py`
(DB id `vascular_mcp_adapter`). Context gate, same-case state, query rewrite,
STRICT_TEMPLATE, answer-mode classifier. Runs from `webui.db`, not the filesystem
— deploy via `push_adapter.py` (see [`VASCULAR_MCP_ADAPTER.md`](VASCULAR_MCP_ADAPTER.md)).

**Layer 2 — Laravel API** (`/opt/cg/laravel/app`). Auth, PHI scrubbing, planning,
retrieval orchestration, gap detection, asset mapping.

**Layer 3 — RAGFlow bridge + RAGFlow.** The Python bridge (`ragflow_service/app.py`)
is a thin authenticated proxy that shapes retrieval payloads and forwards them to
RAGFlow, which owns the vector index, embeddings, and reranking.

### Laravel services reference (`app/Services/`)

| Service | Responsibility |
|---|---|
| `RetrievalService` | Orchestrates the whole retrieval pipeline |
| `PreRetrievalPlannerService` | **Merged planner** — 1 LLM call (normalize/route/interpret/expand) |
| `PlannerPrompt` | Six-section prompt contract for the planner |
| `PreRetrievalService` | Legacy pre-retrieval orchestration (used on planner fallback) |
| `GuidelineRouterService` | Legacy LLM guideline selection + expansion (bypassed when planner active) |
| `ClinicalInterpreterService` | Pre-retrieval clinical framing / term extraction |
| `GraphRagService` | Concept expansion (deterministic fallback; LLM path disabled) |
| `TaxonomyExpanderService` | ESVS taxonomy term expansion (off by default) |
| `ChangeDetectionService` | Detects same-case follow-up vs new case |
| `CoverageAssessmentService` | Legacy pre-synthesis coverage assessment |
| `GapDetectionService` | Detects missing evidence fields → triggers second pass |
| `ChunkSelectionService` | **Authoritative** chunk scoring / selection / intent |
| `GuidelineAssetService` | Maps retrieved guidelines → figures/tables |
| `ClinicalGateService` | Clinical clarification gate (`/clinical-gate` endpoint) |
| `PHIScrubberService` | Strips PHI before any external API call |
| `OpenAiLlmClient` | OpenAI-compatible LLM client (bound to `App\Contracts\LlmClient`) |
| `AzureOpenAiLlmClient` | Legacy Azure client (no longer bound) |
| `BridgeRerankService` | Laravel-side Cohere rerank (standby, disabled) |
| `RAGFlow/RAGFlowClient` + `DatasetResource` (`retrieve_dual`), `DocumentResource`, `ChatResource`, `ChatSessionResource` | HTTP client + typed resources for the bridge |

Middleware: `ValidateApiKey` (auth), `HttpLogging` (request logging; PHI-gated).

---

## 4. API endpoints (`routes/api.php`, prefix `/api/v1`, `ValidateApiKey` + `throttle:60,1`)

| Method | Path | Handler | Purpose |
|---|---|---|---|
| POST | `/vascular-consult` | `ToolController@consult` | **Primary** — full retrieval pipeline |
| POST | `/agent-consult` | `AgentConsultController` | Agent-style consult entry |
| POST | `/pre-retrieval` | `ToolController@preRetrieve` | Planner/pre-retrieval only (debug/tools) |
| POST | `/normalize` | `ToolController@normalize` | Query normalization only |
| POST | `/clinical-gate` | `ToolController@clinicalGate` | Clinical clarification gate only |
| OPTIONS | `/{any}` | inline | CORS preflight (no auth) |

## 5. Bridge endpoints (`ragflow_service/app.py`, `X-Bridge-Secret` required)

| Method | Path | Purpose |
|---|---|---|
| GET | `/health`, `/status` | Liveness + config summary |
| POST | `/retrieval` | Single-dataset retrieval (forwards `rerank_id` to RAGFlow) |
| POST | `/retrieve`, `/retrieve_multi` | Legacy / multi-dataset retrieval |
| POST | `/retrieve_dual` | **Primary** — parallel narrative + citation retrieval with per-dataset caps |
| GET | `/datasets`, `/datasets/{id}` | List / inspect RAGFlow datasets |

---

## 6. Data stores

| Store | Location | Contents |
|---|---|---|
| RAGFlow index | Elasticsearch (`docker-ragflow-cpu-1`) | ~72k embedded guideline chunks across 14 datasets |
| RAGFlow metadata | MySQL `docker-mysql-1` (db `rag_flow`) | datasets, `tenant_llm` (model providers/keys), API tokens, `knowledgebase.tenant_embd_id` |
| RAGFlow cache/queue | valkey `docker-redis-1` | task queue + caches |
| OpenWebUI | `webui.db` (SQLite, in `open-webui`) | users, chats, the `vascular_mcp_adapter` tool, models, connections |
| Laravel storage | `/opt/cg/laravel/app/storage` | logs (`laravel`, `ragflow`, `retrieval`), abbreviations, keyword indexes |
