# Going Fully Self-Hosted — Local Models Walkthrough

_Audience: ISI Athens deployment partners. Goal: replace **all** cloud model
providers (OpenAI, Cohere) with **self-hosted local models**, so no clinical data
(PHI) ever leaves your infrastructure and there is no per-token cost._

Read [`PROVIDER_MIGRATION.md`](PROVIDER_MIGRATION.md) first — it explains the four
model touchpoints. This document explains how to point each one at a **local**
model instead of a cloud one.

---

## 0. Why this is mostly a URL change

Every model touchpoint in this system already speaks the **OpenAI-compatible
API** (`/v1/chat/completions`, `/v1/embeddings`, `/v1/rerank`). Local serving
stacks expose exactly that API. So "self-hosting" is:

1. Stand up local inference servers that expose OpenAI-compatible endpoints.
2. Change each touchpoint's **base URL + model name + key** (key becomes a dummy).
3. **Re-index the knowledge base once** (only because the embedding model changes).

The one unavoidable heavy step is #3 — embeddings are not interchangeable across
different models, so all ~72k chunks must be re-embedded with the local model.

---

## 1. Recommended local stack

| Touchpoint | Serve with | Suggested model | Notes |
|---|---|---|---|
| **Chat / inference** (planner + synthesis) | **vLLM** (GPU, best throughput) or **Ollama** (simplest) | `Qwen2.5-72B-Instruct`, `Llama-3.3-70B-Instruct`, or a medical FT (`OpenBioLLM-70B`, `Meditron`) | Needs a GPU; see sizing |
| **Embeddings** | **HF Text-Embeddings-Inference (TEI)** or **Infinity** | `BAAI/bge-m3` (multilingual, 1024-dim) or `Qwen3-Embedding-8B` | Small; CPU-ok but GPU faster. **Forces re-index** |
| **Reranking** | **TEI** / **Infinity**, or the bridge's built-in **FlashRank**, or Laravel `BridgeRerankService` | `BAAI/bge-reranker-v2-m3` | Small; runs on CPU |

All of the above (vLLM, Ollama, TEI, Infinity) expose OpenAI-compatible routes.
A single **LiteLLM** proxy in front of them is optional but convenient (one base
URL, unified keys, model aliasing).

### Hardware sizing (chat model is the driver)
- **70B class, 4-bit quant (AWQ/GPTQ):** ~40–48 GB VRAM → 1× A6000 (48 GB) / L40S / 2× 3090.
- **32B class (Qwen2.5-32B):** ~20–24 GB → 1× 4090/A5000. Good quality/cost balance.
- **7–14B class:** fits 16 GB; fastest, lower clinical reasoning quality.
- **Embedding + rerank models:** ~2–6 GB each; can share a small GPU or run CPU.
- Start with a 32B chat model on a single 48 GB GPU; scale up if synthesis quality needs it.

---

## 2. Step-by-step

### Step 1 — Serve the chat model (OpenAI-compatible)

**Option A — vLLM (recommended for production):**
```bash
python -m vllm.entrypoints.openai.api_server \
  --model Qwen/Qwen2.5-32B-Instruct-AWQ \
  --served-model-name esvs-chat \
  --host 0.0.0.0 --port 8100 --max-model-len 32768 --gpu-memory-utilization 0.90
# → OpenAI-compatible at http://<host>:8100/v1  (model id: "esvs-chat")
```
**Option B — Ollama (simplest):**
```bash
ollama pull qwen2.5:32b-instruct
ollama serve            # OpenAI-compatible at http://<host>:11434/v1  (model id: "qwen2.5:32b-instruct")
```

### Step 2 — Point the **planner** (Laravel) at it
`/opt/cg/laravel/app/.env`:
```
OPENAI_URL=http://<gpu-host>:8100/v1
OPENAI_API_KEY=sk-local-dummy          # any non-empty string; local servers ignore it (or set vLLM --api-key)
VASCULAR_AGENT_MODEL=esvs-chat
OPENAI_REASONING_EFFORT=              # LEAVE EMPTY for non-reasoning open models (they reject reasoning_effort)
```
```bash
php artisan config:cache && systemctl restart php8.5-fpm.service
```
> The `closeUnbalanced` JSON repair already added to the planner helps with open
> models that occasionally emit slightly malformed JSON. If a model is very
> unreliable at JSON, raise `RETRIEVAL_PLANNER_MAX_TOKENS` and consider a
> few-shot example in `PlannerPrompt`.

### Step 3 — Point **synthesis** (OpenWebUI) at it
Admin Panel → Settings → Connections → **replace** the OpenAI connection URL with
`http://<gpu-host>:8100/v1` (+ dummy key). Then set the ESVS expert base model:
```sql
UPDATE model SET base_model_id='esvs-chat' WHERE id='gpt-5-chat';
```
`docker restart open-webui`. (Also restrict the connection's `model_ids` to
`["esvs-chat"]` so only it appears — see PROVIDER_MIGRATION §2.5 pattern.)

### Step 4 — Point **RAGFlow's chat model** at it (for RAPTOR/keyword features)
RAGFlow UI → Model Providers → add an **OpenAI-API-Compatible** chat model:
- Base URL `http://<gpu-host>:8100/v1`, model `esvs-chat`, any key.
- Set it as the tenant default chat model.

### Step 5 — Serve embeddings + **RE-INDEX** (the heavy step)
```bash
# TEI for embeddings:
docker run --gpus all -p 8101:80 ghcr.io/huggingface/text-embeddings-inference:latest \
  --model-id BAAI/bge-m3
# → OpenAI-compatible embeddings at http://<host>:8101/v1
```
In RAGFlow UI → Model Providers → add **OpenAI-API-Compatible** embedding model
(`bge-m3`, base `http://<host>:8101/v1`). Then, because the vector space changes:

1. For each knowledge base, set its embedding model to `bge-m3` (RAGFlow will not
   let you change it on a populated KB — you must **re-create the KB** or use a new one).
2. **Re-parse / re-embed every document** in each KB (RAGFlow "re-parse"/"re-run").
3. Verify chunk counts return and retrieval works (`code=0`, chunks > 0).

> ⚠️ This is the only step that requires reprocessing all documents. Budget time
> for ~72k chunks. Dimensions differ (bge-m3 = 1024 vs 3072), so old vectors are
> discarded — there is no shortcut.

### Step 6 — Serve the reranker
```bash
docker run --gpus all -p 8102:80 ghcr.io/huggingface/text-embeddings-inference:latest \
  --model-id BAAI/bge-reranker-v2-m3
```
Register it in RAGFlow (OpenAI-API-Compatible rerank), set
`RAGFLOW_RERANK_ID=bge-reranker-v2-m3` in Laravel `.env`, `config:cache` + reload.
**Zero-infra alternative:** the bridge already bundles **FlashRank** — send
`rerank_id="local"` to rerank on-box with no extra service (lower quality but free).

### Step 7 — Verify end-to-end
Same checks as `PROVIDER_MIGRATION.md §4`: bridge `/retrieval` → `code=0`;
`/api/v1/vascular-consult` returns chunks; log shows `plan_applied:true` and **no
external hostnames**.

---

## 3. Privacy / PHI note (why this matters clinically)

Once all four touchpoints are local, **no query, no chunk, and no PHI leaves the
box**. The `PHIScrubberService` becomes defense-in-depth rather than the only
barrier. This is the primary reason to self-host for a clinical deployment.
Confirm the firewall still exposes only 22/80/443 and that the model servers bind
to an internal interface / are firewalled.

---

## 4. Migration order & rollback

- Do it **one touchpoint at a time**, verifying after each, so you can isolate
  regressions: chat first (planner + synthesis + RAGFlow chat), then rerank, then
  embeddings+re-index last (most disruptive).
- Keep the cloud provider configured but unused during bring-up so you can flip
  back by restoring the `.env` / DB values (all changes are backed up — see
  PROVIDER_MIGRATION §5).
- Quality-gate synthesis with the existing batch validation suite before cutover;
  open models may need prompt tuning versus the current gpt-5-chat prompts.

---

## 5. Quick reference — what each touchpoint needs

| Touchpoint | File / location | Fields to change |
|---|---|---|
| Planner | `/opt/cg/laravel/app/.env` + `config/services.php` | `OPENAI_URL`, `OPENAI_API_KEY`, `VASCULAR_AGENT_MODEL`, `OPENAI_REASONING_EFFORT` |
| Synthesis | OpenWebUI Admin→Connections + `model` table | connection URL/key, `base_model_id` |
| RAGFlow chat | RAGFlow UI Model Providers | base URL, model, tenant default |
| Embeddings | RAGFlow UI + `knowledgebase` | provider, `tenant_embd_id`, **re-index** |
| Rerank | RAGFlow UI + Laravel `.env` | `RAGFLOW_RERANK_ID` (or `rerank_id="local"` FlashRank) |
