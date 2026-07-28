# Deploy sequence — gate v2 prototype on OpenWebUI

Target: Hetzner all-in-one, `ssh -i ~/.ssh/id_ed25519 root@178.105.193.206`.
Branch `claude/prototyping-summary-d597c2` at `6db76b3` or later.

Verified facts about this host, checked 2026-07-28:

| | |
|---|---|
| Laravel app | `/opt/cg/laravel/app` — **rsync-deployed, NOT a git checkout** |
| Web server | `caddy.service`, config `/etc/caddy/Caddyfile`, **explicit path allowlist** |
| PHP | `php8.5-fpm.service` |
| Bridge | `ragflow-bridge.service` |
| OpenWebUI | docker container `open-webui` |

**Nothing here has been executed.** The adapter has never run against a live backend.

---

## 0. Pre-flight

```bash
ssh -i ~/.ssh/id_ed25519 root@178.105.193.206
cp -a /opt/cg/laravel/app /opt/cg/laravel/app.bak.$(date +%Y%m%d_%H%M%S)
cp /etc/caddy/Caddyfile /etc/caddy/Caddyfile.bak.$(date +%Y%m%d_%H%M%S)
docker exec open-webui cp /app/backend/data/webui.db /app/backend/data/webui.db.bak
```

Record what is live so you can compare afterwards. There is no git in the app dir, so
the directory copy IS the rollback.

---

## 1. Caddy route — DO THIS FIRST, it has silently broken two previous deploys

The Caddyfile allowlists individual `/api/v1/...` paths. Currently present:
`case-state`, `clinical-gate`, `normalize`, `pending-case-state`, `pre-retrieval`,
`vascular-consult`.

**`gate-progress` is MISSING.** Without it the progress endpoint 404s, the adapter
falls back to a single "Working…" status, and the demo looks like progress does not
work — with no error anywhere to explain why. `case-state` and `pending-case-state`
each hit exactly this on their first deploy.

Add `/api/v1/gate-progress*` alongside the existing paths, matching their syntax, then:

```bash
caddy validate --config /etc/caddy/Caddyfile
systemctl reload caddy
```

---

## 2. Deploy the Laravel code

From the local worktree:

```bash
cd /home/vga/LAVAREL/Laravel-RAGFLOW-router/.claude/worktrees/prototyping-summary-d597c2
rsync -az --delete \
  --exclude='.git' \
  --exclude='ragflow_service/.venv' \
  --exclude='node_modules' \
  --exclude='vendor' \
  --exclude='.env' \
  --exclude='storage' \
  --exclude='database/database.sqlite' \
  -e "ssh -i ~/.ssh/id_ed25519" ./ root@178.105.193.206:/opt/cg/laravel/app/
```

`ragflow_service/.venv` **must** be excluded — deleting it takes the bridge down, and
it has happened before. `.env` and `storage` are excluded so live config and logs
survive.

---

## 3. Environment

Append to `/opt/cg/laravel/app/.env`. Do not print the file; append only.

```
GATE_V2_ENABLED=true
GATE_V2_PERSIST_SNIPPET_DIGESTS=false
```

Leave `GATE_V2_CITATION_MULTI_QUERY` at its default (true) and
`GATE_V2_RETRIEVAL_DEV_CACHE_TTL` at 0 — the cache must never be on for real users.

Confirm Redis is reachable for the progress channel; the app already uses it for case
state, so the existing `REDIS_*` values should suffice. If progress returns empty in
step 6 but the endpoint responds, Redis auth is the first suspect — that has bitten
this project before.

---

## 4. Rebuild and restart

```bash
cd /opt/cg/laravel/app
composer install --no-dev --optimize-autoloader --no-interaction
php artisan config:cache
php artisan route:cache
systemctl reload php8.5-fpm
```

`config:cache` is mandatory after any `.env` change or the new flags are ignored —
the app reads `config()`, not `env()`, at runtime.

---

## 5. Backend smoke test, before touching OpenWebUI

Verify the two endpoints answer before involving the UI. Use the API key from the live
`.env` without printing it:

```bash
cd /opt/cg/laravel/app
KEY=$(grep -m1 '^VASCULAR_API_KEY=' .env | cut -d= -f2-)

# clinical-gate
curl -s -o /tmp/cg.json -w '%{http_code}\n' -X POST http://127.0.0.1/api/v1/clinical-gate \
  -H "X-API-Key: $KEY" -H 'Content-Type: application/json' \
  -d '{"message":"What antithrombotic therapy after vein below-knee bypass for CLTI, no high bleeding risk?","request_id":"smoke-1"}'

# progress for the same id
curl -s -w '\n%{http_code}\n' "http://127.0.0.1/api/v1/gate-progress/smoke-1" -H "X-API-Key: $KEY"
```

Expect `200` from both. Then check the response actually carries the prototype
contract:

```bash
python3 -c "import json;d=json.load(open('/tmp/cg.json'));print('citations',len(d.get('citations',[])));print('assets',len(d.get('assets',[])));print('degradation',d.get('degradation'));print('kinds',sorted({c.get('kind') for c in d.get('citations',[])}))"
```

**Do not proceed if `citations` is 0** — the chips will not render and the whole point
of the prototype is lost.

---

## 6. Install the adapter as a NEW OpenWebUI tool

**Do NOT use `openwebui_tools/push_adapter.py`.** It writes to id `vascular_mcp_adapter`,
which is the live production tool, and would overwrite it. There is no script for
installing a new tool id, and writing one for a first install is more risk than value.

Use the OpenWebUI admin UI instead:

1. Admin Panel → Tools → **Create new tool**
2. Id **`gate_adapter`** — must not collide with `vascular_mcp_adapter` or `mcp`
3. Paste the contents of `openwebui_tools/gate_adapter.py`
4. Save, then set the Valves:
   - `CLINICAL_GATE_BASE_URL` — the same base the existing adapter uses
   - `CLINICAL_GATE_API_KEY` — as in `.env`
   - `REQUEST_TIMEOUT_SECONDS` `120`
   - `POLL_INTERVAL_SECONDS` `2`
   - `EMIT_STATUS` **true**
5. Enable `gate_adapter` on a **test model only**. Leave the production model pointed
   at `vascular_mcp_adapter` so clinicians are unaffected.

```bash
docker restart open-webui   # OpenWebUI caches tool modules in memory
```

---

## 7. End-to-end check

Ask the test model the S2 question above and confirm all four prototype claims:

| Claim | What to look for |
|---|---|
| Live feedback | Status lines change during the wait, naming guidelines and progress, not one static line |
| Clickable citations | `[n]` chips open a popup with the verbatim recommendation, its class and level |
| Structured answer | Guideline-grounded content and flagged interpretation remain visibly separate |
| Honest degradation | If a branch fails, the notice appears in the answer, not only in logs |

Expect 45–85 s. If status never changes, go straight back to step 1 — the Caddy route
is the most likely cause and it fails silently.

---

## Rollback

```bash
rm -rf /opt/cg/laravel/app && mv /opt/cg/laravel/app.bak.<timestamp> /opt/cg/laravel/app
cp /etc/caddy/Caddyfile.bak.<timestamp> /etc/caddy/Caddyfile && systemctl reload caddy
cd /opt/cg/laravel/app && php artisan config:cache && systemctl reload php8.5-fpm
```

In OpenWebUI, disable the `gate_adapter` tool. The production `vascular_mcp_adapter`
is never modified by this procedure, so the clinician-facing path is unaffected
throughout — that is the main safety property of deploying a NEW tool rather than
editing the existing one.

---

## Known risks going in

- The adapter has **never run against a live backend**. The unit tests cover the
  Laravel contract only. Expect at least one contract mismatch on first contact; one
  has already been found and fixed before deploy (the adapter polled `emissions`, the
  backend returns `progress`).
- p95 turn latency is **83 s against a 90 s deadline**. Little headroom; a slow
  upstream will produce degraded answers rather than failures, which is by design but
  will be visible.
- `citations[]` depends on the citation bucket being populated. If retrieval returns
  narrative only, chips will be sparse — check `kinds` in step 5.
