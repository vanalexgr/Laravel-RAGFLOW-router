# Laravel RAGFlow Router — ESVS Vascular Guidelines API

A Laravel 12 API that routes clinical questions to ESVS (European Society for
Vascular Surgery) guideline documents in RAGFlow, retrieves graded evidence
chunks, and returns structured clinical responses for synthesis by an LLM.

### Documentation map

| Doc | What it covers |
|---|---|
| [`docs/SYSTEM_ARCHITECTURE.md`](docs/SYSTEM_ARCHITECTURE.md) | Components, deployment topology, request lifecycle, services & endpoints |
| [`docs/CONFIGURATION.md`](docs/CONFIGURATION.md) | Every env var and config file |
| [`docs/OPERATIONS.md`](docs/OPERATIONS.md) | Deploy, restart matrix, logs, troubleshooting, backups |
| [`docs/SYSTEM_PIPELINE.md`](docs/SYSTEM_PIPELINE.md) | Retrieval pipeline internals (planner, dual retrieval, gap detection) |
| [`docs/VASCULAR_MCP_ADAPTER.md`](docs/VASCULAR_MCP_ADAPTER.md) | The production OpenWebUI tool (gate, query rewrite, templates) |
| [`docs/PROVIDER_MIGRATION.md`](docs/PROVIDER_MIGRATION.md) | Model providers and how to change them |
| [`docs/SELF_HOSTED_MODELS.md`](docs/SELF_HOSTED_MODELS.md) | Going fully local (vLLM/Ollama/TEI) — for the ISI deployment |
| [`docs/HIPAA_COMPLIANCE.md`](docs/HIPAA_COMPLIANCE.md) | PHI de-identification, safeguards, BAA requirements |
| [`docs/GUIDELINE_ASSETS.md`](docs/GUIDELINE_ASSETS.md) | Figure/table asset attachment |
| [`CLAUDE.md`](CLAUDE.md) | Condensed working guide (infra, key files, tuning) |

---

## Architecture

```
OpenWebUI (user chat, "ESVS expert" model = gpt-5-chat-latest)
  └─ Tool: openwebui_tools/vascular_mcp_adapter.py  (stored in webui.db, id=vascular_mcp_adapter)
       ├─ context gate — asks for missing clinical details on patient cases before retrieval
       └─ POST /api/v1/vascular-consult  (Laravel)
            ├─ ValidateApiKey + PHI scrubbing
            ├─ Pre-retrieval planner (1 LLM call: normalize + route + interpret + expand)
            ├─ RAGFlow retrieval via the bridge (127.0.0.1:8000 → RAGFlow :9380)
            ├─ Reranking (Cohere, RAGFlow-side)
            ├─ Gap detection (optional second pass) + guideline asset mapping
            └─ Returns: narrative_chunks, citation_chunks, assets, query_normalization
  └─ LLM synthesises the final clinical answer from the returned evidence
```

**Model providers** (migrated off Azure — see `docs/PROVIDER_MIGRATION.md`):
- Embeddings: OpenAI `text-embedding-3-large` / `ada-002` (in RAGFlow)
- Reranking: Cohere `rerank-english-v3.0` (RAGFlow-side)
- Planner inference: OpenAI `gpt-5-mini` (Laravel `OpenAiLlmClient`)
- Synthesis: OpenAI `gpt-5-chat-latest` (OpenWebUI)

**Infrastructure:** single all-in-one **Hetzner VM** `178.105.193.206`
(`ssh -i ~/.ssh/id_ed25519 root@178.105.193.206`). Laravel at
`/opt/cg/laravel/app`; RAGFlow / MySQL / valkey / OpenWebUI run as Docker
containers on the same host. See `CLAUDE.md` for the full component/port map.
The old Azure VMs (`135.237.148.105`, `48.211.217.69`) are decommissioned.

---

## The production tool

The live client is the OpenWebUI tool **`vascular_mcp_adapter.py`** (DB id
`vascular_mcp_adapter`). It runs from the `webui.db` `tool` table, not the
filesystem — after editing it locally you must push to the DB (deploy command in
`CLAUDE.md`). The context gate is **built into** the adapter: for patient cases
missing clinical details it returns a clarification request (a Markdown string
starting with `**Additional information needed**`) instead of retrieving; the
model presents the questions, then calls again with the completed info (prior
turns passed in `history`).

> A standalone FastMCP server also exists under `vascular_mcp/` (alternate
> interface, **not** the production path — cutover never performed). Treat the
> OpenWebUI adapter as authoritative.

---

## Guideline Datasets

| Key | Dataset |
|---|---|
| `aortic_arch` | Aortic Arch |
| `descending_thoracic_aorta` | Descending Thoracic Aorta *(no figures/tables configured)* |
| `abdominal_aortic_aneurysm` | Abdominal Aortic Aneurysm (AAA) |
| `mesenteric_renal` | Mesenteric and Renal Artery |
| `asymptomatic_pad` | Asymptomatic PAD |
| `clti` | Chronic Limb-Threatening Ischaemia (CLTI) |
| `acute_limb_ischaemia` | Acute Limb Ischaemia (ALI) |
| `carotid_vertebral` | Carotid and Vertebral Artery |
| `venous_thrombosis` | Venous Thrombosis (DVT / PE / SVT) |
| `chronic_venous_disease` | Chronic Venous Disease |
| `antithrombotic_therapy` | Antithrombotic Therapy *(add only when anticoagulation is the actual question)* |
| `vascular_trauma` | Vascular Trauma |
| `vascular_graft_infections` | Vascular Graft Infections |
| `vascular_access` | Vascular Access |

---

## Context Gate — covered scenarios

The gate checks for missing clinical parameters before retrieval and asks
clarification questions when needed. It fires once per case (not per turn), and
only when required parameters are absent; a clearly different case reopens it.
The authoritative rule set lives in `openwebui_tools/vascular_mcp_adapter.py`
(`_CONTEXT_GAP_RULES`) — a representative subset:

| Scenario | Asks about |
|---|---|
| `carotid_stenosis` | Symptomatic status, stenosis degree (NASCET %) |
| `aaa_treatment` | Aneurysm diameter, patient fitness |
| `dvt_pe` | Provoking factors, first vs recurrent, DVT location (proximal vs distal) |
| `ali` | Rutherford class + duration, thrombotic vs embolic |
| `clti` | Anatomical workup (duplex/CTA), patient fitness |
| `type_b_dissection` | Complicated vs uncomplicated, phase (acute/subacute/chronic) |
| `graft_infection` | Clinical signs, prosthesis type + timing |

Raw knowledge questions (definitions, thresholds, population-level guideline
questions) skip the gate and retrieve immediately.

---

## Key Files

| File | Purpose |
|---|---|
| `openwebui_tools/vascular_mcp_adapter.py` | **Production** OpenWebUI tool (context gate, query rewrite, STRICT_TEMPLATE) |
| `openwebui_tools/push_adapter.py` | Deploy script — writes the adapter into `webui.db` (id `vascular_mcp_adapter`) |
| `routes/api.php` | Laravel route: `POST /api/v1/vascular-consult` |
| `app/Services/RetrievalService.php` | Retrieval pipeline orchestration |
| `app/Services/PreRetrievalPlannerService.php` | Merged pre-retrieval planner (1 LLM call) |
| `app/Services/OpenAiLlmClient.php` | OpenAI-compatible LLM client (planner inference) |
| `app/Services/RAGFlow/DatasetResource.php` | `retrieve_dual` calls to the bridge |
| `ragflow_service/app.py` | Python RAGFlow bridge (FastAPI) |
| `config/ragflow.php` | Retrieval tuning parameters |
| `docs/PROVIDER_MIGRATION.md` | How model providers are wired and how to change them |
| `docs/SELF_HOSTED_MODELS.md` | Going fully local (vLLM/Ollama/TEI) |

---

## Known Gaps

- `descending_thoracic_aorta` has no figures/tables in `config/guideline_assets.php`
- 3 minor bugs in `PHIScrubberService` (low risk — see `CLAUDE.md`)
- Old plaintext secrets remain in git history (pre-redaction); rotate if the repo is shared
