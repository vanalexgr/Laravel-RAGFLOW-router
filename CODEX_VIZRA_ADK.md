# Codex Task: Vizra ADK Local Agent Orchestration

## Read this before doing anything
Read `/home/vga/.claude/plans/vizra-adk-implementation.md` for full context.
That file is the authoritative specification. This file is the checklist.

---

## What you are doing (summary)

Installing Vizra ADK into the existing Laravel app (135 VM) and building a `VascularConsultAgent`
that orchestrates clinical guideline retrieval as a true agentic workflow. The agent replaces the
complex Python orchestration in `vascular_mcp_adapter.py` while keeping the same OpenWebUI tool
interface. The Python adapter shrinks to ~80 lines (pure HTTP bridge + citation emitter).

**Key principle:** The Vizra agent LLM does the clinical synthesis. The Python tool returns the
agent's response wrapped in a verbatim pass-through instruction so gpt-5-chat does not reformat it.

**The 135 VM Laravel app is the ONLY backend that changes.**
The 48 VM (OpenWebUI) gets only a new Python adapter pushed to the DB.

---

## Architecture

```
OpenWebUI (gpt-5-chat, "ESVS expert")
  → calls consult_vascular_guidelines tool
  → vascular_agent_adapter.py (new, ~80 lines)
      → POST /api/v1/agent-consult  {question, session_key, guidelines[], history}
          → AgentConsultController (Laravel, 135 VM)
              → VascularConsultAgent (Vizra ADK)
                  [LLM: DeepSeek V3 via Prism — configurable]
                  [System prompt: clinical intelligence, clarification gate, STRICT_TEMPLATE]
                  [Tool: RetrieveClinicalEvidenceTool]
                      → RetrievalService::retrieve(question, history, guidelineKeys)
                          → GuidelineRouterService  (query expansion)
                          → RAGFlow embeddings + retrieval
                          → GapDetectionService
                          → GuidelineAssetService
                      ← returns {citation_chunks, narrative_chunks, assets, has_gap, ...}
                  [Agent synthesises into STRICT_TEMPLATE format]
              ← returns JSON {response, citations[], assets[], mode, session_key}
      ← Python emits citation events per chunk via __event_emitter__
      ← returns "Present verbatim:\n\n{response}"
  → gpt-5-chat passes through verbatim
```

---

## Rules

1. Do NOT modify `openwebui_tools/vascular_mcp_adapter.py` — production v1.5.53 stays untouched.
2. Do NOT modify `openwebui_tools/vascular_expert.py` (id=`mcp`) — never touch this.
3. Do NOT modify any existing Laravel routes/controllers/services.
4. Do NOT run `git commit` — only prepare files.
5. Do NOT push adapter to OpenWebUI DB — only create files; deployment is manual.

---

## Files to create (checklist)

### Laravel (135 VM app — `/home/azureuser/laravel-ragflow/`)
- [ ] Run `composer require vizra/vizra-adk` (check if already installed first)
- [ ] Run `php artisan vizra:install` (publishes config + migrations)
- [ ] Run `php artisan migrate`
- [ ] `app/Agents/Tools/RetrieveClinicalEvidenceTool.php`
- [ ] `app/Agents/VascularConsultAgent.php`
- [ ] `app/Http/Controllers/AgentConsultController.php`
- [ ] `resources/prompts/vascular_agent_system.md`
- [ ] Add route to `routes/api.php` (one line)
- [ ] Add env vars to `.env.example`

### OpenWebUI adapter (local repo, 48 VM deploys later)
- [ ] `openwebui_tools/vascular_agent_adapter.py`
- [ ] `openwebui_tools/push_agent_adapter.py`

---

## Files that must NOT be touched

- `openwebui_tools/vascular_mcp_adapter.py`
- `openwebui_tools/vascular_expert.py`
- `app/Services/RetrievalService.php`
- `app/Services/GuidelineRouterService.php`
- `app/Services/GapDetectionService.php`
- `app/Services/GuidelineAssetService.php`
- `app/Http/Controllers/ToolController.php`
- `routes/api.php` (except the one new route line)
- `ragflow_service/app.py`
- Any MCP server files (`app/Mcp/`)

---

## Step 0 — Safety net

```bash
cd /home/vga/LAVAREL/Laravel-RAGFLOW-router
git tag v1.5.53-pre-vizra
git push origin v1.5.53-pre-vizra
```

---

## Step 1 — Install Vizra ADK

SSH to 135 VM (`ssh -i ~/LAVAREL.pem azureuser@135.237.148.105`):

```bash
cd /home/azureuser/laravel-ragflow
composer require vizra/vizra-adk
php artisan vizra:install
php artisan migrate
```

Verify the install created:
- `config/vizra.php`
- Migration tables in DB (vizra_sessions, vizra_messages, vizra_traces or similar)
- `php artisan vizra:make:agent` command exists

---

## Step 2 — Read these files before writing any PHP

On 135 VM, read:
- `app/Services/RetrievalService.php` lines 1–60 — confirm method signature:
  `retrieve(string $question, array $history = [], ?array $requestedKeys = null): array`
- `app/Services/RetrievalService.php` — understand the return array shape:
  look for keys: `llm_citation_chunks`, `llm_narrative_chunks`, `assets`,
  `has_guideline_gap`, `uncovered_facets`, `query_normalization`
- `config/vizra.php` — understand the LLM provider config format after install
- `app/Http/Middleware/ValidateApiKey.php` — understand how the API key middleware works
  so the new route uses the same auth

---

## Step 3 — `resources/prompts/vascular_agent_system.md`

Create this file on the 135 VM. It is the Vizra agent's system prompt.
Content is adapted from `openwebui_tools/vascular_mcp_adapter.py` (local repo).

Read the following from `vascular_mcp_adapter.py` to extract content:
- The `consult_vascular_guidelines` docstring — all 14 guideline keys with descriptions
- `_CONTEXT_GAP_RULES` dict — clarification gate scenario rules
- `_build_two_layer_blueprint()` method — STRICT_TEMPLATE section structure
- All synthesis rules in the `llm_output` construction (search for "DECISION-FIRST",
  "DOMINANT MODIFIER", "LIFE-THREATENING PRIORITY", "CLINICAL SEQUENCE", "SCOPE FILTER")
- The gap detection mode rules (COMPACT / STANDARD / FULL — 6 classifier rules)

**Required sections in the system prompt:**

### A. Identity
```
You are an ESVS (European Society for Vascular Surgery) clinical decision-support assistant.
Answer questions about vascular surgery ONLY using evidence retrieved from the
retrieve_clinical_evidence tool. Never supplement from internal knowledge.
If no evidence is retrieved, state this explicitly.
```

### B. All 14 guideline keys (verbatim from docstring)
aortic_arch, descending_thoracic_aorta, abdominal_aortic_aneurysm, mesenteric_renal,
asymptomatic_pad, clti, acute_limb_ischaemia, carotid_vertebral, venous_thrombosis,
chronic_venous_disease, antithrombotic_therapy, vascular_trauma, vascular_graft_infections,
vascular_access — each with its description.

### C. Clarification gate (from `_CONTEXT_GAP_RULES`)
Ask ONE round of clarification (never more) before calling the tool when ALL are true:
- The question is a patient-specific case (not a threshold/knowledge question)
- Critical clinical parameters are absent (see scenario rules below)
- You have not already asked about this case in the session

Scenario rules (extract verbatim from `_CONTEXT_GAP_RULES`):
aortic_thrombus, carotid_stenosis, aaa_treatment, dvt_pe, clti, svt,
type_b_dissection, ali, graft_infection, generic catch-all.

After clarification: call the tool with all gathered context.
Same-case follow-ups: do NOT re-ask. Use session history.

### D. Tool call instruction
Call `retrieve_clinical_evidence` with:
- `question` = full clinical question including all context from session
- `guideline_keys` = array of relevant guideline keys (1–3 max)

### E. Response format — STRICT_TEMPLATE (extract from adapter)
Mode selection rules (6 rules, priority order):
1. FULL — has_guideline_gap=true AND uncovered_facets non-empty
2. FULL — multiple treatment options with meaningful trade-offs
3. COMPACT — negative indication (answer is "no" or "defer")
4. COMPACT — single clear guideline-supported path
5. STANDARD — restricted (patient/anatomical modifier changes standard recommendation)
6. STANDARD — modifier (timing/severity/comorbidity affects treatment)

Declare mode as FIRST line: `**Mode:** COMPACT|STANDARD|FULL — Rule N — [reason]`

COMPACT structure (max 5 bullets):
```
**Mode:** COMPACT — Rule N — [reason]
## Clinical Decision
- [recommendation, 1-2 bullets]
## What is NOT indicated
- [excluded options, max 2 bullets]
## Evidence Used
- Rec [ID] (Class X, Level Y) — [one line]
```

STANDARD / FULL structure:
```
**Mode:** STANDARD|FULL — Rule N — [reason]
## Assessment
## Imaging / Workup (if relevant)
## Indication for Intervention (if relevant)
## Treatment Options
## Perioperative / Follow-up (if relevant)
## Clinical Decision Summary (STANDARD+)
## Evidence Used
## Guideline Gap (FULL only, when has_guideline_gap=true)
## Clinical Practice Guidance (FULL only, gap cases)
```

### F. Synthesis rules (mandatory, extract verbatim from adapter)
DECISION-FIRST, DOMINANT MODIFIER RULE, LIFE-THREATENING PRIORITY,
CLINICAL SEQUENCE, NEGATIVE INDICATION FRAMING, SCOPE FILTER.

### G. Out-of-scope
Non-vascular / general knowledge / model-meta questions:
respond ONLY with a brief explanation of what this app does. Do not answer off-topic questions.

---

## Step 4 — `app/Agents/Tools/RetrieveClinicalEvidenceTool.php`

```php
<?php

namespace App\Agents\Tools;

use App\Services\RetrievalService;
use Vizra\Adk\Tools\BaseTool;
use Vizra\Adk\AgentContext;

class RetrieveClinicalEvidenceTool extends BaseTool
{
    protected string $name = 'retrieve_clinical_evidence';
    protected string $description = 'Retrieve ESVS vascular surgery guideline evidence for a clinical question. Returns citation chunks, narrative evidence, and clinical assets.';

    protected array $parameters = [
        'type' => 'object',
        'properties' => [
            'question' => [
                'type' => 'string',
                'description' => 'The full clinical question, including all patient context gathered from the conversation.',
            ],
            'guideline_keys' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Array of 1–3 guideline keys to search. Valid values: aortic_arch, descending_thoracic_aorta, abdominal_aortic_aneurysm, mesenteric_renal, asymptomatic_pad, clti, acute_limb_ischaemia, carotid_vertebral, venous_thrombosis, chronic_venous_disease, antithrombotic_therapy, vascular_trauma, vascular_graft_infections, vascular_access',
            ],
        ],
        'required' => ['question'],
    ];

    public function __construct(protected RetrievalService $retrieval) {}

    public function execute(array $parameters, AgentContext $context): mixed
    {
        $question     = $parameters['question'] ?? '';
        $guidelineKeys = $parameters['guideline_keys'] ?? null;
        $history      = $context->conversationHistory() ?? [];

        // Format history as array of strings ["role: content", ...]
        $historyLines = array_map(
            fn($msg) => ($msg['role'] ?? 'user') . ': ' . (is_string($msg['content']) ? $msg['content'] : ''),
            array_slice($history, -10)
        );

        $result = $this->retrieval->retrieve($question, $historyLines, $guidelineKeys);

        // Store raw result in context state for controller to extract
        $context->setState('last_tool_result', [
            'citation_chunks'  => $result['llm_citation_chunks']  ?? $result['citation_chunks']  ?? [],
            'narrative_chunks' => $result['llm_narrative_chunks'] ?? $result['narrative_chunks'] ?? [],
            'assets'           => $result['assets']               ?? [],
            'has_gap'          => $result['has_guideline_gap']    ?? false,
            'uncovered_facets' => $result['uncovered_facets']     ?? [],
        ]);

        // Return the full result to the agent LLM for synthesis
        return $result;
    }
}
```

**Important:** Before writing this file, verify:
- The actual Vizra ADK base class name and namespace (check `vendor/vizra/vizra-adk/src/Tools/`)
- The actual `AgentContext` method for conversation history (check `AgentContext` class)
- The actual method for `setState` / state storage in context
Adjust namespace and method names to match the installed SDK exactly.

---

## Step 5 — `app/Agents/VascularConsultAgent.php`

```php
<?php

namespace App\Agents;

use App\Agents\Tools\RetrieveClinicalEvidenceTool;
use Vizra\Adk\Agents\BaseLlmAgent;

class VascularConsultAgent extends BaseLlmAgent
{
    protected string $name = 'vascular-consult-agent';

    protected string $model = '';  // set from config: vizra.agents.vascular_consult.model

    protected array $tools = [
        RetrieveClinicalEvidenceTool::class,
    ];

    protected function systemPrompt(): string
    {
        $path = resource_path('prompts/vascular_agent_system.md');
        return file_exists($path) ? file_get_contents($path) : '';
    }
}
```

**Check the actual Vizra ADK base class API** by reading:
- `vendor/vizra/vizra-adk/src/Agents/BaseLlmAgent.php` — how to set model, tools, system prompt
- Whether `model` is a property or loaded from config
- Whether tools are registered as class names or instances
Adjust the class to match exactly.

---

## Step 6 — `app/Http/Controllers/AgentConsultController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Agents\VascularConsultAgent;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AgentConsultController extends Controller
{
    public function __invoke(Request $request, VascularConsultAgent $agent): JsonResponse
    {
        $request->validate([
            'question'    => 'required|string|max:2000',
            'session_key' => 'required|string|max:64',
            'guidelines'  => 'nullable|array|max:3',
            'guidelines.*'=> 'string',
            'history'     => 'nullable|array|max:20',
        ]);

        $question   = $request->input('question');
        $sessionKey = $request->input('session_key');
        $guidelines = $request->input('guidelines', []);

        // Append guideline hints to question if provided
        if (!empty($guidelines)) {
            $question .= "\n\n[Guideline hints: " . implode(', ', $guidelines) . "]";
        }

        // Run agent — Vizra manages session history by sessionKey
        $agentResponse = $agent->run(
            input: $question,
            sessionId: $sessionKey,
        );

        // Extract tool result from agent context (stored by RetrieveClinicalEvidenceTool)
        // Check Vizra ADK API for how to access context state after run()
        $toolResult = $agentResponse->context?->getState('last_tool_result') ?? [];

        return response()->json([
            'response'  => $agentResponse->text(),
            'citations' => $toolResult['citation_chunks']  ?? [],
            'assets'    => $toolResult['assets']           ?? [],
            'narratives'=> $toolResult['narrative_chunks'] ?? [],
            'has_gap'   => $toolResult['has_gap']          ?? false,
            'mode'      => $this->extractMode($agentResponse->text()),
        ]);
    }

    private function extractMode(string $text): string
    {
        if (preg_match('/\*\*Mode:\*\*\s*(COMPACT|STANDARD|FULL)/i', $text, $m)) {
            return strtoupper($m[1]);
        }
        return 'STANDARD';
    }
}
```

**Important:** Before writing this file, check the actual Vizra ADK API:
- `vendor/vizra/vizra-adk/src/Agents/BaseLlmAgent.php` — what does `run()` return?
- What is the return type? (AgentResponse? string? object?)
- How do you access context state after `run()`?
Adjust method calls to match the actual return API.

---

## Step 7 — Add route to `routes/api.php`

Add ONE line after the existing `POST /api/v1/vascular-consult` route:

```php
Route::post('/api/v1/agent-consult', \App\Http\Controllers\AgentConsultController::class)
    ->middleware('validate.api.key');
```

Use the SAME middleware name that the existing `/api/v1/vascular-consult` route uses.
Check the existing route definition to get the exact middleware alias.

---

## Step 8 — Add config to `config/vizra.php`

After `php artisan vizra:install`, edit the published config to add vascular agent settings:

```php
'agents' => [
    'vascular_consult' => [
        'model'    => env('VASCULAR_AGENT_MODEL', 'deepseek/deepseek-chat'),
        'provider' => env('VASCULAR_AGENT_PROVIDER', 'deepseek'),
    ],
],
```

Add to `.env.example`:
```
VASCULAR_AGENT_MODEL=deepseek/deepseek-chat  # LLM for agent synthesis (via Prism)
VASCULAR_AGENT_PROVIDER=deepseek             # Prism provider key
DEEPSEEK_API_KEY=                            # DeepSeek API key
```

Check `config/vizra.php` structure after install to understand where to add this.
Also check Prism's provider config format for DeepSeek integration.

---

## Step 9 — `openwebui_tools/vascular_agent_adapter.py`

This replaces `vascular_mcp_adapter.py` in the OpenWebUI DB.
It is a pure HTTP bridge — no clinical logic, no session state, no template building.

```python
"""
title: Vascular Agent Adapter
author: open-webui
version: 3.0.0
description: Thin bridge to the Vizra ADK VascularConsultAgent on the Laravel backend.
"""
import hashlib
import httpx
from typing import Optional, Callable, Awaitable
from pydantic import BaseModel, Field


VALID_GUIDELINE_KEYS = {
    "aortic_arch", "descending_thoracic_aorta", "abdominal_aortic_aneurysm",
    "mesenteric_renal", "asymptomatic_pad", "clti", "acute_limb_ischaemia",
    "carotid_vertebral", "venous_thrombosis", "chronic_venous_disease",
    "antithrombotic_therapy", "vascular_trauma", "vascular_graft_infections",
    "vascular_access",
}


def _session_key(user: dict, metadata: dict) -> str:
    uid  = str(user.get("id", "anon"))
    chat = str(metadata.get("chat_id", metadata.get("conversation_id", "nochat")))
    return hashlib.sha256(f"{uid}:{chat}".encode()).hexdigest()[:24]


class Tools:

    class Valves(BaseModel):
        LARAVEL_URL : str = Field(default="https://lavarel.eastus2.cloudapp.azure.com", description="Laravel API base URL")
        API_KEY     : str = Field(default="", description="Laravel API key (X-API-Key header)")
        TIMEOUT     : int = Field(default=120, description="Request timeout in seconds")

    def __init__(self):
        self.valves = self.Valves()

    async def _emit_status(self, emitter, message: str, done: bool = False):
        if emitter:
            await emitter({"type": "status", "data": {"description": message, "done": done}})

    async def _emit_citations(self, emitter, citations: list, narratives: list, assets: list):
        if not emitter:
            return
        idx = 1
        for chunk in citations:
            rec_id   = str(chunk.get("recommendation_id") or chunk.get("rec_id") or "").strip()
            cls      = str(chunk.get("class") or "").strip()
            level    = str(chunk.get("level") or "").strip()
            guideline= str(chunk.get("guideline") or chunk.get("source_guideline") or "ESVS").strip()
            text     = str(chunk.get("text") or chunk.get("content") or "").strip()
            title    = f"Rec {rec_id} from {guideline}" if rec_id else f"Recommendation from {guideline}"
            if cls or level:
                title += f" — Class {cls or 'N/A'}, Level {level or 'N/A'}"
            await emitter({"type": "citation", "data": {
                "document": [text],
                "metadata": [{"source": title, "guideline": guideline,
                               "recommendation_id": rec_id, "class": cls, "level": level}],
                "source": {"id": str(idx), "name": title},
            }})
            idx += 1
        for chunk in narratives:
            guideline = str(chunk.get("source_guideline") or chunk.get("guideline") or "ESVS").strip()
            text      = str(chunk.get("content") or "").strip()
            title     = f"{guideline} — supporting evidence"
            await emitter({"type": "citation", "data": {
                "document": [text],
                "metadata": [{"source": title, "guideline": guideline, "kind": "narrative"}],
                "source": {"id": str(idx), "name": title},
            }})
            idx += 1
        for asset in assets:
            name = str(asset.get("title") or asset.get("name") or "Figure").strip()
            url  = str(asset.get("url") or "").strip()
            await emitter({"type": "citation", "data": {
                "document": [name],
                "metadata": [{"source": name, "url": url, "kind": "asset"}],
                "source": {"id": str(idx), "name": name, "url": url},
            }})
            idx += 1

    async def consult_vascular_guidelines(
        self,
        question     : str,
        guideline_1  : Optional[str] = None,
        guideline_2  : Optional[str] = None,
        guideline_3  : Optional[str] = None,
        __messages__ : list = [],
        __user__     : dict = {},
        __metadata__ : dict = {},
        __event_emitter__: Callable[[dict], Awaitable[None]] = None,
    ) -> str:
        """
        Consult ESVS vascular surgery guidelines via the VascularConsultAgent.
        Use for any clinical question about vascular conditions, procedures, or management.

        guideline_1/2/3: optional guideline hints — valid keys:
        aortic_arch, descending_thoracic_aorta, abdominal_aortic_aneurysm,
        mesenteric_renal, asymptomatic_pad, clti, acute_limb_ischaemia,
        carotid_vertebral, venous_thrombosis, chronic_venous_disease,
        antithrombotic_therapy, vascular_trauma, vascular_graft_infections, vascular_access
        """
        if not self.valves.API_KEY:
            return "Configuration error: API_KEY not set in tool valves."

        emitter = __event_emitter__
        await self._emit_status(emitter, "Consulting ESVS guidelines…")

        session_key = _session_key(__user__, __metadata__)
        hints = [g for g in [guideline_1, guideline_2, guideline_3]
                 if g and g in VALID_GUIDELINE_KEYS]

        try:
            async with httpx.AsyncClient(timeout=self.valves.TIMEOUT) as client:
                resp = await client.post(
                    f"{self.valves.LARAVEL_URL.rstrip('/')}/api/v1/agent-consult",
                    json={
                        "question"   : question,
                        "session_key": session_key,
                        "guidelines" : hints,
                    },
                    headers={
                        "X-API-Key"   : self.valves.API_KEY,
                        "Accept"      : "application/json",
                        "Content-Type": "application/json",
                    },
                )
                resp.raise_for_status()
                data = resp.json()

        except httpx.TimeoutException:
            await self._emit_status(emitter, "Timeout", done=True)
            return "The guideline retrieval timed out. Please try again."
        except Exception as exc:
            await self._emit_status(emitter, f"Error: {str(exc)[:80]}", done=True)
            return f"Error consulting guidelines: {exc}"

        # Emit citations, narratives, assets to OpenWebUI sidebar
        await self._emit_citations(
            emitter,
            data.get("citations", []),
            data.get("narratives", []),
            data.get("assets", []),
        )
        await self._emit_status(emitter, "Done", done=True)

        response_text = data.get("response", "No response from agent.")

        # Wrap in pass-through instruction so gpt-5-chat does not reformat
        return (
            "Present the following ESVS clinical decision support output to the user "
            "VERBATIM — do not rephrase, reformat, or add any text of your own:\n\n"
            + response_text
        )

    async def explain_app_capabilities(
        self,
        question     : str = "",
        __messages__ : list = [],
        __user__     : dict = {},
        __metadata__ : dict = {},
        __event_emitter__: Callable[[dict], Awaitable[None]] = None,
    ) -> str:
        """
        Explain what this ESVS guideline app does and how to use it.
        Use for onboarding, out-of-scope, non-vascular, or model-meta requests.
        """
        return await self.consult_vascular_guidelines(
            question=question or "Explain what this vascular guideline app does and does not do.",
            __messages__=__messages__,
            __user__=__user__,
            __metadata__=__metadata__,
            __event_emitter__=__event_emitter__,
        )
```

---

## Step 10 — `openwebui_tools/push_agent_adapter.py`

```python
"""
Push vascular_agent_adapter.py to OpenWebUI DB.
Run inside open-webui container:
    python3 /tmp/push_agent_adapter.py
Source file must be at /tmp/vascular_agent_adapter.py
"""
import sqlite3

TOOL_ID = "vascular_mcp_adapter"   # same production ID — replaces v1 in-place

with open("/tmp/vascular_agent_adapter.py", "r") as f:
    new_content = f.read()

conn = sqlite3.connect("/app/backend/data/webui.db")
conn.execute(
    "UPDATE tool SET content=?, updated_at=strftime('%s','now') WHERE id=?",
    (new_content, TOOL_ID),
)
conn.commit()

row = conn.execute("SELECT length(content) FROM tool WHERE id=?", (TOOL_ID,)).fetchone()
print(f"SUCCESS: {TOOL_ID} content length = {row[0]}")

content = conn.execute("SELECT content FROM tool WHERE id=?", (TOOL_ID,)).fetchone()[0]
checks = [
    ("version: 3.0.0",              "version: 3.0.0"          in content),
    ("no import anthropic",         "import anthropic"         not in content),
    ("_session_key function",       "def _session_key"         in content),
    ("_emit_citations method",      "_emit_citations"          in content),
    ("agent-consult endpoint",      "agent-consult"            in content),
    ("verbatim wrapper",            "VERBATIM"                 in content),
    ("VALID_GUIDELINE_KEYS",        "VALID_GUIDELINE_KEYS"     in content),
]
for name, ok in checks:
    print(f"  {'OK' if ok else 'MISSING'}: {name}")
conn.close()
```

---

## Step 11 — Verify syntax

```bash
# Local
python3 -m py_compile openwebui_tools/vascular_agent_adapter.py && echo ADAPTER_OK
python3 -m py_compile openwebui_tools/push_agent_adapter.py && echo PUSH_OK

# On 135 VM
php artisan route:list --path=agent-consult
php artisan test --filter=AgentConsult 2>/dev/null || echo "No tests yet — create them"
```

---

## Deployment (manual, after Codex creates files)

### On 135 VM:
```bash
ssh -i ~/LAVAREL.pem azureuser@135.237.148.105
cd /home/azureuser/laravel-ragflow
php artisan config:cache
sudo systemctl restart laravel-api.service
# Add DEEPSEEK_API_KEY + VASCULAR_AGENT_MODEL to .env, then:
php artisan config:cache
```

### On 48 VM:
```bash
scp -i ~/ragflownew.pem openwebui_tools/vascular_agent_adapter.py \
    azureuser@48.211.217.69:/tmp/vascular_agent_adapter.py
scp -i ~/ragflownew.pem openwebui_tools/push_agent_adapter.py \
    azureuser@48.211.217.69:/tmp/push_agent_adapter.py
ssh -i ~/ragflownew.pem azureuser@48.211.217.69 "
  sudo docker cp /tmp/vascular_agent_adapter.py open-webui:/tmp/vascular_agent_adapter.py &&
  sudo docker cp /tmp/push_agent_adapter.py     open-webui:/tmp/push_agent_adapter.py &&
  sudo docker exec open-webui python3 /tmp/push_agent_adapter.py &&
  sudo docker restart open-webui
"
```

### Rollback (instant):
```bash
# Restore v1.5.53 to DB:
ssh -i ~/ragflownew.pem azureuser@48.211.217.69 "
  sudo docker exec open-webui python3 -c \"
import sqlite3
with open('/app/backend/data/vascular_adapter_v1.5.53_backup.py') as f: c = f.read()
conn = sqlite3.connect('/app/backend/data/webui.db')
conn.execute(\\\"UPDATE tool SET content=? WHERE id='vascular_mcp_adapter'\\\", (c,))
conn.commit()
print('Rolled back to v1.5.53')
\"
  sudo docker restart open-webui
"
```

---

## Verification (end-to-end after deployment)

1. **Basic retrieval:**
   Ask: "What diameter AAA requires EVAR?"
   Expected: Direct answer in ≤30s, citation pills in sidebar, mode line visible.

2. **Clarification gate:**
   Ask: "My patient has a carotid stenosis"
   Expected: Agent asks for symptomatic status + stenosis degree before calling tool.

3. **Follow-up (session memory):**
   After full consult → ask "What about surveillance imaging?"
   Expected: No re-clarification; agent calls tool with full context from session history.

4. **COMPACT mode:**
   Ask: "Does a 38mm AAA need surgery?"
   Expected: Mode=COMPACT, "## Clinical Decision" + "## What is NOT indicated" sections.

5. **Citations:**
   Every answer with retrieved evidence must have clickable citation pills in the sidebar.

6. **Assets:**
   Ask about CLTI or AAA — check if relevant figures appear in citation sidebar.

7. **Out-of-scope:**
   Ask: "Who is the president of France?"
   Expected: Brief scope explanation, no clinical answer.

8. **Laravel unaffected:**
   Run `php artisan test` on 135 VM — all tests must still pass.

---

## Critical implementation notes for Codex

- **Before writing any PHP**: Read the actual Vizra ADK source in `vendor/vizra/vizra-adk/src/`
  to verify class names, method signatures, and return types. The plan above shows the intended
  structure; adjust to match what the installed SDK actually provides.

- **The `run()` return value**: Vizra ADK `run()` may return a string, an object, or an
  AgentResponse. Check the actual type and adjust `AgentConsultController` accordingly.

- **Context state access**: The mechanism for storing/retrieving state from `AgentContext`
  inside a tool and reading it in the controller after `run()` must be verified from the SDK.
  Alternative: pass a shared stdClass by reference if `AgentContext` state doesn't survive `run()`.

- **Session key**: The Python adapter sends `session_key` = sha256(uid:chat_id)[:24].
  Vizra ADK's session management must use this as the sessionId so conversation history persists
  across turns within the same OpenWebUI chat.

- **DeepSeek via Prism**: Verify Prism supports DeepSeek and the correct provider key name.
  Check `vendor/echolabs/prism/` or `vendor/prism/` for supported providers.
  If DeepSeek is not available, use `anthropic/claude-haiku-4-5` as fallback.

- **Middleware**: The new route MUST use the same `ValidateApiKey` middleware as the existing
  `/api/v1/vascular-consult` route. Do not create a new auth mechanism.
