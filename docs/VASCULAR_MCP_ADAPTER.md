# Vascular MCP Adapter — Architecture & Layered Responsibilities

> **⚠️ Current infra & providers (2026-07-21):** production is a single
> **Hetzner VM** (`178.105.193.206`) with **OpenAI** (inference + embeddings) and
> **Cohere** (reranking). Any Azure OpenAI or old Azure-VM
> (`135.237.148.105`, `48.211.217.69`) references below are **historical** — see
> [`../CLAUDE.md`](../CLAUDE.md) and [`PROVIDER_MIGRATION.md`](PROVIDER_MIGRATION.md).

Current production state for the March 15, 2026 two-phase clarification flow is documented in `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/docs/SESSION_CHANGES_2026-03-15.md`.

This file is still useful as a layered reference, but parts of the earlier gate description below are now historical because the adapter moved to Laravel-backed pre-retrieval plus confirmation/change-detection orchestration.

This document describes the `vascular_mcp_adapter` OpenWebUI tool introduced in March 2026:
what it does at each layer (OpenWebUI, Laravel, RAGFlow), how it differs from the production
`vascular_expert.py` tool, and how to deploy and configure it.

---

## Overview

The system has two OpenWebUI tools:

| Tool ID | File | Model | Status |
|---|---|---|---|
| `vascular_mcp_adapter` | `openwebui_tools/vascular_mcp_adapter.py` | `gpt-5-chat` | **Production** — all new development here |
| `mcp` | `openwebui_tools/vascular_expert.py` | *(disabled)* | Fallback only — do not modify |

**`vascular_expert.py` is never modified.** All development goes into the adapter.
Laravel orchestration (ChunkSelectionService, retrieval pipeline) is the source of truth for chunk selection, scoring, and intent — the adapter is a thin consumer of that output.

---

## End-to-End Data Flow

```
User message (OpenWebUI)
  │
  ▼
[1] vascular_mcp_adapter — Context Gap Gate
      _assess_context_gaps() fires once per case
      → if gaps: return clarification questions to user (no retrieval)
      → if no gaps (or raw knowledge query): proceed
  │
  ▼
[2] vascular_mcp_adapter — POST /api/v1/vascular-consult
      Headers: X-API-Key
      Body: { question, history[], guidelines[] }
  │
  ▼
[3] Laravel — ToolController (entry point)
      Validates input (max 2000 chars, history max 20)
      Guardrail: rejects out-of-scope / onboarding queries
  │
  ▼
[4] Laravel — PHIScrubberService
      Removes patient identifiers before any external API call
  │
  ▼
[5] Laravel — GuidelineRouterService
      Azure OpenAI call → selects 1–3 guidelines + expands query
      Produces: guideline keys[], expanded_query, intent
  │
  ▼
[6] Laravel — RetrievalService (orchestration)
      ├─ ClinicalInterpreterService → clinical_frame + must_include_terms
      ├─ GraphRagService → concept expansion (core/related/slots)
      ├─ retrieveDualChunks() → RAGFlow bridge (port 8000) → RAGFlow API
      │     Narrative query: base + GraphRAG terms + interpreter terms
      │     Citation query:  base + core concepts + slot terms
      ├─ [Optional] Focused Recall — if no non-A/non-B chunk returned
      ├─ [Optional] Quality Pass — if below min narrative/citation thresholds
      ├─ GapDetectionService — second pass if missing fields or missing concepts
      ├─ GuidelineAssetService — attaches figures/tables per guideline
      └─ ChunkSelectionService::select() → produces 6 tiered chunk keys
  │
  ▼
[7] Laravel — ToolController (response)
      Returns JSON with all 6 chunk tier keys + assets + query_normalization
  │
  ▼
[8] vascular_mcp_adapter — Response handling
      Emits per-chunk citation popups (__event_emitter__ type:citation)
      Emits progress messages (type:message) if EMIT_STATUS_AS_MESSAGES=true
      Builds llm_output for LLM synthesis (STRICT_TEMPLATE injected by Laravel)
  │
  ▼
[9] OpenWebUI LLM synthesises final answer from llm_output
```

---

## Layer 1 — OpenWebUI (`vascular_mcp_adapter.py`)

### What it does

The adapter is a thin, stateless OpenWebUI tool. It does not maintain conversation state
between sessions. Its responsibilities are:

1. **Context gap gate** — asks the user for missing clinical parameters before retrieval
2. **HTTP call** to the Laravel API with the clinical question and chat history
3. **Citation events** — emits each evidence chunk as a clickable source popup
4. **Progress feedback** — emits status messages during the request lifecycle

### Context Gap Gate

Before any HTTP call is made, `_assess_context_gaps()` runs:

- **Skips** raw guideline knowledge queries (definitions, thresholds, classification criteria)
- **Skips** generic population-level questions ("in patients with...")
- **Fires once per case** — checks assistant message history for `_CASE_GATE_ASSISTANT_RE`
  to detect whether the gate already fired for this case
- **9 scenario rules** in `_CONTEXT_GAP_RULES`:

  | Scenario | Triggers on | Asks about |
  |---|---|---|
  | `aortic_thrombus` | mural thrombus, aortic thrombus | anticoagulation status, thrombus mobility, stroke aetiology |
  | `carotid_stenosis` | carotid stenosis, CEA, CAS | symptomatic status, stenosis degree |
  | `aaa_treatment` | AAA, EVAR, open repair | aneurysm diameter, patient fitness |
  | `dvt_pe` | DVT, PE, pulmonary embolism | provoking factors, first vs recurrent episode |
  | `clti` | CLTI, CLI, critical ischaemia | anatomical workup (CTA/duplex), patient fitness |
  | `svt` | superficial vein thrombosis, SVT | distance from SFJ/SPJ, risk stratification |
  | `type_b_dissection` | type B dissection, TBAD | complicated vs uncomplicated, phase |
  | `ali` | acute limb ischaemia, ALI | Rutherford class + duration, thrombotic vs embolic |
  | `graft_infection` | graft infection, prosthetic infection | clinical signs, prosthesis type + timing |

- **Generic catch-all** — fires for any patient-case query that does not match a specific
  scenario rule (query < 70 chars with no clinical detail markers)

If gaps are found, the adapter returns the clarification questions directly to the user
and **does not call the Laravel API**.

### Valves (configuration)

| Valve | Default | Description |
|---|---|---|
| `VASCULAR_API_BASE_URL` | `https://lavarel.eastus2.cloudapp.azure.com` | Laravel API base URL |
| `VASCULAR_API_KEY` | *(secret)* | Passed as `X-API-Key` header |
| `EMIT_STATUS_AS_MESSAGES` | `true` | Show progress as chat messages |
| `EMIT_STATUS_EVENTS` | `false` | Show progress as status bar events |

### Citation emission

After a successful response, the adapter loops over `llm_citation_chunks` first, then any
additional `ui_citation_chunks` not already emitted, and emits each as a `type:citation`
event. Each popup shows:
- Source document name + page reference
- Recommendation class and level of evidence (e.g. Class I, Level A)
- Full recommendation text (truncated at 500 chars)

### Differences from `vascular_expert.py`

| Feature | `vascular_expert.py` | `vascular_mcp_adapter.py` |
|---|---|---|
| State management | Stateful (same-case follow-up rewrite) | Stateless |
| Query rewrite | Rewrites follow-ups into standalone queries | Sends question as-is |
| Attachments | Handles uploaded documents as case context | Not supported |
| Chunk source | Legacy `citation_chunks` / `narrative_chunks` | `llm_citation_chunks` / `llm_narrative_chunks` (tiered) |
| Context gap gate | Full (with attachment context) | Full (without attachment handling) |
| MCP transport | N/A | N/A (direct HTTP to Laravel) |

---

## Layer 2 — Laravel API

### Entry point: `ToolController`

`POST /api/v1/vascular-consult`

- Validates: `question` (max 2000 chars), `history` (array, max 20 items), optional `guidelines`
- Applies guardrails (rejects out-of-scope or onboarding queries before any LLM call)
- Calls `RetrievalService::retrieve()`
- Returns JSON response (see Response Format below)

### `PHIScrubberService`

Runs on the scrubbed query before any external call (RAGFlow, Azure OpenAI). Removes:
- Patient names, ages over 90, dates, locations, provider names, device IDs

Known minor bugs (low risk, not yet fixed):
- `scrubAgesOver90`: wrong counter key
- `scrubNames`: token-skip may leave last name unredacted
- Unhandled null from `preg_replace_callback`

### `GuidelineRouterService`

Azure OpenAI call that:
1. Selects 1–3 guideline keys from the 14 available ESVS guidelines
2. Expands the query with clinical terminology
3. Detects intent and question type

Special rules:
- `antithrombotic_therapy` is only added when the question explicitly concerns anticoagulation/antithrombotic decisions — not just because stroke or carotid disease is mentioned
- Laravel prunes low-relevance `antithrombotic_therapy` companions for broad carotid-management questions

### `RetrievalService`

Orchestrates the full retrieval pipeline in order:

1. **`ClinicalInterpreterService`** — pre-retrieval framing: `clinical_frame` + `must_include_terms`
2. **`GraphRagService`** — concept expansion: `core_concepts`, `related_concepts`, slots (anatomy/pathology/stage/intervention/imaging/complications)
3. **`retrieveDualChunks()`** — calls RAGFlow bridge for narrative and citation queries separately
4. **Focused Recall** — if no non-A/non-B evidence returned, reruns with relaxed params
5. **Quality Pass** — if below `RAGFLOW_QUALITY_PASS_MIN_NARRATIVE` or `_MIN_CITATION` thresholds, runs high-recall hybrid search (`top_k=80`, capped to prevent timeouts)
6. **`GapDetectionService`** — triggers second-pass retrieval for missing clinical fields or missing GraphRAG concepts
7. **`GuidelineAssetService`** — attaches relevant figures/tables from `config/guideline_assets.php`
8. **`ChunkSelectionService::select()`** — post-retrieval chunk ranking and tier splitting

### `ChunkSelectionService` — Chunk Tiers

Produces 6 keys consumed by the adapter:

| Key | Content | Used by |
|---|---|---|
| `llm_citation_chunks` | Top citation chunks for LLM synthesis | Adapter (primary) |
| `llm_narrative_chunks` | Top narrative chunks for LLM synthesis | Adapter (primary) |
| `ui_citation_chunks` | Broader citation set for display | Adapter (secondary citations) |
| `ui_narrative_chunks` | Broader narrative set for display | Display only |
| `must_include_chunk` | Single highest-scored chunk | Forced into LLM context |
| `intent_profile` | Intent classification dict | Logging / debug |

Tuning parameters in `config/chunk_scoring.php`.

Backward-compat aliases `citation_chunks` → `ui_citation_chunks` and
`narrative_chunks` → `ui_narrative_chunks` remain in the response for old consumers.

### JSON Response Format

```json
{
  "result": "<STRICT_TEMPLATE formatted llm_output>",
  "narrative_chunks": [...],
  "citation_chunks": [...],
  "llm_citation_chunks": [...],
  "llm_narrative_chunks": [...],
  "ui_citation_chunks": [...],
  "ui_narrative_chunks": [...],
  "must_include_chunk": {...},
  "intent_profile": {...},
  "assets": { "figures": [...], "tables": [...] },
  "query_normalization": { "normalized_query": "...", "intent": "...", "key_terms": [...] }
}
```

`result` always contains the STRICT_TEMPLATE narrative (Assessment / Imaging / Indication /
Treatment options / Follow-up / Evidence used), generated by Laravel before the response
is returned.

### Security

- API key validated via `config('services.api.key')` — works after `config:cache`
- `hash_equals()` for timing-safe comparison
- Proxy trust locked to `127.0.0.1` (Caddy on same host)
- Rate limit: 60 req/min per IP

---

## Layer 3 — RAGFlow Bridge + RAGFlow

### RAGFlow Bridge (`ragflow_service/app.py`)

Python FastAPI service on port 8000 of the Laravel VM (135). Acts as a proxy between
Laravel and the RAGFlow API.

- Route: `POST /retrieve` — `RetrieveRequest { question, dataset_ids[], top_k, size, page }`
- Clamps `top_k` to 80 (standard) or 128 (high-recall mode) to prevent timeouts
- Clamps `size` to max 12 chunks per call
- Optional local FlashRank reranker (if enabled)
- Health: `GET /health`, `GET /status`

**Important**: The bridge binds `0.0.0.0:8000`. Port 8000 should be firewalled on the 135 VM
to block external access (verify with `sudo ufw status`).

### RAGFlow

Stores and retrieves ESVS guideline document chunks. Each guideline is a separate dataset.
The bridge translates Laravel's dataset key names (e.g. `carotid_vertebral`) to RAGFlow
dataset IDs via `config/ragflow.php`.

Retrieval modes:
- **Vector search**: `vector_weight=0.5`, `similarity_threshold=0.3`
- **Hybrid (quality pass)**: `keyword=true`, `vector_weight=0.2`, `similarity_threshold=0.2`

---

## Deployment

### Deploy adapter to OpenWebUI

After any local change to `openwebui_tools/vascular_mcp_adapter.py`:

```bash
scp -i ~/ragflownew.pem openwebui_tools/vascular_mcp_adapter.py push_adapter.py azureuser@48.211.217.69:/tmp/
ssh -i ~/ragflownew.pem azureuser@48.211.217.69 "
  sudo docker cp /tmp/vascular_mcp_adapter.py open-webui:/tmp/ &&
  sudo docker cp /tmp/push_adapter.py open-webui:/tmp/ &&
  sudo docker exec open-webui python3 /tmp/push_adapter.py &&
  sudo docker restart open-webui
"
```

`push_adapter.py` runs `INSERT OR REPLACE` into the `tool` table in `/app/backend/data/webui.db`
with the correct `user_id`, `specs` (JSON schema for `consult_vascular_guidelines`), and
`valves` (production URL and API key). The container restart is required because OpenWebUI
caches loaded tool modules in memory.

### Deploy Laravel changes to 135 VM

```bash
# After PHP file changes:
ssh -i ~/LAVAREL.pem azureuser@135.237.148.105 "
  cd /home/azureuser/laravel-ragflow &&
  git pull &&
  sudo systemctl restart laravel-api.service
"

# After .env changes:
ssh -i ~/LAVAREL.pem azureuser@135.237.148.105 "
  cd /home/azureuser/laravel-ragflow &&
  php artisan config:cache &&
  sudo systemctl restart laravel-api.service
"
```

---

## Pending / Known Gaps

- **Cutover complete**: `vascular_mcp_adapter` is now the production tool on `gpt-5-chat`.
  `mcp` (vascular_expert.py) is kept disabled as a fallback — do not delete.
- **No same-case follow-up rewrite**: The adapter sends follow-up questions as-is. Production
  `vascular_expert.py` rewrites them into standalone retrieval queries.
- **No attachment handling**: Uploaded documents are not included in the adapter's gate context.
- **`descending_thoracic_aorta`**: No figures/tables configured in `config/guideline_assets.php`.
- **3 PHIScrubberService bugs**: Minor, low risk — see Security Notes in CLAUDE.md.

---

## Logs

```bash
# Laravel (135 VM):
tail -f /home/azureuser/laravel-ragflow/storage/logs/laravel.log
tail -f /home/azureuser/laravel-ragflow/storage/logs/ragflow-$(date +%Y-%m-%d).log
tail -f /home/azureuser/laravel-ragflow/storage/logs/retrieval-$(date +%Y-%m-%d).log

# OpenWebUI (48 VM):
sudo docker logs open-webui --tail 100 -f
```

Key log prefixes to watch:
- `[GRAPHRAG]` — concept expansion and gap detection
- `[QUALITY PASS]` — high-recall pass running
- `[FOCUSED RECALL]` — non-A/non-B retry
- `[GAP DETECTION]` — second-pass retrieval
- `[CLINICAL INTERPRETER]` — pre-retrieval framing
- `[CHUNK SELECTION]` — tier splitting decisions
