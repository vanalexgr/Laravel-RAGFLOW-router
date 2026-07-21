# Configuration Reference

Every tunable in the Laravel service. Reference this when onboarding, changing
behaviour, or debugging. For the model-provider wiring specifically, see
[`PROVIDER_MIGRATION.md`](PROVIDER_MIGRATION.md).

## How configuration is loaded

- Runtime settings come from **`/opt/cg/laravel/app/.env`** (not committed;
  `.env.example` is the template).
- `.env` values are consumed by **`config/*.php`** files via `env()`. Application
  code reads **`config('…')`**, never `env()` directly.
- **Laravel caches config.** After any `.env` or `config/*.php` change you must run:
  ```bash
  php artisan config:cache            # from /opt/cg/laravel/app
  systemctl reload php8.5-fpm.service # picks up the new cached config
  ```
- The Python **bridge** has its **own** env file: `ragflow_service/.env`
  (loaded by `ragflow-bridge.service`). It is independent of the Laravel `.env`.

---

## 1. Core / auth

| Var | Purpose |
|---|---|
| `APP_KEY` | Laravel app key (`php artisan key:generate`). |
| `APP_ENV` / `APP_DEBUG` | `production` / `false` in prod. |
| `API_SECRET_KEY` | **The API key** for `POST /api/v1/*`. Clients send it as `Authorization: Bearer <key>` or `X-API-Key`. Validated by `ValidateApiKey` middleware with `hash_equals` (`config('services.api.key')`). |

## 2. Model providers

Full detail + rationale in [`PROVIDER_MIGRATION.md`](PROVIDER_MIGRATION.md).
The planner LLM client (`App\Services\OpenAiLlmClient`) reads
**`config/services.php` → `services.openai`** (NOT `config/prism.php`).

| Var | Purpose | Production value |
|---|---|---|
| `OPENAI_URL` | OpenAI-compatible base URL | `https://api.openai.com/v1` |
| `OPENAI_API_KEY` | OpenAI key (planner inference) | `sk-…` |
| `OPENAI_MODEL` / `VASCULAR_AGENT_MODEL` | Planner model | `gpt-5-mini` |
| `OPENAI_REASONING_EFFORT` | Reasoning effort for gpt-5 reasoning models; empty = omit | `minimal` |
| `OPENAI_SUPPORTS_TEMPERATURE` | Send `temperature`? Reasoning models reject it | `false` |
| `OPENAI_TIMEOUT` | Planner HTTP timeout (s) | `30` |
| `AZURE_OPENAI_*` | **Legacy** — unused after migration; safe to remove | (empty) |
| `VIZRA_ADK_*` | Vizra ADK agent config (dormant; not the production path) | — |

> Embeddings (`text-embedding-3-large`) and reranking (Cohere `rerank-english-v3.0`)
> are configured **inside RAGFlow**, not here. See PROVIDER_MIGRATION §2.1–2.2.

## 3. RAGFlow retrieval tuning (`config/ragflow.php`)

| Var | Meaning | Prod |
|---|---|---|
| `RAGFLOW_REQUEST_TIMEOUT` | Laravel→bridge total timeout (s). Keep `> 2×` upstream for citation retry headroom | `60` |
| `RAGFLOW_CONNECT_TIMEOUT` | TCP connect timeout (s) | `5` |
| `RAGFLOW_UPSTREAM_TIMEOUT` | Bridge→RAGFlow timeout (s) | `25` |
| `RAGFLOW_TOP_K` | Reranker candidate pool (base) | `80` |
| `RAGFLOW_CITATION_TOP_K` | Candidate pool for the citation branch | `48` |
| `RAGFLOW_TOP_K_CEILING` | Hard cap on candidate pool | `96` |
| `RAGFLOW_LEAN_TOP_K` | Pool for lean (knowledge) queries | `64` |
| `RAGFLOW_SINGLE_CASE_TOP_K` | Pool for single-case queries | `96` |
| `RAGFLOW_NARRATIVE_MAX` | Max narrative chunks returned | `10` |
| `RAGFLOW_CITATION_MAX` | Max citation chunks returned | `4` |
| `RAGFLOW_RERANK_ID` | Rerank model name forwarded to RAGFlow (empty = no rerank) | `rerank-english-v3.0` |
| `RAGFLOW_QUERY_EXPANSION` | Legacy expansion (adds ~1–2s) | `false` |
| `RAGFLOW_USE_KG` | Enable RAGFlow knowledge graph | `false` |
| `RAGFLOW_USE_BRIDGE` / `RAGFLOW_BRIDGE_URL` / `RAGFLOW_BRIDGE_SECRET` | Bridge routing + shared secret (sent as `X-Bridge-Secret`) | on / `http://localhost:8000` / *(secret)* |
| `RAGFLOW_QUALITY_PASS_*` | Second-pass retrieval when too few citation chunks return (`_NARRATIVE_MAX`, `_CITATION_MAX`, `_MIN_CITATION`, `_TOP_K`) | see `.env` |
| `RAGFLOW_BLUE_TOE_BOOST_ENABLED` | Targeted term boosts for blue-toe / shaggy-aorta queries | `true` |

## 4. Pre-retrieval planner (`config/ragflow.php` → `planner`)

The merged planner replaces four sequential legacy LLM calls with one.

| Var | Meaning | Prod |
|---|---|---|
| `RETRIEVAL_PLANNER_MERGED_ENABLED` | Use the merged planner | `true` |
| `RETRIEVAL_PLANNER_SHADOW` | Log the plan without applying it (A/B) | `false` |
| `RETRIEVAL_PLANNER_MODEL` | Override planner model (empty = `services.openai.model`) | (empty) |
| `RETRIEVAL_PLANNER_MAX_TOKENS` | **`max_completion_tokens`** for the plan JSON (raise if truncated) | `3000` |
| `RETRIEVAL_PLANNER_TEMPERATURE` | Only sent if `OPENAI_SUPPORTS_TEMPERATURE=true` | `0` |

## 5. GraphRAG concept expansion (`config/graphrag.php`)

| Var | Meaning | Prod |
|---|---|---|
| `GRAPHRAG_ENABLED` | Master switch | see `.env` |
| `GRAPHRAG_LLM_ENABLED` | Use an LLM for concept expansion. **`false` in prod** — falls back to a good deterministic expander; its own LLM call was Azure-only | `false` |
| `GRAPHRAG_INTENT_ENABLED` | Intent classification | `true` |
| `GRAPHRAG_MAX_*` | Candidate / core / related / query-term caps | see `.env` |

## 6. Other pipeline stages

| Area | Config file | Key vars |
|---|---|---|
| Clinical interpreter (pre-retrieval framing) | `config/clinical_interpreter.php` | `CLINICAL_INTERPRETER_ENABLED`, `_MAX_TERMS`, `_TIMEOUT` |
| Taxonomy expansion (ESVS term index) | `config/taxonomy.php` | `TAXONOMY_EXPANSION_ENABLED` (off), `_PATH`, `_MAX_*` |
| Partial-evidence synthesis | — | `ALLOW_PARTIAL_EVIDENCE_ANSWERS=true` |
| Gap detection | `config/gap_detection.php` | second-pass thresholds |
| Chunk scoring/selection | `config/chunk_scoring.php` | scoring weights (authoritative in `ChunkSelectionService`) |
| PHI scrubbing | `config/phi.php` | city list, age threshold |
| Guideline → figure/table assets | `config/guideline_assets.php` | disk, url prefix, manifest, per-guideline assets |
| Guideline dataset IDs | `config/guidelines.php` | key → RAGFlow `dataset_id` map |

## 7. Bridge-side rerank (standby — `config/ragflow.php` → `bridge_rerank`)

Disabled path where **Laravel** reranks locally instead of RAGFlow. Kept ready.

| Var | Prod |
|---|---|
| `BRIDGE_RERANK_ENABLED` | `false` (standby) |
| `BRIDGE_RERANK_ENDPOINT` | `https://api.cohere.com/v2/rerank` |
| `BRIDGE_RERANK_API_KEY` | Cohere key |
| `BRIDGE_RERANK_MODEL` | `rerank-english-v3.0` |
| `BRIDGE_RERANK_TOP_N` / `_TIMEOUT` | `20` / `20` |

## 8. Config files at a glance

`app auth cache chunk_scoring clinical_interpreter database filesystems
gap_detection graphrag guideline_assets guidelines logging mail phi prism queue
ragflow router_abbreviations services session taxonomy vizra-adk`

The domain-specific ones to know: **`ragflow.php`** (retrieval + planner + bridge),
**`services.php`** (`services.api.key`, `services.openai`, legacy `azure_openai`),
**`graphrag.php`**, **`clinical_interpreter.php`**, **`gap_detection.php`**,
**`guideline_assets.php`**, **`guidelines.php`**, **`phi.php`**,
**`chunk_scoring.php`**. `prism.php` and `vizra-adk.php` are legacy/dormant.

> ⚠️ The default `.env.example` reflects an earlier (Azure, planner-off) baseline;
> the **Production value** columns above are authoritative for the running system.
