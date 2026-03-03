# Session Changes - 2026-03-03

This document summarizes how the system currently works with the interpretive frame and lists all changes made in this session.

## Current Behavior (Interpretive Frame)

1. PHI scrub runs on the raw user query.
2. Query normalization runs when needed (non-ASCII or GraphRAG intent enabled).
3. Clinical Interpreter runs on the scrubbed query.
4. The interpreter outputs:
   - `clinical_frame` (1-2 sentences, no recommendations)
   - `interpretation_terms` (retrieval expansion terms)
   - `must_include_terms` (terms that should appear in at least one LLM-visible citation)
5. Guideline routing selects 1-3 datasets.
6. Retrieval queries are expanded with:
   - GraphRAG concepts
   - Interpreter terms
   - Taxonomy terms (if enabled)
   - Query boosts (edge-case phrasing)
7. Dual retrieval runs (narrative + citation).
8. Focused recall or quality pass may run based on configured thresholds.
9. Gap detection can trigger a second pass.
10. The OpenWebUI tool prepares the LLM context and, if enabled:
    - Includes the `CLINICAL FRAME (INTERPRETIVE / NON-GUIDELINE)` block.
    - Forces a term-matched citation into the LLM evidence set when possible.
    - Allows a best-fit answer with explicit caveats (`ALLOW_PARTIAL_EVIDENCE_ANSWERS=true`).

## Changes in the Laravel Bridge Repo

New or updated files:
- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/app/Services/ClinicalInterpreterService.php`
- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/config/clinical_interpreter.php`
- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/app/Services/RetrievalService.php`
- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/openwebui_tools/vascular_expert.py`
- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/.env.example`
- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/docs/CONFIGURATION.md`
- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/docs/SYSTEM_PIPELINE.md`
- `/Users/vga/LARAVEL/Laravel-RAGFLOW-router/.gitignore`

Behavioral changes:
- Added a pre-retrieval Clinical Interpreter that expands retrieval terms and emits a clinical frame.
- Interpreter terms are appended to both narrative and citation queries.
- Interpreter `must_include_terms` are passed to the OpenWebUI tool to force at least one term-matched citation.
- OpenWebUI tool now supports partial-match answers with explicit caveats.
- OpenWebUI tool includes an optional interpretive frame block in LLM context (`SHOW_CLINICAL_FRAME`).
- Term-variant matching improves inclusion for edge phrasing (e.g., "shaggy thoracic aorta").

Housekeeping:
- Added `.gitignore` rules for runtime backup files and `ragflow_service/models/`.

Commits:
- `a05f337` Add pre-retrieval clinical interpreter
- `4c1e6e5` Ignore runtime backups and models

## Changes Applied Directly on Production VMs

Laravel VM (`135.237.148.105`):
- Pulled latest `main`.
- Ran `php artisan config:clear`.
- Removed `.env.bak.*` and other `.bak` files.

OpenWebUI VM (`48.211.217.69`):
- Updated `/home/azureuser/vascular_expert.py` to match repo `openwebui_tools/vascular_expert.py`.
- Updated the OpenWebUI model system prompt and RAG template in the DB to:
  - Allow best-fit answers with explicit caveats.
  - Accept the interpretive frame block as non-guideline context.
- Backups created before prompt changes:
  - `/var/lib/docker/volumes/open-webui/_data/codex_backups/gpt5_system_1772492179_before_frame.txt`
  - `/var/lib/docker/volumes/open-webui/_data/codex_backups/rag_template_1772492195_before_frame.txt`

## Rollback Guide

Disable interpreter and interpretive frame:
- `CLINICAL_INTERPRETER_ENABLED=false`
- OpenWebUI tool valve `SHOW_CLINICAL_FRAME=false`

Disable partial-match answers:
- OpenWebUI tool valve `ALLOW_PARTIAL_EVIDENCE_ANSWERS=false`

Disable GraphRAG or recall passes:
- `GRAPHRAG_ENABLED=false`
- `RAGFLOW_FOCUSED_RECALL_ENABLED=false`
- `RAGFLOW_QUALITY_PASS_ENABLED=false`

Restore prior OpenWebUI prompts:
- Replace the DB system prompt and RAG template with the backup files listed above.
