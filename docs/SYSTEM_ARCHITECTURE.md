# Vascular Guidelines System — Architecture & Operations

> **⚠️ Current infra & providers (2026-07-21):** production is a single
> **Hetzner VM** (`178.105.193.206`) with **OpenAI** (inference + embeddings) and
> **Cohere** (reranking). References below to Azure OpenAI or the old Azure VMs
> (`135.237.148.105`, `48.211.217.69`) are **historical** — see
> [`../CLAUDE.md`](../CLAUDE.md) and [`PROVIDER_MIGRATION.md`](PROVIDER_MIGRATION.md)
> for the authoritative current state.

**Stack:** Laravel 12 API · RAGFlow · OpenWebUI · Azure OpenAI
**Purpose:** Clinical decision-support for vascular surgeons. Retrieves evidence from ESVS guidelines and returns structured, citation-backed answers.

---

## 1. Components

| Component | Host | Role |
|---|---|---|
| **Laravel API** | Azure VM `135.237.148.105` | Orchestrates retrieval, LLM calls, response assembly |
| **RAGFlow bridge** | `localhost:8000` on 135 VM | Python FastAPI proxy between Laravel and RAGFlow |
| **RAGFlow** | Azure VM `48.211.217.69` | Vector search over ESVS guideline documents |
| **OpenWebUI** | Azure VM `48.211.217.69` | Chat interface; hosts the adapter tool |
| **Azure OpenAI** | Azure-hosted | LLM for pre-retrieval analysis, change detection, routing |

---

## 2. End-to-End Request Flow

There are two modes: **standard** (single-round) and **pre-retrieval** (two-round with confirmation).

### 2.1 Standard Mode

```
User types question in OpenWebUI
  │
  ▼
vascular_mcp_adapter.py (tool running inside OpenWebUI)
  │  • classifies question (patient case vs knowledge query)
  │  • builds compact history context
  │  • sends POST /api/v1/vascular-consult
  │
  ▼
ToolController::consult()  [135 VM]
  │  • validates input (max 2000 chars, max 20 history items)
  │  • ValidateApiKey middleware (hash_equals timing-safe check)
  │  • guardrail check (out-of-scope / capability prompts short-circuit here)
  │  • calls executeRetrieval()
  │       ├─ RetrievalService::retrieve()
  │       │     ├─ GuidelineRouterService: query normalisation + guideline selection
  │       │     ├─ PHIScrubberService: scrubs PHI before any external call
  │       │     ├─ RAGFlowClient → bridge (port 8000) → RAGFlow vector search
  │       │     ├─ quality pass (if < min_citation chunks returned)
  │       │     ├─ GapDetectionService: optional second pass for missing fields
  │       │     └─ ChunkSelectionService: scores + ranks chunks by intent profile
  │       └─ GuidelineAssetService: maps chunks to figures/tables
  │  • buildConsultPayload(): assembles llm_output, narrative_chunks, citation_chunks, assets
  │
  ▼
adapter formats response using STRICT_TEMPLATE
  │  Assessment / Imaging / Indication / Treatment / Follow-up / Evidence used
  │
  ▼
LLM synthesises final answer shown to user
```

### 2.2 Pre-Retrieval Mode (two-round)

Designed to eliminate perceived latency: the LLM interprets the question and retrieval runs in parallel while the user reads the confirmation message.

```
User types patient case in OpenWebUI
  │
  ▼
adapter: detects patient-case pattern → sets pre_retrieval_mode=true
  │
  ▼ POST /api/v1/vascular-consult  (pre_retrieval_mode: true)

ToolController::consult() — Phase 1
  │  • PreRetrievalService::analyse()
  │       └─ AzureOpenAiLlmClient: LLM reads question, returns JSON:
  │            proceed, soft_warn, clarification_questions,
  │            provisional_diagnosis, guidelines, retrieval_query,
  │            scope, confirmation_message
  │  • applyRequestedGuidelines(): merges any adapter-selected guidelines
  │  • executeRetrieval() runs immediately with LLM-selected query + guidelines
  │  • returns phase=awaiting_confirmation with:
  │       confirmation_message  ← shown to user while they read/type
  │       pre_retrieval_result  ← stored by adapter for phase 2
  │       retrieval_payload     ← cached by adapter (already complete)
  │
  ▼
adapter stores {pre_result, payload} in _session_store (TTL 300s)
adapter emits confirmation_message to user

User reads message, types reply (5–30s — retrieval already done)
  │
  ▼ POST /api/v1/vascular-consult  (confirmation_mode: true)
    body includes: pre_retrieval_result + cached_retrieval_payload

ToolController::consult() — Phase 2
  │  • ChangeDetectionService::detect()
  │       1. deterministicGuidelineShift(): hardcoded PAD→CLTI rule (no LLM)
  │       2. LLM prompt: reuse | requery decision
  │  • decision=reuse → returns cached_retrieval_payload immediately (no retrieval)
  │  • decision=requery → executeRetrieval() with enriched_query + updated_guidelines
  │
  ▼
adapter uses payload to format STRICT_TEMPLATE response
LLM synthesises final answer
```

**Why latency disappears:** The pre-retrieval LLM call takes 2–5 s. Retrieval takes 10–60 s. Both happen server-side before the response returns to the adapter. The user needs ~5–30 s to read the confirmation and type a reply. By then retrieval is complete. Phase 2 is either instant (reuse) or triggers a second retrieval only when the reply genuinely changes the clinical picture.

---

## 3. Services Reference

### Laravel (`app/Services/`)

| Service | Role |
|---|---|
| `PreRetrievalService` | LLM-based query analysis: provisional diagnosis, guideline selection, retrieval query, confirmation message. Falls back to `safeDefaults` on any LLM/parse failure. |
| `ChangeDetectionService` | Given a user reply, decides `reuse` or `requery`. Runs a deterministic PAD→CLTI check first; LLM only if needed. Fails safe to `reuse`. |
| `RetrievalService` | Orchestrates the full retrieval pipeline: normalisation → PHI scrub → RAGFlow → quality pass → gap detection → chunk scoring. |
| `GuidelineRouterService` | Query normalisation, language detection, guideline routing for standard retrieval. Used by `RetrievalService` internally. |
| `ChunkSelectionService` | Scores and ranks RAGFlow chunks by intent profile (citation vs narrative). Authoritative for chunk selection. |
| `GapDetectionService` | Detects missing evidence fields; triggers optional second retrieval pass. |
| `GuidelineAssetService` | Maps retrieved guidelines to their figures and tables. |
| `PHIScrubberService` | Scrubs PHI (names, ages >90, identifiers) before any external API call. |
| `ClinicalGateService` | LLM-based gate: classifies question as patient-case vs knowledge query. |
| `AzureOpenAiLlmClient` | Thin HTTP client for Azure OpenAI. Implements `LlmClient` interface. Reads credentials exclusively from `config('prism.providers.azure.*')` — no `env()` fallbacks. |
| `BridgeRerankService` | Re-ranks RAGFlow results via bridge. |
| `TaxonomyExpanderService` | Expands clinical terms for query enrichment. |
| `GraphRagService` | Graph-based retrieval augmentation (supplementary). |

### Value Objects (`app/ValueObjects/`)

| Class | Fields |
|---|---|
| `PreRetrievalResult` | `proceed`, `softWarn`, `clarificationQuestions`, `provisionalDiagnosis`, `guidelines`, `retrievalQuery`, `scope`, `confirmationMessage` |
| `ChangeDetectionResult` | `decision` (reuse\|requery), `reason`, `enrichedQuery`, `updatedGuidelines` |

### Contracts (`app/Contracts/`)

| Interface | Bound to |
|---|---|
| `LlmClient` | `AzureOpenAiLlmClient` (registered in `AppServiceProvider`) |

---

## 4. API Endpoints

All endpoints: `POST https://<host>/api/v1/<endpoint>`
Auth: `Authorization: Bearer <key>` checked via `ValidateApiKey` middleware.
Rate limit: 60 req/min per IP.

### `POST /vascular-consult`

Main retrieval endpoint. Supports three modes via request flags.

**Standard mode** (default):
```json
{
  "question": "75yo man, TIA last week, 78% ICA stenosis",
  "history": ["...prior turns..."],
  "guidelines": ["carotid_vertebral"]
}
```

**Pre-retrieval mode** (phase 1):
```json
{
  "question": "...",
  "pre_retrieval_mode": true,
  "guidelines": ["carotid_vertebral"]
}
```
Returns:
```json
{
  "phase": "awaiting_confirmation",
  "confirmation_message": "→ Understanding: ...\n→ Searching: ...",
  "soft_warn": false,
  "clarification_questions": ["Is the patient fit for surgery?"],
  "provisional_diagnosis": "Symptomatic left carotid stenosis",
  "pre_retrieval_result": { ... },
  "retrieval_payload": { ... }
}
```

**Confirmation mode** (phase 2):
```json
{
  "question": "yes, patient is fit",
  "confirmation_mode": true,
  "pre_retrieval_result": { ... },
  "cached_retrieval_payload": { ... }
}
```
Returns on reuse:
```json
{
  "phase": "complete",
  "reused": true,
  "decision_reason": "fitness clarification, same diagnosis",
  "retrieval_payload": { ... }
}
```
Returns on requery:
```json
{
  "phase": "complete",
  "reused": false,
  "decision_reason": "new anatomical territory",
  "retrieval_payload": { ... }
}
```

**Payload shape** (inside `retrieval_payload`):
```json
{
  "result": "RETRIEVED GUIDELINES for: ...",
  "narrative_chunks": [...],
  "citation_chunks": [...],
  "llm_citation_chunks": [...],
  "llm_narrative_chunks": [...],
  "ui_citation_chunks": [...],
  "ui_narrative_chunks": [...],
  "must_include_chunk": null,
  "intent_profile": {...},
  "selected_guidelines": [...],
  "assets": [...],
  "query_normalization": {...},
  "query_type": "complex_case"
}
```

### `POST /normalize`

Lightweight query normaliser. Returns trimmed query + language detection.
Full LLM-based normalisation happens inside `RetrievalService` before retrieval.

```json
{ "question": "Quel est le traitement de la sténose carotidienne?" }
```
```json
{ "normalized_query": "...", "language": "other", "changed": false }
```

### `POST /clinical-gate`

Classifies a question as patient-case vs knowledge query. Used by adapter for gate decisions.

```json
{ "question": "75yo man with TIA...", "history": [] }
```

---

## 5. OpenWebUI Adapter (`openwebui_tools/vascular_mcp_adapter.py`)

The adapter is the only client of the Laravel API. It runs as a tool inside OpenWebUI (tool ID: `vascular_mcp_adapter`). The live version is stored in the OpenWebUI SQLite DB — filesystem edits must be pushed to the DB and the container restarted.

### Key behaviours

**Question classification** — before any API call, the adapter classifies the question:
- Patient-case markers (age, "my patient", "presents with") → pre-retrieval mode
- Raw knowledge questions → standard mode
- Out-of-scope (general knowledge, model meta) → blocked locally

**Session store** — module-level `_session_store` dict (TTL 300 s):
- Phase 1 stores `{pre_result, payload, task, started_at, confirmation_history}`
- Phase 2 reads the session; if expired, falls back to standard retrieval
- `cached_retrieval_payload` is always sent in phase 2 so the server can echo it back even if the adapter session has expired

**STRICT_TEMPLATE** — always active when chunks are returned. Forces answer structure:
- Assessment / Imaging / Indication / Treatment options / Follow-up / Evidence used
- Clinical Decision Summary + Perioperative Risk (for management questions)

**Same-case follow-up** — detects when a new message is a follow-up on the same case (not a new patient). Rewrites the follow-up into a standalone retrieval query rather than sending the raw short message.

**Guideline selection** — the adapter passes `guidelines` keys to the API. The `PreRetrievalService` LLM may override or augment these. Final keys are logged in each retrieval.

### Deploying adapter changes

```bash
# 1. Edit locally
vi openwebui_tools/vascular_mcp_adapter.py

# 2. Push to OpenWebUI DB
scp -i ~/ragflownew.pem openwebui_tools/vascular_mcp_adapter.py \
    azureuser@48.211.217.69:/tmp/vascular_expert_new.py
ssh -i ~/ragflownew.pem azureuser@48.211.217.69 \
    "sudo docker cp /tmp/vascular_expert_new.py open-webui:/tmp/vascular_expert_new.py && \
     sudo docker exec open-webui python3 /tmp/push_tool_content.py"

# 3. Restart to reload from DB
ssh -i ~/ragflownew.pem azureuser@48.211.217.69 "sudo docker restart open-webui"
```

---

## 6. Configuration

### Laravel `.env` (on 135 VM — key retrieval tuning vars)

```ini
# Azure OpenAI — used by PreRetrievalService + ChangeDetectionService
# Set via config/prism.php → prism.providers.azure.*
AZURE_OPENAI_ENDPOINT=https://...
AZURE_OPENAI_API_KEY=...
AZURE_OPENAI_DEPLOYMENT=gpt-5-chat
AZURE_OPENAI_VERSION=2024-12-01-preview

# RAGFlow bridge
RAGFLOW_BRIDGE_URL=http://localhost:8000
RAGFLOW_BRIDGE_SECRET=...

# Retrieval tuning
RAGFLOW_QUALITY_PASS_ENABLED=true
RAGFLOW_QUALITY_PASS_MIN_CITATION=2     # trigger quality pass if < 2 citation chunks
RAGFLOW_QUALITY_PASS_TOP_K=80           # capped at 80 to avoid 60s timeouts
RAGFLOW_HIGH_RECALL_TOP_K_CEILING=128
RAGFLOW_QUALITY_PASS_MIN_NARRATIVE=8
```

After any `.env` change:
```bash
php artisan config:cache && sudo systemctl restart laravel-api.service
```

**Important:** `AzureOpenAiLlmClient` reads Azure credentials exclusively from `config('prism.providers.azure.*')`. `env()` is not used as a fallback, so `config:cache` is always authoritative.

### Config files

| File | Purpose |
|---|---|
| `config/ragflow.php` | Retrieval tuning: top_k, quality pass thresholds, scoring weights |
| `config/chunk_scoring.php` | ChunkSelectionService scoring weights by chunk type |
| `config/guideline_assets.php` | Figure/table assets per guideline (note: `descending_thoracic_aorta` has no assets yet) |
| `config/guidelines.php` | Guideline key→name map, key_concepts for routing |

---

## 7. Guideline Keys

Valid keys for the `guidelines` request field:

| Key | Guideline |
|---|---|
| `aortic_arch` | Aortic Arch |
| `descending_thoracic_aorta` | Thoracic Aorta |
| `abdominal_aortic_aneurysm` | AAA |
| `carotid_vertebral` | Carotid & Vertebral |
| `mesenteric_renal` | Mesenteric & Renal |
| `acute_limb_ischaemia` | ALI |
| `asymptomatic_pad` | Asymptomatic PAD |
| `clti` | CLTI |
| `venous_thrombosis` | Venous Thrombosis |
| `chronic_venous_disease` | CVD |
| `antithrombotic_therapy` | Antithrombotics |
| `vascular_trauma` | Vascular Trauma |
| `vascular_graft_infections` | Graft Infections |
| `vascular_access` | Vascular Access |

The adapter LLM selects guidelines at tool-call time. `PreRetrievalService` may override or augment the selection. `antithrombotic_therapy` is only added when the question is specifically about anticoagulation decisions, not merely because the case mentions stroke or carotid disease.

---

## 8. Logs

```bash
# On 135 VM
tail -f /home/azureuser/laravel-ragflow/storage/logs/laravel.log
tail -f /home/azureuser/laravel-ragflow/storage/logs/ragflow-$(date +%Y-%m-%d).log
tail -f /home/azureuser/laravel-ragflow/storage/logs/retrieval-$(date +%Y-%m-%d).log

# On 48 VM
sudo docker logs open-webui --tail 100 -f
```

Key log tags to search: `[PRE-RETRIEVAL]`, `[CHANGE DETECTION]`, `[GUARDRAIL]`, `[MULTILINGUAL RETRY]`, `[QUALITY PASS]`.

---

## 9. Security

| Control | Implementation |
|---|---|
| API key auth | `ValidateApiKey` middleware; `hash_equals()` timing-safe comparison |
| PHI scrubbing | `PHIScrubberService` runs before every external API call |
| Proxy trust | Locked to `127.0.0.1` (Caddy on same host) |
| History sanitisation | Adapter strips history before LLM injection |
| Rate limiting | 60 req/min per IP on all `/api/v1/*` routes |
| Config isolation | Azure credentials read from `config()` only — never `env()` at runtime |

**Known open items:**
- `scrubNames()` token-skip bug: last name may be unredacted before sending to Azure/RAGFlow (low risk, deferred)
- Verify port 8000 is firewalled on 135 VM (bridge binds `0.0.0.0`)
- Bridge secret comparison uses `!=` not `hmac.compare_digest` (timing attack surface)

---

## 10. Known Gaps

- `descending_thoracic_aorta` has no figures/tables in `config/guideline_assets.php`
- 3 minor bugs in `PHIScrubberService` (wrong counter key, token-skip, unhandled null) — low risk, not fixed
- `GuidelineRouterService` is still used inside `RetrievalService` for standard retrieval normalisation. It is no longer injected into `ToolController`. Full consolidation into `PreRetrievalService` is a future task.
