# Master Plan — Vizra ADK Agent Migration

## Current state
- **Production:** `vascular_mcp_adapter.py` v1.5.53 running in OpenWebUI on 48 VM.
  Direct HTTP calls to Laravel 135 VM. Working correctly.
- **Goal:** Replace Python orchestration logic with a Vizra ADK agent living inside Laravel.
  Python adapter shrinks to ~80 lines (pure bridge). Clinical intelligence moves to PHP + system prompt.
- **Production is untouched throughout.** All work happens on a new clean VM.
  Go-live = one valve change in OpenWebUI. Rollback = change it back.

---

## Infrastructure map

| VM | IP | Role | Touch? |
|---|---|---|---|
| Laravel prod | `135.237.148.105` | Production API + v1.5.53 | **Never** |
| OpenWebUI + RAGFlow | `48.211.217.69` | OpenWebUI, RAGFlow, user-facing | Step 14 only (valve change) |
| **New Vizra VM** | `<NEW_VM_IP>` | Vizra ADK dev/new production | All build work here |

---

## Before you start — 3 prerequisites

### P1. Rotate GitHub PAT
The token was exposed in terminal output. Do this first.

1. Go to https://github.com/settings/tokens
2. Delete the old token
3. Create new token (repo scope, no expiry or 90 days)
4. On 135 VM, update the remote:
```bash
ssh -i ~/LAVAREL.pem azureuser@135.237.148.105
cd /home/azureuser/laravel-ragflow
git remote set-url origin https://<NEW_TOKEN>@github.com/vanalexgr/Laravel-RAGFLOW-router.git
git fetch   # confirm it works
```
5. Note the new token — you'll need it in Step 4 (clone on new VM).

### P2. Get DeepSeek API key
1. Go to https://platform.deepseek.com
2. Create account / log in
3. API Keys → Create new key
4. Copy and store as `DEEPSEEK_API_KEY=sk-...`
5. Top up credit (minimum $5 — at ~$0.003/call this is ~1600 calls)

### P3. Collect .env values from 135 VM
Run this and save the output securely (you'll paste into the new VM's .env):
```bash
ssh -i ~/LAVAREL.pem azureuser@135.237.148.105 \
  "grep -E '^(API_SECRET_KEY|AZURE_OPENAI|RAGFLOW|BRIDGE_RERANK)' \
   /home/azureuser/laravel-ragflow/.env"
```

---

## Phase 1 — Provision the new VM

### Step 1. Create Azure VM
```bash
# From your local machine
az vm create \
  --resource-group <your-rg> \
  --name vizra-agent-vm \
  --image Ubuntu2204 \
  --size Standard_B2ms \
  --admin-username azureuser \
  --ssh-key-values ~/.ssh/id_rsa.pub \
  --public-ip-sku Standard \
  --output table

az vm open-port --resource-group <your-rg> --name vizra-agent-vm --port 80  --priority 100
az vm open-port --resource-group <your-rg> --name vizra-agent-vm --port 443 --priority 110
az vm open-port --resource-group <your-rg> --name vizra-agent-vm --port 22  --priority 120

# Get the IP
az vm show -d --resource-group <your-rg> --name vizra-agent-vm --query publicIps -o tsv
```

Add to `~/.ssh/config` on your local machine:
```
Host vizra-vm
  HostName <NEW_VM_IP>
  User azureuser
  IdentityFile ~/.ssh/id_rsa
```

Then: `ssh vizra-vm`

### Step 2. Install system packages
```bash
sudo apt-get update && sudo apt-get upgrade -y

# PHP 8.2
sudo apt-get install -y software-properties-common
sudo add-apt-repository ppa:ondrej/php -y
sudo apt-get update
sudo apt-get install -y \
  php8.2 php8.2-cli php8.2-mbstring php8.2-xml php8.2-curl \
  php8.2-zip php8.2-sqlite3 php8.2-bcmath php8.2-tokenizer \
  php8.2-dom php8.2-fileinfo

php --version    # must show 8.2.x

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Python 3.11
sudo apt-get install -y python3.11 python3.11-venv python3-pip

# Tools
sudo apt-get install -y git curl unzip sqlite3

# Caddy
sudo apt-get install -y debian-keyring debian-archive-keyring apt-transport-https
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' \
  | sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' \
  | sudo tee /etc/apt/sources.list.d/caddy-stable.list
sudo apt-get update && sudo apt-get install -y caddy
sudo systemctl enable caddy
```

### Step 3. Clone the repo
```bash
cd /home/azureuser
git clone https://<NEW_TOKEN>@github.com/vanalexgr/Laravel-RAGFLOW-router.git laravel-ragflow
cd laravel-ragflow
git checkout main
```

### Step 4. Install Composer dependencies
```bash
cd /home/azureuser/laravel-ragflow
composer install --no-dev --optimize-autoloader
```

### Step 5. Configure .env
```bash
cp .env.example .env
php artisan key:generate
nano .env
```

Set these values (use the output you collected in P3):
```ini
APP_NAME=VascularExpert
APP_ENV=local
APP_DEBUG=false
APP_URL=http://<NEW_VM_IP>

# Database — SQLite (no MySQL needed on this VM)
DB_CONNECTION=sqlite
# Remove or comment out DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD

# API auth
API_SECRET_KEY=<from 135 VM>

# Azure OpenAI (for GuidelineRouterService — unchanged)
AZURE_OPENAI_ENDPOINT=<from 135 VM>
AZURE_OPENAI_API_KEY=<from 135 VM>
AZURE_OPENAI_VERSION=2024-12-01-preview
AZURE_OPENAI_DEPLOYMENT=<from 135 VM>

# RAGFlow — same endpoint, same credentials
RAGFLOW_API_KEY=<from 135 VM>
RAGFLOW_ENDPOINT=https://ragflow.clinicalguidelines.io/api/v1
RAGFLOW_USE_BRIDGE=true
RAGFLOW_BRIDGE_URL=http://127.0.0.1:8000
RAGFLOW_BRIDGE_SECRET=<from 135 VM>
RAGFLOW_DEFAULT_MODE=standard
RAGFLOW_TOP_K=60
RAGFLOW_QUERY_EXPANSION=false
RAGFLOW_QUALITY_PASS_ENABLED=true
RAGFLOW_QUALITY_PASS_MIN_CITATION=2
RAGFLOW_QUALITY_PASS_TOP_K=80
RAGFLOW_HIGH_RECALL_TOP_K_CEILING=128
RAGFLOW_QUALITY_PASS_MIN_NARRATIVE=8

# Reranker
BRIDGE_RERANK_ENDPOINT=<from 135 VM>
BRIDGE_RERANK_API_KEY=<from 135 VM>
RAGFLOW_RERANK_ID=<from 135 VM>

# Vizra ADK — agent LLM
VASCULAR_AGENT_MODEL=deepseek-chat
DEEPSEEK_API_KEY=<your DeepSeek key from P2>
```

Create SQLite DB file:
```bash
touch /home/azureuser/laravel-ragflow/database/database.sqlite
```

### Step 6. Run base migrations
```bash
php artisan migrate
php artisan config:cache
```

---

## Phase 2 — Install Vizra ADK

### Step 7. Install the package
```bash
cd /home/azureuser/laravel-ragflow
composer require vizra/vizra-adk
php artisan vizra:install
php artisan migrate
```

Confirm:
```bash
php artisan | grep vizra      # should list vizra:make:agent etc.
php artisan route:list | grep vizra   # should show /vizra dashboard
```

---

## Phase 3 — Build the agent (Codex task)

Hand `CODEX_VIZRA_ADK.md` to Codex. It will create these files on the new VM:

| File | What it does |
|---|---|
| `resources/prompts/vascular_agent_system.md` | Agent system prompt — clinical intelligence, clarification gate, STRICT_TEMPLATE |
| `app/Agents/Tools/RetrieveClinicalEvidenceTool.php` | Calls `RetrievalService::retrieve()` directly |
| `app/Agents/VascularConsultAgent.php` | Vizra agent — DeepSeek, system prompt, tool registration |
| `app/Http/Controllers/AgentConsultController.php` | Runs agent, returns `{response, citations, assets, mode}` |
| `routes/api.php` | +1 line: `POST /api/v1/agent-consult` with `ValidateApiKey` middleware |

After Codex finishes:
```bash
php artisan config:cache
php artisan route:list --path=agent-consult   # must appear
```

---

## Phase 4 — Set up services

### Step 8. RAGFlow bridge
```bash
cd /home/azureuser/laravel-ragflow/ragflow_service
python3.11 -m venv venv
source venv/bin/activate
pip install -r requirements.txt
deactivate

sudo tee /etc/systemd/system/ragflow-bridge.service << 'EOF'
[Unit]
Description=RAGFlow Bridge API on port 8000
After=network-online.target

[Service]
Type=simple
User=azureuser
WorkingDirectory=/home/azureuser/laravel-ragflow/ragflow_service
Environment=PYTHONUNBUFFERED=1
ExecStart=/bin/bash -lc 'set -a && source /home/azureuser/laravel-ragflow/.env && set +a && source venv/bin/activate && exec uvicorn app:app --host 0.0.0.0 --port 8000 --timeout-keep-alive 120'
Restart=always
RestartSec=2
StandardOutput=append:/home/azureuser/laravel-ragflow/bridge.log
StandardError=append:/home/azureuser/laravel-ragflow/bridge.log

[Install]
WantedBy=multi-user.target
EOF

sudo systemctl daemon-reload
sudo systemctl enable ragflow-bridge
sudo systemctl start ragflow-bridge
sudo systemctl status ragflow-bridge   # must show active (running)
```

### Step 9. Laravel API service
```bash
sudo tee /etc/systemd/system/laravel-api.service << 'EOF'
[Unit]
Description=Laravel API server on port 8001
After=network-online.target ragflow-bridge.service

[Service]
Type=simple
User=azureuser
WorkingDirectory=/home/azureuser/laravel-ragflow
ExecStart=/usr/bin/php artisan serve --host 0.0.0.0 --port 8001
Restart=always
RestartSec=3
StandardOutput=append:/home/azureuser/laravel-ragflow/storage/logs/artisan-serve.log
StandardError=append:/home/azureuser/laravel-ragflow/storage/logs/artisan-serve.log

[Install]
WantedBy=multi-user.target
EOF

sudo systemctl daemon-reload
sudo systemctl enable laravel-api
sudo systemctl start laravel-api
sudo systemctl status laravel-api   # must show active (running)
```

### Step 10. Caddy
```bash
sudo tee /etc/caddy/Caddyfile << 'EOF'
:<NEW_VM_IP> {
    handle {
        reverse_proxy localhost:8001
    }
}
EOF

sudo systemctl restart caddy
```

---

## Phase 5 — Test on new VM (before touching OpenWebUI)

Run all tests from your local machine. Replace `<NEW_VM_IP>` and `<API_SECRET_KEY>`.

### Test 1 — Bridge up
```bash
ssh vizra-vm "curl -s http://127.0.0.1:8000/"
# Must return something (not connection refused)
```

### Test 2 — Basic agent call
```bash
curl -s https://<NEW_VM_IP>/api/v1/agent-consult \
  -k \
  -H "Authorization: Bearer <API_SECRET_KEY>" \
  -H "Content-Type: application/json" \
  -d '{
    "question": "What diameter AAA requires EVAR?",
    "session_key": "test001",
    "guidelines": ["abdominal_aortic_aneurysm"]
  }' | python3 -m json.tool
```
Expected: `{"response": "...", "citations": [...], "assets": [...], "mode": "COMPACT"}`

### Test 3 — Clarification gate
```bash
curl -s https://<NEW_VM_IP>/api/v1/agent-consult \
  -k \
  -H "Authorization: Bearer <API_SECRET_KEY>" \
  -H "Content-Type: application/json" \
  -d '{
    "question": "My patient has a carotid stenosis",
    "session_key": "test002",
    "guidelines": ["carotid_vertebral"]
  }' | python3 -m json.tool
```
Expected: Response contains a clarifying question (symptomatic status, stenosis degree).
No `citations` yet (tool not called until context is complete).

### Test 4 — Multi-turn session memory (same session_key)
```bash
# Follow-up in the same session as Test 3
curl -s https://<NEW_VM_IP>/api/v1/agent-consult \
  -k \
  -H "Authorization: Bearer <API_SECRET_KEY>" \
  -H "Content-Type: application/json" \
  -d '{
    "question": "Symptomatic, 75% stenosis, good surgical candidate",
    "session_key": "test002",
    "guidelines": ["carotid_vertebral"]
  }' | python3 -m json.tool
```
Expected: Full clinical response with citations. No re-asking of questions already answered.

### Test 5 — Response format
Check the `response` field contains:
- `**Mode:** COMPACT|STANDARD|FULL — Rule N —` as the first line
- `## Clinical Decision` section
- `## Evidence Used` section
- `## Guideline Gap` section (only in FULL mode)

### Test 6 — Citations present
```bash
# Check citations array is non-empty and has the right shape
curl -s https://<NEW_VM_IP>/api/v1/agent-consult \
  -k \
  -H "Authorization: Bearer <API_SECRET_KEY>" \
  -H "Content-Type: application/json" \
  -d '{
    "question": "What is CLTI and when is revascularisation indicated?",
    "session_key": "test003",
    "guidelines": ["clti"]
  }' | python3 -c "
import sys, json
d = json.load(sys.stdin)
print('Mode:', d.get('mode'))
print('Citations:', len(d.get('citations', [])))
print('Assets:', len(d.get('assets', [])))
print('Response preview:', d.get('response','')[:200])
"
```

### Test 7 — Vizra dashboard
Open in browser: `http://<NEW_VM_IP>/vizra`
Should show sessions, messages, traces for the tests above.

### Test 8 — Laravel logs clean
```bash
ssh vizra-vm "tail -20 /home/azureuser/laravel-ragflow/storage/logs/laravel.log"
# Must show Tool API Response entries, no exceptions
```

---

## Phase 6 — Deploy the Python adapter

### Step 11. Update the Python adapter for the new endpoint

File: `openwebui_tools/vascular_agent_adapter.py` (already created by Codex in CODEX_VIZRA_ADK.md Step 9)

The adapter calls `POST /api/v1/agent-consult`. It reads `LARAVEL_URL` and `API_KEY` from its Valves.

### Step 12. Push to OpenWebUI DB

```bash
# From local machine
scp -i ~/ragflownew.pem openwebui_tools/vascular_agent_adapter.py \
  azureuser@48.211.217.69:/tmp/vascular_agent_adapter.py

scp -i ~/ragflownew.pem openwebui_tools/push_agent_adapter.py \
  azureuser@48.211.217.69:/tmp/push_agent_adapter.py

ssh -i ~/ragflownew.pem azureuser@48.211.217.69 "
  sudo docker cp /tmp/vascular_agent_adapter.py open-webui:/tmp/vascular_agent_adapter.py &&
  sudo docker cp /tmp/push_agent_adapter.py     open-webui:/tmp/push_agent_adapter.py &&
  sudo docker exec open-webui python3 /tmp/push_agent_adapter.py &&
  sudo docker restart open-webui &&
  echo DEPLOYED
"
```

Expected output:
```
SUCCESS: vascular_mcp_adapter content length = XXXXX
  OK: version: 3.0.0
  OK: no import anthropic
  OK: _session_key function
  OK: _emit_citations method
  OK: agent-consult endpoint
  OK: VERBATIM
  OK: VALID_GUIDELINE_KEYS
```

### Step 13. Set adapter valves

In OpenWebUI → Settings → Tools → vascular_mcp_adapter → Valves:
```
VASCULAR_API_BASE_URL = https://<NEW_VM_IP>
VASCULAR_API_KEY      = <API_SECRET_KEY>
TIMEOUT               = 120
```

Restart OpenWebUI if valves don't take effect:
```bash
ssh -i ~/ragflownew.pem azureuser@48.211.217.69 "sudo docker restart open-webui"
```

---

## Phase 7 — End-to-end OpenWebUI tests

Test in OpenWebUI chat (with the ESVS Expert model):

| # | Query | Expected |
|---|---|---|
| 1 | "What diameter AAA requires EVAR?" | Direct answer, citation pills in sidebar |
| 2 | "My patient has a carotid stenosis" | Clarification question before answer |
| 3 | *(reply to #2)* "Symptomatic, 70%, fit patient" | Full treatment answer with citations |
| 4 | "What about surveillance?" *(in same chat)* | Uses session — no re-clarification |
| 5 | "Who is the president of France?" | Scope explanation only |
| 6 | *(any vascular question)* | Guideline figures visible in citation sidebar |

Check:
- Response sections: `## Clinical Decision`, `## Treatment Options`, `## Evidence Used`, etc.
- Citation pills appear in sidebar and show chunk text when clicked
- No "🩺 Clinical Synthesis" or "📑 Recommendations" (gpt-5-chat not reformatting)
- Status indicator shows "Consulting ESVS guidelines…" then "Done"

---

## Phase 8 — Go live

If all Phase 7 tests pass, the Vizra VM IS production.

Optional: assign a proper DNS name instead of bare IP:

```bash
# Add DNS A record pointing to NEW_VM_IP in Azure DNS or your DNS provider
# Then update Caddyfile:
sudo tee /etc/caddy/Caddyfile << 'EOF'
agent.clinicalguidelines.io {
    handle {
        reverse_proxy localhost:8001
    }
}
EOF
sudo systemctl restart caddy

# Update OpenWebUI valve:
# VASCULAR_API_BASE_URL = https://agent.clinicalguidelines.io
```

The old 135 VM can be left running (for rollback) or decommissioned once the new VM
has been stable for 2+ weeks.

---

## Rollback — instant at any point

**Rollback the adapter** (revert to v1.5.53 on 135 VM):
```bash
ssh -i ~/ragflownew.pem azureuser@48.211.217.69 "
sudo docker exec open-webui python3 -c \"
import sqlite3
with open('/app/backend/data/vascular_adapter_v1.5.53_backup.py') as f:
    c = f.read()
conn = sqlite3.connect('/app/backend/data/webui.db')
conn.execute(\\\"UPDATE tool SET content=? WHERE id='vascular_mcp_adapter'\\\", (c,))
conn.commit()
print('Rolled back to v1.5.53')
\"
sudo docker restart open-webui
"
```

Then in OpenWebUI Valves:
```
VASCULAR_API_BASE_URL = https://lavarel.eastus2.cloudapp.azure.com
VASCULAR_API_KEY      = <API_SECRET_KEY>
```

Production is fully restored in under 60 seconds.

---

## Files in this repo

| File | Purpose |
|---|---|
| `MASTER_PLAN.md` | **This file** — complete sequence start to finish |
| `VIZRA_VM_SETUP.md` | Detailed VM setup reference (same as Phases 1–4 above) |
| `CODEX_VIZRA_ADK.md` | Codex instructions for building PHP agent classes |
| `openwebui_tools/vascular_agent_adapter.py` | New Python adapter v3.0 (created by Codex) |
| `openwebui_tools/push_agent_adapter.py` | Deploys adapter to OpenWebUI DB |
| `openwebui_tools/vascular_mcp_adapter.py` | Production v1.5.53 — never modify |

---

## Estimated timeline

| Phase | Work | Time |
|---|---|---|
| Prerequisites (P1–P3) | Rotate PAT, get DeepSeek key, collect .env values | 15 min |
| Phase 1 (Steps 1–6) | Provision + base Laravel setup | 30–45 min |
| Phase 2 (Step 7) | Install Vizra ADK | 10 min |
| Phase 3 (Codex task) | Build agent PHP classes | 30–60 min |
| Phase 4 (Steps 8–10) | Start services + Caddy | 15 min |
| Phase 5 (Tests 1–8) | API-level validation | 20 min |
| Phase 6 (Steps 11–13) | Deploy Python adapter to OpenWebUI | 10 min |
| Phase 7 (OpenWebUI tests) | End-to-end validation | 20 min |
| **Total** | | **~3 hours** |
