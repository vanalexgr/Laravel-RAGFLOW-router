# Vascular MCP Server — Deployment Guide

> **⚠️ Not the production path.** Production clients use the OpenWebUI tool
> `openwebui_tools/vascular_mcp_adapter.py` (see the repo [`README.md`](../README.md)).
> This standalone FastMCP server is an **alternate interface whose cutover was
> never performed**. Hosts/providers below may reference the decommissioned Azure
> VMs — current infra is the Hetzner VM (see [`../CLAUDE.md`](../CLAUDE.md)).

## Overview

This directory contains the **Vascular MCP Server**: a FastMCP Python service that wraps the
existing Laravel/RAGFlow vascular guidelines backend and exposes it as a standards-compliant
[Model Context Protocol](https://modelcontextprotocol.io/) (MCP) service.

It runs **in parallel** with the existing `vascular_expert.py` OpenWebUI tool. The old tool
remains active until the MCP path is manually validated and the cutover is executed.

---

## Architecture

```
OpenWebUI (48 VM)
  └─ MCPClient → HTTPS → lavarel.eastus2.cloudapp.azure.com/mcp
                              │
                         Caddy (443, 135 VM)
                              │ handle_path /mcp* (strips prefix)
                              ↓
                    vascular-mcp.service (port 8080, 135 VM)
                         FastMCP server.py
                              │
                              ↓
                    laravel-api.service (port 8001, 135 VM)
                         Laravel RAGFlow router
```

All existing traffic to `lavarel.eastus2.cloudapp.azure.com` (without `/mcp`) continues to
reach Laravel unchanged via Caddy's `handle` catch-all.

---

## Exposed Tools

| Tool | Description |
|------|-------------|
| `vascular_consult_guidelines` | Retrieve ESVS guideline evidence for a query. Call directly for knowledge questions. |
| `vascular_assess_context_gaps` | Clinical context gate. Call first for patient-case questions. Returns `PROCEED` or `NEEDS_CLARIFICATION`. |
| `vascular_list_guidelines` | List all 14 available ESVS guideline dataset keys. |

---

## Directory Layout

```
vascular_mcp/
├── server.py                   # FastMCP server — all tools + gate logic
├── requirements.txt            # Python dependencies
├── .env.example                # Environment variable template
├── DEPLOYMENT.md               # This file
└── tests/
    ├── conftest.py             # pytest path + dotenv setup
    ├── test_context_gate.py    # Unit tests for gate (no Laravel required)
    ├── test_integration.py     # Integration tests against live Laravel API
    └── test_mcp_protocol.py    # MCP protocol compliance tests (requires running service)
```

---

## Prerequisites

- Python 3.10+
- The Laravel API service running on port 8001
- Caddy configured with the `/mcp*` route (see Caddy section below)
- A valid `LARAVEL_API_KEY`

---

## Installation (135 VM)

```bash
cd /home/azureuser
git clone <repo> vascular-mcp   # or copy files
cd vascular-mcp

python3 -m venv .venv
.venv/bin/pip install -r requirements.txt

cp .env.example .env
# Edit .env — set LARAVEL_BASE_URL and LARAVEL_API_KEY
nano .env
chmod 600 .env
```

### .env contents

```
LARAVEL_BASE_URL=http://127.0.0.1:8001
LARAVEL_API_KEY=<key from Laravel .env APP_API_KEY>
```

---

## Systemd Service

File: `/etc/systemd/system/vascular-mcp.service`

```ini
[Unit]
Description=Vascular MCP Server
After=network.target laravel-api.service

[Service]
User=azureuser
WorkingDirectory=/home/azureuser/vascular-mcp
EnvironmentFile=/home/azureuser/vascular-mcp/.env
ExecStart=/home/azureuser/vascular-mcp/.venv/bin/python server.py streamable_http 8080
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable vascular-mcp.service
sudo systemctl start vascular-mcp.service
sudo systemctl status vascular-mcp.service
```

---

## Caddy Configuration

File: `/etc/caddy/Caddyfile`

```caddy
lavarel.eastus2.cloudapp.azure.com {
    # /mcp* → MCP server (prefix stripped; FastMCP sees requests at /)
    handle_path /mcp* {
        reverse_proxy localhost:8080
    }

    # Everything else → Laravel (unchanged)
    handle {
        reverse_proxy localhost:8001
    }
}
```

File: `/etc/systemd/system/caddy.service`

```ini
[Unit]
Description=Caddy Web Server
After=network.target

[Service]
User=root
ExecStart=/usr/local/bin/caddy run --config /etc/caddy/Caddyfile
ExecReload=/usr/local/bin/caddy reload --config /etc/caddy/Caddyfile
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
```

> **Important**: Only ONE Caddy service should run. The legacy `caddy-lavarel.service`
> (bare `caddy reverse-proxy` command) must be stopped and disabled:
> ```bash
> sudo systemctl stop caddy-lavarel.service
> sudo systemctl disable caddy-lavarel.service
> ```

---

## OpenWebUI Connection (48 VM)

The MCP server is registered in OpenWebUI via the admin API:

```bash
curl -X POST http://localhost:8080/api/v1/configs/tool_servers \
  -H 'Authorization: Bearer <admin_api_key>' \
  -H 'Content-Type: application/json' \
  -d '{
    "TOOL_SERVER_CONNECTIONS": [{
      "url": "https://lavarel.eastus2.cloudapp.azure.com/mcp",
      "path": "/",
      "type": "mcp",
      "auth_type": "none",
      "key": "",
      "config": {},
      "info": {"id": "6a97362f-5da7-4e19-99d2-323a48874ed6", "name": "Vascular MCP"}
    }]
  }'
```

Verify the connection:
```bash
curl -X POST http://localhost:8080/api/v1/configs/tool_servers/verify \
  -H 'Authorization: Bearer <admin_api_key>' \
  -H 'Content-Type: application/json' \
  -d '{"url":"https://lavarel.eastus2.cloudapp.azure.com/mcp","path":"/","type":"mcp","auth_type":"none","key":"","config":{}}'
# Expected: {"status": true, "specs": [...3 tools...]}
```

### Validation Agent

A model named **Vascular MCP Validation** (`id: vascular-mcp-validation`) has been created in
OpenWebUI. It uses `gpt-5-chat` as the base model with:
- `toolIds: ["server:mcp:6a97362f-5da7-4e19-99d2-323a48874ed6"]` — MCP server tools only
- No `vascular_expert.py` Python tool attached
- System prompt: validation-mode instructions (report raw tool output)

Use this agent for manual testing before cutover.

---

## Running the Tests

### Unit tests (no external services required)

```bash
cd /home/azureuser/vascular-mcp
source .venv/bin/activate
python -m pytest tests/test_context_gate.py -v
```

### Integration tests (requires Laravel API on port 8001)

```bash
python -m pytest tests/test_integration.py -v
```

### MCP protocol tests (requires running vascular-mcp.service)

```bash
python -m pytest tests/test_mcp_protocol.py -v
```

### Full suite

```bash
python -m pytest tests/ -q
# Expected: 43 passed
```

---

## Manual Validation Checklist

Before cutover, confirm these queries via the **Vascular MCP Validation** agent in OpenWebUI:

**Knowledge questions** (should return guideline evidence directly):
- [ ] "What is the diameter threshold for elective AAA repair in fit patients?"
- [ ] "What is the Rutherford classification for acute limb ischaemia?"
- [ ] "What are the ESVS recommendations for CEA timing after TIA?"
- [ ] "What anticoagulation is recommended after DVT in cancer patients?"

**Patient-case gate** (should ask for missing context):
- [ ] "Patient with carotid stenosis" → gate asks for symptomatic status + degree
- [ ] "Patient with AAA found on ultrasound" → gate asks for diameter + fitness
- [ ] "Patient with DVT" → gate asks for provoking factors + episode history

**Patient-case with full context** (gate should PROCEED, then return evidence):
- [ ] "75-year-old man with 70% carotid stenosis and recent TIA. Should he have CEA?"
- [ ] "Patient with DVT after long-haul flight, first episode, no cancer"
- [ ] "Patient with 5.8 cm AAA, fit for surgery. EVAR or open repair?"

**Follow-up turns** (should not re-fire the gate):
- [ ] Start a case, get an answer, then ask: "What about surveillance after CEA?"

---

## Cutover (Section 9)

Once manual validation passes, disable the old `vascular_expert.py` tool:

```bash
# On 48 VM
sudo docker exec open-webui python3 -c "
import sqlite3
conn = sqlite3.connect('/app/backend/data/webui.db')
# Verify current state first
row = conn.execute(\"SELECT id, is_active FROM tool WHERE id='mcp'\").fetchone()
print('Before:', row)
# Disable
conn.execute(\"UPDATE tool SET is_active=0 WHERE id='mcp'\")
conn.commit()
row = conn.execute(\"SELECT id, is_active FROM tool WHERE id='mcp'\").fetchone()
print('After:', row)
conn.close()
"
sudo docker restart open-webui
```

To roll back (re-enable `vascular_expert.py`):
```bash
sudo docker exec open-webui python3 -c "
import sqlite3
conn = sqlite3.connect('/app/backend/data/webui.db')
conn.execute(\"UPDATE tool SET is_active=1 WHERE id='mcp'\")
conn.commit()
conn.close()
"
sudo docker restart open-webui
```

---

## Service Management

```bash
# 135 VM
sudo systemctl status vascular-mcp.service
sudo systemctl restart vascular-mcp.service
sudo journalctl -u vascular-mcp.service -f

sudo systemctl status caddy.service
sudo journalctl -u caddy.service -f

# Quick smoke test
curl -X POST https://lavarel.eastus2.cloudapp.azure.com/mcp \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","method":"tools/list","id":1}'
# Expected: SSE event with 3 tools
```

---

## Known Limitations

- **Multi-turn tool calls in API mode**: When calling the OpenWebUI completions API directly
  (not via the chat UI), responses with two sequential tool calls (gate PROCEED → retrieval)
  may return at the intermediate state. This is an API-mode artefact; the full pipeline
  executes correctly in the OpenWebUI chat interface.
- **MCP SDK version**: OpenWebUI uses mcp 1.25.0; the server uses mcp 1.26.0. No known
  compatibility issues observed.
- **No bearer auth on `/mcp`**: The endpoint is open (no API key). Port 8080 is blocked by
  Azure NSG; the only external access is through Caddy on 443. Add bearer auth if the NSG
  rule is ever opened.
