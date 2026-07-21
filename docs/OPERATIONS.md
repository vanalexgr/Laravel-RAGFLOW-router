# Operations Runbook

Deploy, restart, monitor, and troubleshoot the production system. All commands run
on the Hetzner VM unless noted: `ssh -i ~/.ssh/id_ed25519 root@178.105.193.206`,
app root `/opt/cg/laravel/app`.

---

## 1. Deploy

The server is **not** a git checkout — it is an rsync target. Deploy the
**git-tracked files only** (never `--delete`; the deploy dir contains unrelated
files, and a bare rsync would also push local untracked cruft).

```bash
# From the local repo (/home/vga/LAVAREL/Laravel-RAGFLOW-router):
git ls-files > /tmp/tracked.txt
rsync -az --no-owner --no-group --files-from=/tmp/tracked.txt \
  --exclude='.env' --exclude='.env.*' --exclude='bootstrap/cache/*' \
  -e "ssh -i ~/.ssh/id_ed25519" \
  ./ root@178.105.193.206:/opt/cg/laravel/app/

# Then on the server:
cd /opt/cg/laravel/app
composer install --no-interaction --optimize-autoloader --no-dev   # only if deps changed
php artisan config:cache
systemctl restart php8.5-fpm.service          # restart (not reload) if PHP classes changed
systemctl restart ragflow-bridge.service      # if ragflow_service/ changed
```

> ⚠️ **Never** rsync `ragflow_service/.venv` — recreating it wipes the bridge’s
> Python env. If it is ever lost: `cd ragflow_service && python3 -m venv .venv &&
> .venv/bin/pip install -r requirements.txt`.

### OpenWebUI tool deploy (separate)
The live tool runs from `webui.db`, not the filesystem. After editing
`openwebui_tools/vascular_mcp_adapter.py`:
```bash
scp -i ~/.ssh/id_ed25519 openwebui_tools/vascular_mcp_adapter.py root@178.105.193.206:/tmp/vascular_expert_new.py
scp -i ~/.ssh/id_ed25519 openwebui_tools/push_adapter.py         root@178.105.193.206:/tmp/push_adapter.py
ssh -i ~/.ssh/id_ed25519 root@178.105.193.206 "
  docker cp /tmp/vascular_expert_new.py open-webui:/tmp/vascular_expert_new.py &&
  docker cp /tmp/push_adapter.py open-webui:/tmp/push_adapter.py &&
  docker exec open-webui python3 /tmp/push_adapter.py &&   # writes id=vascular_mcp_adapter
  docker restart open-webui                                # required — module is cached in memory
"
```

---

## 2. Restart matrix — which action for which change

| You changed… | Do this |
|---|---|
| `.env` (Laravel) | `php artisan config:cache && systemctl reload php8.5-fpm.service` |
| A PHP class / `app/` code | `systemctl restart php8.5-fpm.service` (opcache does **not** hot-reload classes) |
| `config/*.php` | `php artisan config:cache && systemctl reload php8.5-fpm.service` |
| `ragflow_service/` or its `.env` | `systemctl restart ragflow-bridge.service` |
| RAGFlow model config / keys in its DB | `docker restart docker-ragflow-cpu-1` (clears cached model clients) |
| OpenWebUI model/connection/tool | `docker restart open-webui` |
| Caddy config | `systemctl reload caddy` |

---

## 3. Health checks

```bash
systemctl status php8.5-fpm.service ragflow-bridge.service caddy.service
docker ps --format '{{.Names}}\t{{.Status}}' | grep -E 'ragflow|mysql|redis|open-webui'

# Bridge liveness:
curl -s -H "X-Bridge-Secret: $(grep ^RAGFLOW_BRIDGE_SECRET= .env | cut -d= -f2-)" http://127.0.0.1:8000/health

# Full pipeline (expects narrative_chunks + citation_chunks):
curl -s http://127.0.0.1:8001/api/v1/vascular-consult \
  -H "X-API-Key: $(grep ^API_SECRET_KEY= .env | cut -d= -f2-)" -H 'Content-Type: application/json' \
  -d '{"question":"When is repair indicated for an asymptomatic AAA based on diameter?","history":[]}'
```

---

## 4. Logs

```bash
cd /opt/cg/laravel/app
tail -f storage/logs/laravel.log                              # app errors
tail -f storage/logs/ragflow-$(date +%Y-%m-%d).log            # bridge/RAGFlow calls
tail -f storage/logs/retrieval-$(date +%Y-%m-%d).log          # planner + retrieval timing
docker logs open-webui --tail 100 -f                          # OpenWebUI
docker logs docker-ragflow-cpu-1 --tail 100 -f                # RAGFlow
```

Key retrieval-log lines: `[PRE-RETRIEVAL TIMING]` (`plan_applied:true` = planner
worked), `[PLANNER] Merged pre-retrieval plan produced`, `[GRAPHRAG] Concept expansion`.

---

## 5. Troubleshooting (symptoms seen in production)

| Symptom | Cause | Fix |
|---|---|---|
| Retrieval returns `code 109 "API key is invalid"` | Stale **RAGFlow API token** in `ragflow_service/.env` | Regenerate in RAGFlow UI → API Keys; update `RAGFLOW_API_KEY`; `systemctl restart ragflow-bridge.service` |
| Retrieval `code 100 APIConnectionError / "Name or service not known"` | Embedding model points at a dead host (e.g. old Azure) | Fix embedding provider + `knowledgebase.tenant_embd_id`; `docker restart docker-ragflow-cpu-1`. See PROVIDER_MIGRATION §2.1 |
| `code 100` with `403 … project does not have access to model` | OpenAI **project** model allow-list | Enable the model in that project (Project → Limits) |
| `[PLANNER] JSON parse failed` | Planner model truncated/malformed JSON | Raise `RETRIEVAL_PLANNER_MAX_TOKENS`; ensure `OPENAI_REASONING_EFFORT=minimal` for gpt-5 reasoning models |
| Planner still hits Azure after an env change | opcache serving old class/config | `systemctl restart php8.5-fpm.service` (full restart) |
| Config change "not taking" | Config is cached | `php artisan config:cache` |
| OpenWebUI answer wrong model / tool not firing | Tool cached in memory, or model base wrong | `docker restart open-webui`; check `model.base_model_id` |

---

## 6. Backups & rollback

- **Before any DB or `.env` edit**, snapshot: `cp .env .env.bak.$(date +%Y%m%d_%H%M%S)`;
  for OpenWebUI: `docker exec open-webui cp /app/backend/data/webui.db /app/backend/data/webui.db.bak.$(date +%s)`.
- Existing backups on the server: `.env.bak.*`, `ragflow_service/.env.bak.*`,
  `/root/kb_embd_backup_*.tsv`, `/root/azure_embed_rows_*.tsv`, `webui.db.bak.*`.
- RAGFlow MySQL dump: `docker exec docker-mysql-1 sh -lc 'mysqldump -uroot -p"$MYSQL_PASSWORD" rag_flow' > /root/rag_flow_$(date +%F).sql`.
- Roll back a deploy by re-rsyncing a previous git commit and repeating §1.

---

## 7. Firewall / exposure

`ufw` is active (default-deny) and allows only **22, 80, 443**. RAGFlow (9380),
MySQL, valkey, Elasticsearch (1200), and the bridge (8000) are internal only. Keep
it that way — never `ufw allow` those ports to the internet. Full security review
in [`HIPAA_COMPLIANCE.md`](HIPAA_COMPLIANCE.md).
