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

### 5. Follow-up 302 redirect fix

Problem:

- some follow-up questions after a completed answer failed with `API error: 302`
- this happened when the adapter sent a long assistant answer back in `history`
- Laravel validates `history.*` with `max:2000`
- without an explicit `Accept: application/json` header, Laravel validation failures could surface as redirects instead of JSON errors

Fix:

- the adapter now sends `Accept: application/json`
- backend history sent from the adapter is now sanitized for API use:
  - oversized assistant answers are trimmed before sending
  - figure/table blocks and other bulky display-only sections are removed from backend history

Result:

- follow-up questions like `What about asymptomatic?` after a long cited answer no longer fail with `302`
- the exact failing payload from chat `a958cbd5-5162-4566-8546-db68b54a82b3` replays successfully against the live adapter path

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
- `1.4.8`: fixed follow-up `302` errors by sending JSON `Accept` headers and trimming oversized backend history
- `1.4.9`: forced same-case follow-up questions back through the tool and retrieval path
- `1.4.10`: introduced the newer structured answer blueprint (`## Bottom Line`, `## Evidence Used`, `## 🖼️ Figures / Tables`)
- `1.4.11`: hardened tool-selection wording for same-case definitive-treatment follow-ups
- `1.4.13`: restored and exported `explain_app_capabilities` for out-of-scope, onboarding, and prompt-injection requests
- `1.4.14`: prevented pending clarification replies from being misrouted into app-capabilities guidance
- `1.4.15`: explicitly treated recommendation-detail follow-ups such as `Provide class and level of recommendations` as mandatory same-case tool calls

## Additional Production Changes Later In The Session

### 1. Gate and change-detection refinement

The Laravel pre-retrieval and confirmation path continued to evolve after the initial two-phase rollout.

Main improvements:

- clarification-gate fallback questions were added for thoracic fistula / haemorrhage scenarios when the LLM returned no sharpening questions
- saphenous thrombosis clarification logic stopped asking whether a saphenous thrombosis is `superficial or deep`
- non-A non-B dissection interpretation was normalized earlier so thoracic dissection cases use the correct diagnosis wording, retrieval terms, and guideline mix
- CLTI clarifications such as `rest pain` and `tissue loss` now force a requery and refresh the guideline set instead of staying on asymptomatic PAD
- complex juxtarenal / pararenal / fenestrated AAA contexts now add Thoracic Aorta as a companion guideline during both gate analysis and retrieval

Files involved:

- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/app/Services/PreRetrievalService.php`
- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/app/Services/ChangeDetectionService.php`
- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/app/Http/Controllers/ToolController.php`
- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/app/ValueObjects/ChangeDetectionResult.php`
- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/tests/Unit/PreRetrievalServiceTest.php`
- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/tests/Unit/ChangeDetectionServiceTest.php`
- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/tests/Feature/ConfirmationGuidelineRefreshTest.php`

### 2. Recommendation and citation retrieval tuning

Two retrieval-ranking problems were fixed later in the session.

#### Definitive-treatment VGEI / fistula queries

The system had been over-prioritizing bridge or generic thoracic recommendations for questions about definitive treatment after emergency TEVAR in infected thoracic endograft / aorto-oesophageal fistula scenarios.

Fixes:

- prevented `What is the definitive treatment...` from being misclassified as a definition query
- added VGEI definitive-treatment query boosts
- boosted definitive-treatment VGEI citations and penalized bridge-only chunks during chunk scoring

#### Complex urgent juxtarenal AAA queries

The system was retrieving the right urgent complex-AAA recommendations but still letting thoracic companion or generic elective material outrank them in the LLM-facing set.

Fixes:

- added focused complex-AAA scoring cues and mismatch penalties
- added primary-anatomy weighting for juxtarenal / pararenal / abdominal-complex AAA recommendations
- changed `must_include` selection to use the full chunk score instead of simple keyword overlap
- re-ranked the final LLM subset after multi-guideline coverage seeding so the most relevant recommendation leads the synthesis

Files involved:

- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/app/Services/RetrievalService.php`
- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/app/Services/ChunkSelectionService.php`
- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/config/chunk_scoring.php`
- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/tests/Feature/RetrievalServiceFocusTest.php`
- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/tests/Unit/ChunkSelectionServiceTest.php`

### 3. Asset selection improvements

Figure / table selection was tightened so anatomically wrong or workflow-mismatched PNGs are less likely to appear.

Main changes:

- explicit figure / table references still win first, but irrelevant explicit hits are now rejected
- fallback scoring now understands vascular territory and narrower anatomy anchors
- definitive-treatment questions now prefer management algorithms over diagnostic imaging workflows
- fallback candidate selection now has a hybrid rerank stage:
  - the heuristic selector still generates a scoped shortlist
  - the shortlist is converted into rerank documents from asset labels, captions, descriptions, aliases, and keywords
  - the bridge reranker then re-orders those candidates before final asset selection

Files involved:

- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/app/Services/BridgeRerankService.php`
- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/app/Services/GuidelineAssetService.php`
- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/config/guideline_assets.php`
- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/tests/Unit/GuidelineAssetServiceTest.php`

Validation notes:

- `php artisan test tests/Unit/GuidelineAssetServiceTest.php` passed on the Laravel VM with `10` tests and `34` assertions
- live replay for the urgent juxtarenal AAA case no longer selected the clearly irrelevant compartment-syndrome PNG
- the current hybrid selector still trends toward AAA comparison tables over the ideal anatomy-specific complex-AAA figure, so future tuning is most likely needed in metadata and anatomy-anchor weighting rather than reranker enablement

### 4. OpenWebUI prompt and guardrail hardening

The OpenWebUI layer was hardened beyond the first two-phase rollout.

Main changes:

- added a repository copy of the live `gpt-5-chat` system prompt and a deployment helper:
  - `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/openwebui_tools/gpt5_system_prompt.txt`
  - `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/push_gpt5_prompt.py`
- restored the `explain_app_capabilities` tool path for onboarding, out-of-scope, and prompt-injection handling
- prevented pending gate replies from being diverted into app-capabilities guidance
- tightened tool-selection wording so recommendation-detail follow-ups re-enter the consult tool

Files involved:

- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/openwebui_tools/vascular_mcp_adapter.py`
- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/openwebui_tools/test_vascular_mcp_adapter.py`
- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/openwebui_tools/gpt5_system_prompt.txt`
- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/push_gpt5_prompt.py`

### 5. Validation snapshots from the later fixes

Observed successful checks later in the session included:

- local adapter tests:
  - `python3 -m unittest openwebui_tools.test_vascular_mcp_adapter`
  - latest observed passing count before final sync: `33` tests
- Laravel VM retrieval-focused suite:
  - `php artisan test tests/Unit/ChunkSelectionServiceTest.php tests/Feature/RetrievalServiceFocusTest.php`
  - final observed result: `37` tests, `92` assertions
- live Laravel replay for the urgent juxtarenal query:
  - `LLM: ["129","83","120","128", ...]`
  - `UI: ["129","83","120","121", ...]`
  - `must_include = 129`

## Validation Completed

### Local adapter validation

- `python3 -m py_compile openwebui_tools/vascular_mcp_adapter.py openwebui_tools/test_vascular_mcp_adapter.py`
- `python3 -m unittest openwebui_tools.test_vascular_mcp_adapter`

Final local adapter result:

- `16` tests passed

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
- the exact follow-up scenario from chat `a958cbd5-5162-4566-8546-db68b54a82b3` now returns a normal clarification gate instead of `API error: 302`

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
