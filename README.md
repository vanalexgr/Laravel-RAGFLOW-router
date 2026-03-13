# Laravel RAGFlow Router — ESVS Vascular Guidelines API

A Laravel 12 API that routes clinical questions to ESVS (European Society for Vascular Surgery) guideline documents in RAGFlow, retrieves graded evidence chunks, and returns structured clinical responses for synthesis by an LLM.

---

## Architecture

```
Client (OpenWebUI / Codex / API)
  └─ MCP server (FastMCP, port 8080, 135 VM)
       └─ POST /api/v1/vascular-consult (Laravel, 135 VM)
            ├─ PHI scrubbing
            ├─ Guideline router (Azure OpenAI → selects dataset + expands query)
            ├─ RAGFlow retrieval (bridge on port 8000)
            ├─ Gap detection (optional second pass)
            └─ Returns: citation_chunks, narrative_chunks, assets, query_normalization
```

### VMs

| Component | Host | SSH |
|---|---|---|
| Laravel API + MCP server | `135.237.148.105` | `ssh -i ~/LAVAREL.pem azureuser@135.237.148.105` |
| OpenWebUI + RAGFlow | `48.211.217.69` | `ssh -i ~/ragflownew.pem azureuser@48.211.217.69` |

---

## MCP Server

Located at `/home/azureuser/vascular-mcp/` on the 135 VM. Served at port 8080 via systemd; Caddy proxies `/mcp*` → 8080.

### Tools

| Tool | Purpose |
|---|---|
| `vascular_consult_guidelines` | **Primary tool.** Retrieves ESVS evidence. Includes a built-in context gate — for patient cases with missing clinical details it returns clarification questions instead of retrieving; call again with complete info. |
| `vascular_list_guidelines` | Lists all 14 available guideline datasets with keys, labels, and descriptions. |

`vascular_assess_context_gaps` is an internal Python function that powers the gate inside `vascular_consult_guidelines`. It is **not registered as an MCP tool** and is not visible to clients.

---

## Client Configuration: Which Tool Config to Use

This is the most important thing to get right when setting up a new client.

### The problem with two-tool chaining

`vascular_assess_context_gaps` (gate) → `vascular_consult_guidelines` (retrieval) is a two-step sequence. In practice, many LLM clients (including OpenWebUI with GPT-class models) call the gate, receive PROCEED, and then **output the second tool call as plain text** rather than making a real function call. The turn closes with no retrieval and no answer.

### All clients: use `vascular_consult_guidelines` directly

The server exposes **2 tools**. The context gate is built into `vascular_consult_guidelines` — there is no separate gate tool to call first. For every query type:

- **Knowledge questions** (definitions, thresholds, classifications): tool retrieves immediately.
- **Patient cases with sufficient context**: tool retrieves immediately.
- **Patient cases with missing context**: tool returns a clarification request (Markdown string beginning with `**Additional information needed**`). Present the questions to the user, then call the tool again with the complete information. Pass all prior turns in the `history` parameter so the gate knows what was already collected.

**Background — why there is no separate gate tool:**
An earlier design exposed `vascular_assess_context_gaps` as a third MCP tool for explicit two-step gate control. In practice, LLM clients (including GPT-class models in OpenWebUI) called the gate, received a PROCEED response, and then output the next tool call as **plain text** instead of making an actual function call — leaving the turn with no retrieval and no answer. Merging the gate into `vascular_consult_guidelines` eliminates the chaining requirement entirely.

OpenWebUI model: **"Vascular MCP (Agent)"** (`vascular-mcp-agent` in DB, base: `gpt-5-chat`)

### Decision guide

```
Any client (chat interface, agent, API, Codex):
  └─ Call vascular_consult_guidelines with the full query.
     If the response starts with "**Additional information needed**":
       └─ Present the questions to the user.
          Call vascular_consult_guidelines again with the complete information.
          Include prior turns in the history parameter.
     Otherwise:
       └─ Synthesise the returned evidence into a clinical response.
```

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

## Built-in Context Gate — Covered Scenarios

The gate runs inside `vascular_consult_guidelines` (and optionally via `vascular_assess_context_gaps`). It checks for missing clinical parameters before retrieval and asks clarification questions when needed.

| Scenario | Detects | Asks about |
|---|---|---|
| `carotid_stenosis` | carotid stenosis, CEA, CAS | Symptomatic status, stenosis degree (NASCET %) |
| `aaa_treatment` | AAA, aortic aneurysm | Aneurysm diameter, patient fitness |
| `dvt_pe` | DVT, PE, VTE | Provoking factors, prior VTE history, DVT location (proximal vs distal) |
| `ali` | Acute limb ischaemia | Rutherford class + duration, aetiology (thrombotic vs embolic), occlusion level |
| `clti` | CLTI, critical limb, rest pain, tissue loss | Anatomical workup (duplex/CTA), patient fitness |
| `type_b_dissection` | Type B aortic dissection | Complicated vs uncomplicated, phase (acute/subacute/chronic) |
| `graft_infection` | Graft / prosthesis infection | Clinical signs (fever, wound breakdown), prosthesis type + timing |

The gate fires only when **two or more** required parameters are absent. A case with partial context (e.g. carotid stenosis with symptomatic status present but no stenosis degree) does not trigger it.

---

## Running the Test Suite

```bash
# On 135 VM:
cd /home/azureuser/vascular-mcp
source .env && export LARAVEL_BASE_URL LARAVEL_API_KEY
.venv/bin/pytest tests/ -v

# Tests require laravel-api.service and vascular-mcp.service running
```

56 tests: unit gate logic, integration against live Laravel API, MCP protocol compliance.

---

## Deployment

### MCP server update

```bash
scp -i ~/LAVAREL.pem vascular_mcp/server.py azureuser@135.237.148.105:/home/azureuser/vascular-mcp/server.py
ssh -i ~/LAVAREL.pem azureuser@135.237.148.105 'sudo systemctl restart vascular-mcp.service'
```

### Laravel backend update

```bash
# Push changes, then on 135 VM:
php artisan config:cache
sudo systemctl restart laravel-api.service
```

### OpenWebUI tool update

The live tool (`mcp` ID in OpenWebUI DB) runs from SQLite, not from the filesystem:

```bash
scp -i ~/ragflownew.pem openwebui_tools/vascular_expert.py azureuser@48.211.217.69:/tmp/vascular_expert_new.py
ssh -i ~/ragflownew.pem azureuser@48.211.217.69 "
  sudo docker cp /tmp/vascular_expert_new.py open-webui:/tmp/vascular_expert_new.py &&
  sudo docker exec open-webui python3 /tmp/push_tool_content.py &&
  sudo docker restart open-webui
"
```

---

## Key Files

| File | Purpose |
|---|---|
| `vascular_mcp/server.py` | FastMCP server — 3 tools, context gate, narrative formatter, STRICT_TEMPLATE |
| `vascular_mcp/tests/` | Test suite (unit, integration, MCP protocol) |
| `openwebui_tools/vascular_expert.py` | Legacy OpenWebUI tool (old two-step pattern, kept for reference) |
| `routes/api.php` | Laravel route: `POST /api/v1/vascular-consult` |
| `app/Services/RetrievalService.php` | Retrieval pipeline orchestration |
| `app/Services/GuidelineRouterService.php` | LLM-based guideline selection + query expansion |
| `config/ragflow.php` | Retrieval tuning parameters |
| `validation/phase3_results.md` | Phase 3 cross-client validation results |

---

## Known Gaps

- `descending_thoracic_aorta` has no figures/tables in `config/guideline_assets.php`
- 3 minor bugs in `PHIScrubberService` (low risk, not yet fixed — see `CLAUDE.md`)
- Codex baseline not yet established (Phase 3 validation pending)
