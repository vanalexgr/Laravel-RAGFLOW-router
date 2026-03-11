# Laravel RAGFlow Router — Claude Session Guide

## What This Project Does

A Laravel 12 API that acts as a clinical decision-support router: it receives questions from OpenWebUI (via a Python tool), routes them to the correct ESVS (European Society for Vascular Surgery) guideline documents in RAGFlow, retrieves evidence chunks, and returns structured clinical responses for synthesis by an LLM.

---

## Infrastructure

| Component | Location | Access |
|---|---|---|
| Laravel API | Azure VM `135.237.148.105` | `ssh -i ~/LAVAREL.pem azureuser@135.237.148.105` |
| OpenWebUI + RAGFlow | Azure VM `48.211.217.69` | `ssh -i ~/ragflownew.pem azureuser@48.211.217.69` |
| Laravel app root | `/home/azureuser/laravel-ragflow/` | on 135 VM |
| OpenWebUI container | `open-webui` (Docker) | on 48 VM |
| OpenWebUI DB | `/app/backend/data/webui.db` (SQLite, inside container) | |
| RAGFlow bridge | `localhost:8000` on 135 VM | Python FastAPI in `/home/azureuser/laravel-ragflow/ragflow_service/app.py` |

### Services
```bash
# Laravel API
sudo systemctl status laravel-api.service
sudo systemctl restart laravel-api.service

# After any .env change
php artisan config:cache     # from /home/azureuser/laravel-ragflow/

# OpenWebUI
sudo docker ps
sudo docker restart open-webui

# Bridge (RAGFlow proxy)
# Runs as part of laravel-api.service or a separate process; check with:
sudo systemctl status ragflow-bridge.service
```

---

## Key Files

### Laravel Backend
| File | Purpose |
|---|---|
| `routes/api.php` | Single route: `POST /api/v1/vascular-consult` |
| `app/Http/Controllers/ToolController.php` | Request entry point; input validation (max:2000, history max:20) |
| `app/Http/Middleware/ValidateApiKey.php` | API key auth via `config('services.api.key')`, `hash_equals()` |
| `app/Services/RetrievalService.php` | Orchestrates the full retrieval pipeline |
| `app/Services/GuidelineRouterService.php` | LLM-based guideline selection + query expansion |
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
| `openwebui_tools/vascular_expert.py` | The OpenWebUI tool (also stored in `webui.db` `tool` table, id=`mcp`) |

**IMPORTANT**: The live tool runs from the SQLite DB, not from the filesystem. After editing `vascular_expert.py` locally, always push to the DB:
```bash
scp -i ~/ragflownew.pem openwebui_tools/vascular_expert.py azureuser@48.211.217.69:/tmp/vascular_expert_new.py
ssh -i ~/ragflownew.pem azureuser@48.211.217.69 "
  sudo docker cp /tmp/vascular_expert_new.py open-webui:/tmp/vascular_expert_new.py &&
  sudo docker exec open-webui python3 /tmp/push_tool_content.py
"
```
The `/tmp/push_tool_content.py` script inside the container reads `vascular_expert_new.py` and writes it to the DB.
OpenWebUI also caches the loaded tool module in memory, so DB updates are not live until the `open-webui` container is restarted:
```bash
ssh -i ~/ragflownew.pem azureuser@48.211.217.69 "sudo docker restart open-webui"
```

---

## Architecture Flow

```
OpenWebUI (user message)
  → LLM decides to call consult_vascular_guidelines tool
  → vascular_expert.py (mcp tool in OpenWebUI)
      ├─ [AGENTIC CHECK] _assess_context_gaps() — asks for missing params before retrieval
      ├─ POST /api/v1/vascular-consult (Laravel, 135 VM)
      │     ├─ ValidateApiKey middleware
      │     ├─ PHIScrubberService (scrub before external calls)
      │     ├─ GuidelineRouterService (Azure OpenAI → selects guidelines + expands query)
      │     ├─ RetrievalService
      │     │     ├─ retrieveDualChunks() → RAGFlow bridge (port 8000) → RAGFlow API
      │     │     ├─ Quality pass (if < min_citation chunks returned)
      │     │     ├─ GapDetectionService (optional second pass)
      │     │     └─ GuidelineAssetService (figures/tables)
      │     └─ Returns: narrative_chunks, citation_chunks, assets, query_normalization
      └─ Formats llm_output with STRICT_TEMPLATE (Assessment/Imaging/Treatment/etc.)
  → LLM synthesises final answer from llm_output
```

---

## Retrieval Tuning (`.env` on 135 VM)

Key env vars affecting performance:
```
RAGFLOW_QUALITY_PASS_ENABLED=true
RAGFLOW_QUALITY_PASS_MIN_CITATION=2     # trigger quality pass if < 2 citation chunks
RAGFLOW_QUALITY_PASS_TOP_K=80           # was 256 — reduced to prevent 60s timeouts
RAGFLOW_HIGH_RECALL_TOP_K_CEILING=128   # was 1024
RAGFLOW_QUALITY_PASS_MIN_NARRATIVE=8
```

3-guideline queries previously timed out (90–102s) because quality pass used `top_k=256` → 60s per RAGFlow call. Now capped at 80.

---

## OpenWebUI Tool: Key Behaviours

### Agentic Context Check
Before calling the backend, `_assess_context_gaps()` checks for missing clinical parameters in patient-case presentations. If ≥ 2 key parameters are absent, the tool asks clarifying questions instead of retrieving.

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

**Generic catch-all**: Any patient-presentation question < 70 chars with no clinical detail markers triggers a generic parameter request.

### STRICT_TEMPLATE
Always enabled. When `llm_total_chunks > 0`, injects structured template into `llm_output`:
- Assessment / Imaging / Indication / Treatment options / Follow-up / Evidence used
- + Clinical Decision Summary + Perioperative Risk (when `_requires_clinical_decision_summary()` = True)

### Scope Filter
LLM is instructed not to cite recommendations for different procedures than the case (e.g., TEVAR recs for a mural thrombus question).

### Guideline Selection Rules
The LLM selects guidelines at tool-call time from the docstring. Key rule: add `antithrombotic_therapy` whenever anticoagulation decisions are involved.

---

## Logs

```bash
# On 135 VM:
tail -f /home/azureuser/laravel-ragflow/storage/logs/laravel.log
tail -f /home/azureuser/laravel-ragflow/storage/logs/ragflow-$(date +%Y-%m-%d).log
tail -f /home/azureuser/laravel-ragflow/storage/logs/retrieval-$(date +%Y-%m-%d).log

# On 48 VM (OpenWebUI):
sudo docker logs open-webui --tail 100 -f
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
- PHI scrubbed before any external API call (RAGFlow, Azure OpenAI)
- Rate limit: 60 req/min per IP on `/api/v1/vascular-consult`

---

## Known Gaps / TODO

- `descending_thoracic_aorta` has **no figures/tables** configured in `config/guideline_assets.php`
- `PHIScrubberService` has 3 minor bugs (wrong counter key in `scrubAgesOver90`, token-skip in `scrubNames`, unhandled null from `preg_replace_callback`) — low risk, not yet fixed
- OpenWebUI follow-up question suggestions: disabled globally (`task.follow_up.enable = false` in config DB)
