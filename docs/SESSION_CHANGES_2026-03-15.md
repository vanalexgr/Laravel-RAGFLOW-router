# Session Changes - 2026-03-15

This document summarizes the final code, deployment state, and production fixes reached during the March 15, 2026 session.

## Scope

This session introduced the new two-phase clarification and retrieval flow for the `vascular_mcp_adapter` tool while leaving the legacy `mcp` tool untouched.

Primary goals covered:

- move new development to `openwebui_tools/vascular_mcp_adapter.py`
- add a Laravel pre-retrieval interpretation layer before full retrieval
- support same-case clarification replies without reopening the gate
- reuse background retrieval whenever the clarified reply does not materially change the case
- keep retrieval running in the background while the user answers clarification questions
- stop the fallback disclaimer from leaking into clarification-gate replies
- remove duplicate gate rendering in OpenWebUI
- refresh the gate presentation with clearer structure and icons

## Files Added Or Introduced

- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/app/Contracts/LlmClient.php`
- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/app/Services/AzureOpenAiLlmClient.php`
- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/app/Services/PreRetrievalService.php`
- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/app/Services/ChangeDetectionService.php`
- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/app/ValueObjects/PreRetrievalResult.php`
- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/app/ValueObjects/ChangeDetectionResult.php`
- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/tests/Unit/PreRetrievalServiceTest.php`
- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/tests/Unit/ChangeDetectionServiceTest.php`
- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/openwebui_tools/test_vascular_mcp_adapter.py`

## Files Updated

- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/app/Http/Controllers/ToolController.php`
- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/app/Providers/AppServiceProvider.php`
- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/routes/api.php`
- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/openwebui_tools/vascular_mcp_adapter.py`

## Final Architecture

### 1. Laravel phase 1: pre-retrieval interpretation

`PreRetrievalService` is now the first clinical interpretation step for the adapter path.

Responsibilities:

- interpret the current case like a vascular surgeon
- generate a provisional diagnosis
- select 1-3 guideline keys
- build an expanded retrieval query
- determine whether clarification is needed before full retrieval
- generate a user-facing confirmation/gate message

New endpoint:

- `POST /api/v1/pre-retrieval`

### 2. Laravel phase 2: change detection

`ChangeDetectionService` compares the user clarification reply against the stored pre-retrieval state.

Decision outcomes:

- `reuse`: same case, same retrieval target, use the background retrieval already in progress
- `requery`: clarification materially changes diagnosis, anatomy, acuity, or retrieval target

This keeps same-case clarification replies fast and prevents unnecessary repeat retrieval.

### 3. OpenWebUI adapter flow

`vascular_mcp_adapter.py` now uses a two-phase same-case workflow:

1. call `/api/v1/pre-retrieval`
2. return the clarification gate immediately
3. start the full Laravel consult retrieval in the background
4. store phase-1 state in a lightweight in-memory chat-scoped session
5. on the next turn, call Laravel confirmation mode
6. reuse the stored/background retrieval when possible
7. only run a fresh retrieval when change detection says the case actually changed

The adapter now keys pending sessions by chat ID when available, instead of only user ID.

## Production Fixes Applied During This Session

### 1. Duplicate gate reopening

Problem:

- short clarification replies such as `10 days ago. minor stroke. no` were not being recognized as same-case clarification answers
- rewritten follow-up questions could be treated as brand-new cases
- the gate reopened instead of moving into confirmation mode

Fix:

- broadened short-answer detection in the adapter
- allowed pending-gate reuse for rewritten same-case follow-ups
- improved `ChangeDetectionService` to prefer `reuse` when the reply mainly answers the original clarification questions

### 2. Fallback disclaimer leaking into gate replies

Problem:

- the sentence `The provided ESVS guideline context does not explicitly address this scenario.` still appeared in clarification gates

Actual root cause:

- part of the problem was adapter behavior
- the bigger leak was in the live OpenWebUI `gpt-5-chat` system prompt, which explicitly instructed the model to emit that fallback sentence when it believed there was no relevant evidence

Fix:

- strengthened the adapter gate wrapper with the `GUIDELINE_RETRIEVAL_PAUSED` marker
- patched the live `gpt-5-chat` system prompt with a higher-priority clarification-gate override:
  - return only the gate message
  - do not use the strict evidence-answer template
  - do not emit the fallback disclaimer

Important note:

- this model-prompt change exists in production OpenWebUI state, not in this repository

### 3. Duplicate gate text inside the same assistant bubble

Problem:

- OpenWebUI merged adapter message emissions and the final assistant gate reply into one stored assistant message
- phase 1 was emitting the full `confirmation_message` as a status message and then returning the same gate for the model to print again

Fix:

- removed the extra status emission of the full gate text
- phase 1 now emits only the short `Interpreting the clinical question before retrieval...` progress message
- the actual gate text is rendered once, via the final assistant reply

### 4. Gate presentation refresh

The confirmation/gate message now uses a structured format:

- `Clinical Query Checkpoint`
- `🩺 Understanding`
- `📚 Searching`
- `🏷️ Query Terms`
- `❓ To Sharpen`
- `✅ Reply to confirm, or add details to refine the search.`

The adapter was updated to recognize both:

- the older arrow-style gate messages
- the new icon-based structured gate messages

## Live Deployment State

### Laravel VM

- Host: `135.237.148.105`
- Active app path: `/home/azureuser/laravel-ragflow`
- Service: `laravel-api.service`

Important operational note:

- the active Laravel service on the VM runs from `/home/azureuser/laravel-ragflow`
- this differs from the local workspace folder name `Laravel-RAGFLOW-router`

### OpenWebUI VM

- Host: `48.211.217.69`
- Active tool row: `tool.id='vascular_mcp_adapter'`
- Legacy `mcp` tool left unchanged

Adapter versions deployed during this session:

- `1.4.5`: chat-scoped sessions, restored background retrieval, same-case clarification reuse
- `1.4.6`: removed duplicate gate status emission
- `1.4.7`: refreshed gate appearance and added compatibility for icon-based gate detection

## Validation Completed

### Local adapter validation

- `python3 -m py_compile openwebui_tools/vascular_mcp_adapter.py openwebui_tools/test_vascular_mcp_adapter.py`
- `python3 -m unittest openwebui_tools.test_vascular_mcp_adapter`

Final local adapter result:

- `14` tests passed

### Laravel VM validation

Executed on `/home/azureuser/laravel-ragflow`:

- `php -l app/Services/PreRetrievalService.php`
- `php -l tests/Unit/PreRetrievalServiceTest.php`
- `php artisan test tests/Unit/PreRetrievalServiceTest.php`
- `php artisan test tests/Unit/ChangeDetectionServiceTest.php`

Observed passing results during the session:

- `PreRetrievalServiceTest`: `11` tests, `48` assertions
- `ChangeDetectionServiceTest`: `7` tests, `12` assertions

### Live behavior checks

Validated directly against live production services:

- phase 1 gate returns without the fallback disclaimer
- same-case clarifications do not reopen the gate
- a reused clarification flow performs:
  - `1` pre-retrieval call
  - `1` confirmation call
  - `1` full retrieval call total
- the second turn waits on the already-running background retrieval instead of starting over
- duplicate gate text in the same assistant bubble was reduced to a single gate instance
- the live Laravel `pre-retrieval` endpoint returns the icon-based structured gate format

## Production Backups Created

On `48.211.217.69`:

- `/tmp/tool_backup_20260315_111633.json`
- `/tmp/tool_backup_20260315_113330.json`
- `/tmp/tool_backup_20260315_114007.json`
- `/tmp/gpt5_params_backup_1773573688.json`

These backups were created before replacing OpenWebUI tool content or patching the live `gpt-5-chat` model prompt.

## Known Constraint

The OpenWebUI model-prompt override that suppresses fallback disclaimer leakage for clarification gates lives in production state, not in this repository. If the OpenWebUI model row is recreated or overwritten, that clarification-gate override must be re-applied.

## Summary

At the end of the session, production behavior for the new adapter path is:

- clarification gate first
- retrieval in the background
- clarification-aware same-case follow-up handling
- reuse vs requery decided by Laravel
- no fallback disclaimer in gate replies
- no duplicate gate rendering
- styled gate presentation with icons and clearer structure
