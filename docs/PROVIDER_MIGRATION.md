# Model Provider Migration — Azure → OpenAI + Cohere

_Last performed: 2026-07-21. This document records exactly how the ESVS clinical
RAG system's model providers were changed, so the process is repeatable._

---

## 1. The four model touchpoints

The system uses LLM/model providers in **four independent places**. Changing
providers means changing each one — they do **not** share a single config.

| # | Touchpoint | What it does | Where it's configured | Speaks |
|---|---|---|---|---|
| 1 | **Embeddings** | Encodes the query for vector search; encoded the 72k indexed chunks | RAGFlow (`tenant_llm` rows + `knowledgebase.tenant_embd_id`) | OpenAI-compatible |
| 2 | **Reranking** | Re-scores retrieved chunks | RAGFlow (`RAGFLOW_RERANK_ID` → RAGFlow's Cohere model) | Cohere / OpenAI-compatible |
| 3 | **Planner inference** | Pre-retrieval: normalize + route + interpret + expand (1 LLM call) | Laravel `App\Services\OpenAiLlmClient` + `config/services.php` `services.openai` | OpenAI-compatible |
| 4 | **Synthesis** | Writes the clinical answer from evidence | OpenWebUI "ESVS expert" model → OpenAI connection | OpenAI-compatible |

> **Key architectural fact:** every touchpoint speaks the **OpenAI-compatible
> chat/embeddings API**. That's what makes swapping providers (and, later,
> self-hosting) a matter of changing base URLs + model names + keys.

Data flow: `OpenWebUI (synthesis) → Laravel API → PHIScrubber → Planner (inference)
→ RAGFlow bridge → RAGFlow (embeddings + rerank) → chunks back → synthesis`.

---

## 2. Migration steps (in order)

### 2.1 Embeddings → OpenAI (RAGFlow)

RAGFlow stores the embedding binding **twice**, and retrieval uses the numeric one:

- `knowledgebase.embd_id` — string label, e.g. `text-embedding-3-large@OpenAI` (cosmetic / display)
- **`knowledgebase.tenant_embd_id`** — numeric FK to `tenant_llm.id` — **this is what retrieval actually resolves** (`get_model_config_by_id(kb.tenant_embd_id)`)

**You must update `tenant_embd_id`, not just `embd_id`.**

```sql
-- 1. Register the provider + key in RAGFlow UI (Model Providers) first.
-- 2. Find the new provider's row id:
SELECT id, llm_name FROM tenant_llm WHERE llm_factory='OpenAI' AND model_type='embedding';
-- 3. Repoint every KB (same-model migration = vectors compatible, NO re-index):
UPDATE knowledgebase SET embd_id='text-embedding-3-large@OpenAI', tenant_embd_id=<new_id>
  WHERE tenant_embd_id=<old_azure_id>;
-- 4. Set api_base on the OpenAI embedding rows (RAGFlow's embedding path needs it explicit):
UPDATE tenant_llm SET api_base='https://api.openai.com/v1'
  WHERE llm_factory='OpenAI' AND model_type='embedding';
```

- **Same model on a new provider = identical vectors → no re-index** (`text-embedding-3-large` on Azure and OpenAI are the same weights, 3072-dim).
- **Different model = full re-embed** of all chunks (see self-hosting doc).
- Remove dead provider rows so `llm_name` isn't ambiguous:
  `DELETE FROM tenant_llm WHERE llm_factory='Azure-OpenAI' AND model_type='embedding';`
- **Restart RAGFlow** (`docker restart docker-ragflow-cpu-1`) to drop cached model clients.

### 2.2 Reranking → native Cohere (RAGFlow)

The bridge does **not** rerank — it forwards `rerank_id` to RAGFlow, which calls
the rerank provider using the key in RAGFlow's own config.

```bash
# Laravel .env
RAGFLOW_RERANK_ID=rerank-english-v3.0      # native Cohere model registered in RAGFlow
php artisan config:cache && systemctl reload php8.5-fpm.service
```

(A second, disabled path exists — Laravel-side `BridgeRerankService`, gated by
`BRIDGE_RERANK_ENABLED`, using `BRIDGE_RERANK_API_KEY` / `_ENDPOINT` / `_MODEL`
in Laravel `.env`. Keep those pointing at `https://api.cohere.com/v2/rerank` +
`rerank-english-v3.0` in case you switch to local reranking.)

### 2.3 RAGFlow API token (bridge)

The Python bridge authenticates to RAGFlow with a token in **its own** env file:
`/opt/cg/laravel/app/ragflow_service/.env` → `RAGFLOW_API_KEY` (a `ragflow-…`
token generated in RAGFlow UI → API Keys). If retrieval returns
`code 109 "API key is invalid"`, this token is stale.

```bash
# after updating ragflow_service/.env:
systemctl restart ragflow-bridge.service
```

### 2.4 Planner inference → OpenAI `gpt-5-mini` (Laravel)

All planner LLM calls go through the DI-bound `App\Contracts\LlmClient`. It was
bound to `AzureOpenAiLlmClient` (Azure-only URLs). Migration added
`App\Services\OpenAiLlmClient` and rebound it in `App\Providers\AppServiceProvider`.

Config lives in **`config/services.php` → `services.openai`** (NOT
`config/prism.php` — the Prism package's config merge silently drops custom keys):

```php
'openai' => [
    'url'                 => env('OPENAI_URL', 'https://api.openai.com/v1'),
    'api_key'             => env('OPENAI_API_KEY'),
    'model'               => env('OPENAI_MODEL', env('VASCULAR_AGENT_MODEL', 'gpt-5-mini')),
    'supports_temperature'=> env('OPENAI_SUPPORTS_TEMPERATURE', false),
    'timeout'             => (int) env('OPENAI_TIMEOUT', 30),
    'reasoning_effort'    => env('OPENAI_REASONING_EFFORT', 'minimal'),
],
```

`.env`:
```
OPENAI_URL=https://api.openai.com/v1
OPENAI_API_KEY=sk-...
VIZRA_ADK_DEFAULT_MODEL=gpt-5-mini
VASCULAR_AGENT_MODEL=gpt-5-mini
RETRIEVAL_PLANNER_MAX_TOKENS=3000     # NOT RAGFLOW_PLANNER_MAX_TOKENS
OPENAI_REASONING_EFFORT=minimal        # gpt-5-mini is a reasoning model
GRAPHRAG_LLM_ENABLED=false             # GraphRag had its own Azure call; disabled → deterministic fallback
```

Apply: `php artisan config:cache && systemctl restart php8.5-fpm.service`
(a **restart**, not reload — see gotcha #4).

### 2.5 Synthesis → OpenAI `gpt-5-chat-latest` (OpenWebUI)

The "ESVS expert" model (`model` table, id `gpt-5-chat`) had `base_model_id=None`
and mapped to a model literally named `gpt-5-chat` served by Azure. OpenAI serves
it as `gpt-5-chat-latest`:

```sql
UPDATE model SET base_model_id='gpt-5-chat-latest' WHERE id='gpt-5-chat';
```
Add the OpenAI connection in **Admin Panel → Settings → Connections**
(`https://api.openai.com/v1` + key), then `docker restart open-webui`.
System prompt + tool binding (`vascular_mcp_adapter`) are preserved automatically.

---

## 3. Gotchas (all hit and resolved on 2026-07-21)

1. **`tenant_embd_id` (numeric FK), not `embd_id` (string)** drives retrieval embeddings.
2. **OpenAI project model access** — a project-scoped key (`sk-proj-…`) 403s unless the model is enabled in that project's allow-list (Project → Limits). The Default project usually has all models. Test directly: `POST api.openai.com/v1/embeddings`.
3. **Prism `mergeConfigFrom` drops app-custom keys** in `config/prism.php` `providers.openai` — put planner settings in `config/services.php` instead.
4. **opcache**: config changes need `php artisan config:cache`; **PHP class/code changes need a full `systemctl restart php8.5-fpm.service`** — `reload` does not reload changed classes.
5. **gpt-5-mini is a reasoning model** → set `reasoning_effort=minimal` (else ~15s + it burns the token budget on reasoning); it also occasionally emits JSON one `}` short → `PreRetrievalPlannerService::parseJson` now repairs unbalanced brackets (`closeUnbalanced`).
6. **Env var name**: planner token budget is `RETRIEVAL_PLANNER_MAX_TOKENS` (config `ragflow.planner.max_tokens`), not `RAGFLOW_PLANNER_MAX_TOKENS`.
7. **GraphRag** has its own embedded Azure LLM call — disable with `GRAPHRAG_LLM_ENABLED=false` (deterministic concept-expansion fallback is good), or route it to the new provider later.

---

## 4. Verification

```bash
# Embeddings + rerank (bridge → RAGFlow), expect code=0 with chunks:
#   POST http://localhost:8000/retrieval  (header X-Bridge-Secret) {question,dataset_ids,rerank_id}
# Full pipeline (planner + retrieval + rerank):
curl -s http://127.0.0.1:8001/api/v1/vascular-consult \
  -H "X-API-Key: $API_SECRET_KEY" -H 'Content-Type: application/json' \
  -d '{"question":"When is repair indicated for an asymptomatic AAA based on diameter?","history":[]}'
# Planner health in the log — want plan_applied:true, no "alexiouv"/azure errors:
grep -E 'PRE-RETRIEVAL TIMING|Merged pre-retrieval plan|PLANNER' \
  storage/logs/retrieval-$(date +%Y-%m-%d).log | tail
```

## 5. Rollback

All edits were backed up: Laravel `.env.bak.*`, `ragflow_service/.env.bak.*`,
`/root/kb_embd_backup_*.tsv` (KB embedding bindings),
`/root/azure_embed_rows_*.tsv` (deleted Azure rows),
`webui.db.bak.*` (inside the open-webui container).
