# Agentic Clarification Gate v2 — `laravel/ai` multi-agent workflow

Prototype. Replaces the single-call `App\Services\AgenticGate\AgenticGateService`
("orient → probe → proceed" in one LLM call) with a **multi-agent workflow** built
on the **first-party Laravel AI SDK** (`laravel/ai`), applying the patterns from
Anthropic's *Building Effective Agents* / the Laravel AI SDK blog.

## Why `laravel/ai` (not Vizra ADK)

The repo currently wires Vizra ADK (`vizra/vizra-adk`) for `VascularConsultAgent`.
For the gate v2 we deliberately use the **first-party** SDK because we are migrating
to **ISI on-prem / local models**:

- `laravel/ai` ships `OllamaProvider` **and** `OpenAiCompatibleProvider` (custom base
  URL) → a self-hosted model (vLLM / Ollama / TGI, OpenAI-compatible API) is
  first-class. It also has native Cohere reranking + embedding providers (matches the
  RAGFlow stack).
- Requirements are satisfied: `laravel/ai` v0.10.1 needs `illuminate ^12.0|^13.0`
  (repo is Laravel 12) and **PHP ^8.3** (Hetzner runs PHP 8.5). Local `composer.json`
  declares `php ^8.2` — bump to `^8.3` when adding the dep.

## The agents (this directory) — "agents first, wire later"

| File | Stage | Role | Pattern |
|---|---|---|---|
| `TriageAgent.php` | 0 | **Front door.** Cheap/fast: `knowledge` (fast path) vs `case` (deep loop). `#[UseCheapestModel]`. | Routing |
| `KnowledgeAnswerAgent.php` | fast | **Fast path** for simple knowledge Qs: one retrieve + two-frame answer, no loop. Can `escalate` to the deep path. | Routing |
| `OrientAgent.php` | 1 | Frame the case → patient_model, differential, candidate guideline keys. `#[UseCheapestModel]`. | Routing |
| `Tools/RetrieveEsvsSnippetsTool.php` | — | Grounding seam: pulls real ESVS snippets for ONE guideline via the existing `RetrievalService`; returns a clear `NO_SNIPPETS` signal on a miss. | Tool |
| `PathwayAgent.php` | 2 | One instance **per candidate guideline**, run in parallel; **re-retrieves with reformulated queries** on a miss and only then reports `coverage` (covered/partial/not_covered) + `queries_tried`; enumerates grounded pathways. | Parallelization |
| `ProbeAgent.php` | 3 (generator) | Value-of-information ranking → unknowns, ≤2 questions; answer split into **`guideline_grounded_answer`** + **`interpretive_frame` (non-ESVS, always populated)** + `evidence_status`; calibrated confidence. Refines on critic feedback. | Evaluator-Optimizer |
| `CriticAgent.php` | 4 (evaluator) | **General** evaluator of the WHOLE result against **7** case-agnostic invariants; returns `approved` + `revise_stage` + tagged `issues`. Capable model. | Evaluator-Optimizer |

**The 7 invariants** (all general, no per-case rules): `state_completeness`, `routing_validity`,
`retrieval_sufficiency`, `grounding`, `frame_integrity`, `question_value`, `calibration`.

Plus `Progress/GateProgress.php` (+ `LogGateProgress`, `NullGateProgress`) — the progress channel.

## Two-tier front door — most questions stay fast

The heavy loop is only for questions that earn it. `TriageAgent` runs first (one cheap call) and splits:

```
question
  │
  ▼
TriageAgent (cheapest model)
  ├── "knowledge" ─► KnowledgeAnswerAgent: retrieve once → two-frame answer     [FAST, ~1 call]
  │                     └─ escalate=true? ─► fall through to the deep loop ▼
  └── "case" ─────► OrientAgent → parallel Pathways → Probe⇄Critic loop → decide [DEEP]
```

Simple definitions/thresholds/population questions — the majority — take the fast path and never pay
for orient, parallel retrieval, or the critic loop. Only specific-patient consultations whose answer
depends on unstated facts enter the loop. The fast path still uses the same two-frame answer and the
same re-retrieve-on-miss rule, so answers stay consistent and robust. The `escalate` hatch means a
question that *looks* simple but turns out patient-specific is handed to the deep path mid-flight.

## Progress feedback — never a silent hang

The workflow takes a `GateProgress` and calls `emit($stage, $message, $context)` at every stage
boundary (`triage`, `knowledge_fast`, `orient`, `retrieve`, `probe`, `evaluate`, `revise`, `decide`,
`done`). The OpenWebUI adapter maps these to `__event_emitter__` **status** lines the user sees live,
e.g.:

- `🔍 Retrieving carotid guideline…`
- `🧭 Straightforward question — answering directly.`
- `🤔 This needs a closer look — analysing the case in depth…`   ← shown on `case`/`escalate`
- `⚠️ Evidence looks thin — re-checking the guidelines…`          ← shown on `revise: ground`
- `✍️ Refining the answer (pass 2 of 3)…`                          ← shown on loop re-entry

Crucially, whenever the agent *decides to go deep or rethink* (triage→case, escalate, or a Critic
bounce) it emits a user-facing line so the added latency is always explained.

**Cross-VM streaming note:** the OpenWebUI tool calls Laravel over a single HTTP POST, so live
per-stage push needs one of: (a) an SSE/streaming gate endpoint the adapter consumes and forwards via
`__event_emitter__` (preferred — `laravel/ai` supports `stream()`); or (b) interim: the adapter emits
coarse "thinking…" status while awaiting the response, and Laravel returns a full `stage_trace` for
the transcript. Decide (a) vs (b) at wiring time.

Each agent implements `Laravel\Ai\Contracts\Agent` + `HasStructuredOutput` (typed
JSON via `schema(JsonSchema $schema)`). Structured responses are read via array
offsets, e.g. `$res['patient_model']`, `$res['confidence']`.

## Wiring plan (NOT built yet — next step)

A `GateWorkflowService` (or artisan `gate:probe2`) composes the stages inside ONE
**general evaluate-and-improve loop**. The Critic judges the whole result and names
the earliest failing stage; the loop re-runs from THAT stage with the issues injected.
There are no case-specific guards — routing validity, state completeness, grounding,
question value and calibration are all just invariants the Critic enforces, for every
case:

```
build($case):                                       // one candidate
    orient   = OrientAgent->prompt($case)                        // cheap model (routing)
    pathways = Concurrency::run(                                 // parallelization
        collect(orient.candidate_guidelines)->map(fn($k) =>
            fn() => (new PathwayAgent($k))->prompt($case, orient))->all())
    probe    = ProbeAgent->prompt($case, orient, pathways, $issues)
    return {orient, pathways, probe}

# GENERAL evaluator-optimizer loop (max ~3 iterations):
$issues = [];
loop:
    $candidate = build($case, from: $revise_stage, carrying: prior $candidate + $issues)
    $critic    = CriticAgent->prompt($candidate)      // evaluates the WHOLE candidate
    if $critic.approved: break
    $revise_stage = $critic.revise_stage              // orient_route | ground | probe
    $issues       = $critic.issues                    // tagged, actionable
until approved OR iteration cap

# deterministic policy tail (NOT a guard, just the ask/proceed threshold):
decide: ask iff a HIGH-impact unknown remains AND confidence < 0.70.
```

Key point: because the Critic can set `revise_stage = orient_route`, the loop can
**re-route and re-retrieve**, not just re-word questions — so a mis-route (e.g. an AAA
case grounded in the thoracic guideline) is caught and corrected by the same general
mechanism that catches everything else, without an anatomy-specific rule.

Parallelization uses `Illuminate\Support\Facades\Concurrency::run()` (the SDK blog's
`Concurrency::run()` pattern). **Open risk:** under PHP-FPM this may fork processes;
confirm on Hetzner that concurrent LLM HTTP calls truly overlap (else fall back to
queued fan-out or accept sequential pathway calls).

**Cost/latency note:** a whole-pipeline loop can re-run Orient + parallel retrieval on
an `orient_route` bounce. Keep the cap low (~3), let the Critic bounce to the EARLIEST
failing stage only, and short-circuit when `approved` on the first pass (the common
case). Measure iterations-per-case in `gate:probe2`.

## Retrieval robustness & the two-frame answer

Two properties the loop guarantees for **every** case:

**Re-retrieve before concluding "ESVS is silent."** Retrieval is treated as fallible. A PathwayAgent
that gets `NO_SNIPPETS` or off-target snippets must reformulate and retry (synonyms, underlying
threshold/anatomy, broader phrasing) before reporting `coverage: not_covered`, and it emits
`queries_tried` as the audit trail. The Critic's `retrieval_sufficiency` invariant rejects any
"not_covered"/"partial" that rests on a single weak query and bounces the loop to `ground` for a
better retrieval. So "the guidelines don't cover this" is a *validated* verdict, never a first-try
giveup.

**Always answer, always labelled.** The ProbeAgent splits every answer into two frames:
- `guideline_grounded_answer` — only ESVS-supported claims (may be empty when `evidence_status =
  esvs_absent`).
- `interpretive_frame` — expert reasoning beyond the guideline, **always populated** and flagged as
  non-ESVS, so the user gets usable guidance even when the guideline is silent or thin.

`evidence_status` (`esvs_sufficient | esvs_partial | esvs_absent`) is derived from the pathways'
coverage verdicts. The Critic's `frame_integrity` invariant enforces that the two frames never leak
into each other and that the interpretive frame is never left empty. This preserves the existing
answer layout's non-guideline interpretive section as a first-class, flagged output.

## Install & test (Hetzner only — no local PHP/model)

```bash
# on Hetzner (root@178.105.193.206), in /opt/cg/laravel/app
composer require laravel/ai           # bump composer.json php to ^8.3 first if needed
php artisan vendor:publish --tag=ai-config   # config/ai.php

# point the SDK at the local ISI model (OpenAI-compatible) in .env, e.g.:
#   AI_TEXT_PROVIDER=openai_compatible
#   AI_OPENAI_COMPATIBLE_BASE_URL=http://<isi-model-host>:<port>/v1
#   AI_OPENAI_COMPATIBLE_API_KEY=...   (or ollama: AI_TEXT_PROVIDER=ollama)

php artisan config:cache
# then a gate:probe2 command (to be added) runs the workflow and prints
# per-stage timings + loop iteration count, side-by-side with the v1 gate.
```

Keep production == deployed branch: `rm` any scratch test files after probing, as
with the v1 prototype.

## Status

- [x] Agents + grounding tool scaffolded (structured schemas, domain prompts).
- [x] Two-tier front door: `TriageAgent` (fast/deep routing) + `KnowledgeAnswerAgent` (fast path, escalate hatch).
- [x] `GateProgress` channel (+ Log/Null impls) for live user feedback on deep/rethink.
- [x] Critic is a GENERAL evaluator (7 case-agnostic invariants + `revise_stage`), no per-case guards.
- [ ] `composer require laravel/ai` + `config/ai.php` provider config (Hetzner).
- [ ] `GateWorkflowService` / `gate:probe2` — triage → (fast path | deep loop), the loop
      re-running from `revise_stage` with issues injected; emits `GateProgress`; logs
      iterations-per-case + per-stage timings.
- [ ] OpenWebUI adapter: map `GateProgress` events to `__event_emitter__` status; pick SSE
      streaming vs coarse-status+stage_trace for the cross-VM channel.
- [ ] Verify `Concurrency::run()` truly parallelizes on Hetzner.
- [ ] `laravel/ai` offline eval over the labelled case set (AAA evolving-context, carotid
      lateralisation, etc.) to prove the loop converges across cases, not just spot fixes.
