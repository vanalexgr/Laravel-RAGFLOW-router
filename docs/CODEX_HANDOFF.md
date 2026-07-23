# Codex handoff — Agentic Gate v2 (continue the build)

You are continuing an in-progress project on branch **`claude/prototyping-summary-d597c2`** (already on
origin). Do **not** work on `main`. Do **not** deploy to production. Commit to the prototyping branch.

## Read these first (authoritative, in the repo)

1. `docs/AGENTIC_GATE_V2_PLAN.md` — the master build spec. §0 = locked decisions; §3–4 = architecture +
   state model; §6–7 = invariants + two-frame answer; §8 = migration sequence (S0–S7); §9 = eval;
   §11 = full adapter-migration inventory. **This is the source of truth.**
2. `docs/AGENTIC_GATE_V2_REVIEW.md` and `docs/AGENTIC_GATE_V2_MIGRATION_REVIEW.md` — two design reviews
   (by Fable) already folded into the plan; read for the *why* behind the decisions.
3. `app/Ai/Gate/README.md` + the scaffolded agents in `app/Ai/Gate/` (Triage/Orient/Pathway/Probe/
   Critic/KnowledgeAnswer + `Tools/RetrieveEsvsSnippetsTool` + `Progress/`).
4. `docs/spikes/ollama_capability_spike.py` — the GO/NO-GO model harness.
5. The live adapter you are migrating from: `openwebui_tools/vascular_mcp_adapter.py` (v1.5.59, ~3000 lines).

## What this is (one paragraph)

A Laravel 12 clinical decision-support router (OpenWebUI → Laravel API → RAGFlow ESVS guidelines). We are
replacing a rigid whitelist "clarification gate" with an agentic gate built on the first-party Laravel AI
SDK (`laravel/ai`), targeting on-prem **Ollama** models, and moving ALL intelligence out of the fat
OpenWebUI adapter into Laravel (thin adapter). It must beat the AAA evolving-context benchmark (plan §1):
state-loss across turns + router drift.

## Non-negotiable design commitments (do not drift)

- One **general evaluate-and-improve loop**, no case-specific guards (deterministic *lints* are OK).
- **Two tiers**: fast path for simple knowledge Qs; deep loop only for patient cases.
- Retrieval is fallible → **re-retrieve** before concluding "ESVS silent."
- **Two-frame answer**: `guideline_grounded_answer` + always-populated, flagged `interpretive_frame`.
- **All intelligence in Laravel; thin adapter**. **Laravel-verbatim** final answer (OWUI renders as-is).
- **Ollama** ⇒ pathways **sequential** (≤2 candidates), **deterministic PHP orchestration** (NO agentic
  tool-loops), structured output via `format`=JSON, **no scalar-confidence** decision (discrete signals).
- Migration: **answer path first, decoupled from the gate**; S0 fill-call on cloud gpt-5-mini, S1 swaps
  to local (see plan §8 for the S0–S7 valve sequence and `SYNTHESIS_MODEL`/`SYNTHESIS_OWNER` valves).

## Infra + testing (NOT in the repo — the repo CLAUDE.md lists OLD Azure infra; use THIS)

- **Develop and test on CLOUD providers.** The ISI on-prem/local (Ollama) migration has NOT happened;
  there is no representative local-model host yet (Hetzner is CPU + production). Use cloud
  (OpenAI / OpenAI-compatible) via `laravel/ai`'s provider abstraction — the eventual Ollama swap is a
  config change. **The Ollama capability spike is DEFERRED** until ISI GPU hardware exists (a spike
  attempt on 2026-07-24 returned BLOCKED — Ollama not installed on Hetzner; that was correct). Keep the
  design provider-agnostic and local-faithful (sequential pathways, deterministic PHP orchestration,
  structured `format`/`response_format` JSON).
- **No local PHP.** PHP testing runs on the Hetzner box: `ssh -i ~/.ssh/id_ed25519 root@178.105.193.206`,
  Laravel at `/opt/cg/laravel/app/`. RAGFlow container `docker-ragflow-cpu-1` (Elasticsearch, ES :1200),
  OpenWebUI `open-webui`, Redis case-state DB5 (`REDIS_PASSWORD=infini_rag_flow`). NOTE: the repo
  CLAUDE.md lists OLD Azure infra (135/48 VMs) — ignore it; use this.
- **`laravel/ai` is NOT installed yet.** Framework is already v12.63.0 (no upgrade needed). To wire S0+:
  on Hetzner, `composer require laravel/ai` (dry-run first to confirm coexistence with prism-php/prism
  v0.92 + vizra/vizra-adk v0.0.42), publish `config/ai.php`, bump composer.json php `^8.2`→`^8.3`. For a
  cloud provider, configure OpenAI (`gpt-5-mini` is already wired in Laravel) or an OpenAI-compatible host.
- Adapter deploy (only when explicitly asked, not for prototyping): edit
  `openwebui_tools/vascular_mcp_adapter.py`, then push to the OpenWebUI SQLite DB via
  `openwebui_tools/push_adapter.py` (id=`vascular_mcp_adapter`) + restart the container.

## Current state

Design is complete and twice-reviewed; agents are scaffolded (schemas + prompts) but **nothing is wired,
nothing deployed, `laravel/ai` not installed**. The scaffolded agents still reflect the pre-review shape —
per plan §10 they need rework (merge Triage→Orient; remove agentic tool-loops → deterministic
orchestration; Critic must see snippets; discrete decision; structured `evidence_status`; etc.).

## Your next tasks (in order)

1. **Build the eval harness + scenario files** (plan §9) — the binding launch gate; model-independent.
   JSON scenario files with **cumulative** `must_include_facts` (turn N inherits 1..N-1). Cover: the AAA
   benchmark (full transcript + expectations in **`eval/benchmarks/aaa_evolving_context.md`**), Fable's
   **6 adversarial scenarios** (case-switch chimera, correction flip, declined-question persistence,
   duplicate delivery, knowledge interleave, retrieval trap — migration review §5 / plan §9), plus the
   existing **15-case suite**: case content is in repo-root **`vascular_batch_validation_suite_v_1.md`**
   and the binding per-case baseline grades (for "no grade drop") are in
   **`eval/baseline/15_case_baseline.md`** (note the S4 content-gap caveat there). Scenario schema (plan §9):
   `{id, tags[], turns:[{user, attachments?, expected:{mode, same_case, guideline_keys[],
   must_include_facts[], must_not_include[], expected_questions_semantic[], max_questions,
   evidence_status}}]}`. Build one artisan runner + an **external strong-model judge** (a strong cloud
   model, e.g. gpt-5-tier — never the system-under-test judging itself). Store `stage_trace`s for replay.
   Guideline keys are the 14 in the `consult_vascular_guidelines` docstring.
2. **Start S0 — answer path on cloud** (plan §8). Laravel `AnswerAssembly` = deterministic PHP section
   skeletons + ONE schema-constrained fill call (migration-review Q1(b)), consuming the EXISTING
   planner/retrieval/gap_assessment outputs, behind a `SYNTHESIS_OWNER` valve; fill-call on the cloud
   model (`SYNTHESIS_MODEL=cloud`, gpt-5-mini already wired). Reproduce the adapter's `_response_mode`
   variants + two-layer blueprint structure + gap taxonomy (plan §7/§11; migration review Q1/Q2).
   **Checkpoint:** binding 15-case + verbatim ≥98% + gap-taxonomy scenarios. Old adapter path stays intact
   at `SYNTHESIS_OWNER=adapter`.
3. **The Ollama capability spike (`docs/spikes/ollama_capability_spike.py`) is DEFERRED** to when ISI GPU
   hardware exists — do NOT block on it. It is the S1 gate (local-model swap), not a prerequisite for
   S0/eval on cloud.

## Open human decisions (do NOT resolve these yourself — flag and proceed around them)

- Embedded clinical assertions → audited snippet library (decided) but clinician sign-off still pending
  (**gates finalizing S0 skeletons**).
- One-tool vs two (recommend deleting `explain_app_capabilities`); decommission "N weeks"; PHI-at-rest for
  patient_model in Redis; clinician audit cadence. (Plan §0 lists all.)

## Working rules

- Keep `docs/AGENTIC_GATE_V2_PLAN.md` updated as the spec evolves; record decisions there.
- Small, reviewable commits on `claude/prototyping-summary-d597c2`. Never touch `main`.
- Delete scratch/test files off Hetzner after use (keep prod == deployed).
- If reality contradicts the plan (e.g. Ollama not on Hetzner, laravel/ai dependency conflict), stop and
  report rather than working around it silently.
