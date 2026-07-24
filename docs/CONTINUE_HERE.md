# ▶ CONTINUE HERE — Agentic Gate v2

Single source of truth for picking this work back up (from any machine). The Claude/agent memory does
**not** travel between PCs — **this repo is the source of truth.**

Branch: **`claude/prototyping-summary-d597c2`** (also on origin). Pull it and read this file first.

## Where we are (2026-07-24)

- **Milestone A reached:** the gate reasons end-to-end on the CLI (`php artisan gate:probe2 "<case>"`)
  — triage/orient → deterministic retrieval + re-retrieval → probe ⇄ critic loop → two-frame answer +
  stage trace. It beats the AAA benchmark on quality (state retention + no router drift).
- **Runs 1–3 done** (eval harness, agents, workflow, latency work, S0 foundation). Full log:
  `docs/CODEX_PROGRESS.md`. Roadmap: `docs/AGENTIC_GATE_V2_TIMELINE.md`. Spec: `docs/AGENTIC_GATE_V2_PLAN.md`.
- **Open blocker:** deep-turn latency p95 ≈ 108s — **retrieval-infrastructure-bound** (RAGFlow on the
  CPU box), not gate design. The 60s SLO is deferred to production/ISI hardware.
- **A review pass landed three fixes on 2026-07-24** (`56d2666`, `5f1e973`, `6ada1ed`, pushed):
  child calls are now bounded by the turn deadline (R4.1 was a no-op — see below), the gate
  de-identifies before calling a model provider, and consult session ids are derived rather than
  taken from the request body. Full write-up at the top of `docs/CODEX_PROGRESS.md`.
  **None of it is eval-verified** — see the checkpoints below.
- **⭐ Strongest untested lead (R4.8):** the latency is likely dominated by **reranking inside the
  RAGFlow call** — the default `RAGFLOW_RERANK_ID=Cohere-rerank-v4.0-pro___OpenAI-API` reranks the whole
  (recently-raised) `top_k` pool via a synchronous Cohere network hop. An in-process FlashRank reranker
  (`RAGFLOW_RERANK_ID=local`) and bridge-side rerank already exist and are OFF. R4.8 is a **config-only,
  measured A/B** (latency **and** `gate:eval` grade) — run it first on any host with access. Details in
  `docs/CODEX_RUN4_BACKLOG.md` Part 1b.
- **⛔ Human decisions pending:** clinician sign-off on the 4 audited snippets; plan §0 calls (one-tool,
  decommission window, PHI-at-rest, audit owner). See plan §0. Plus three new ones:
  - **`storage/app/phi/common_names.json` does not exist in this repo**, so name redaction is a no-op
    on *every* path including production — and looks identical to "no names present". The gate now
    warns when running degraded, but supplying the dictionary is a data decision, not a code fix.
  - **`APP_KEY` must be set on the Hetzner host** or `/api/v1/agent-consult` returns 503. It is empty
    in the local `.env`. `docs/deployment_guide.md` already runs `key:generate` — confirm it.
  - **Per-user credentials.** Session binding is to the API *credential*; while one key is shared by
    every OpenWebUI user, they stay in a single scope. Separating end users needs an auth-model and
    adapter-contract change.

## The active next step = Codex Run 4 (verification-first)

Backlog: **`docs/CODEX_RUN4_BACKLOG.md`**. **Requires a host with RAGFlow/Laravel access** — Part 0 is
`gate:eval`, which cannot run from a machine without it. Paste this prompt into the Codex app:

> Continue the unattended Agentic Gate v2 run on branch **`claude/prototyping-summary-d597c2`**. **`git pull` first**, then open **`docs/CODEX_RUN4_BACKLOG.md`** and work it top-to-bottom. Follow the **autonomy rules in `docs/CODEX_HANDOFF.md`** and keep **`docs/CODEX_PROGRESS.md`** current. Measurement discipline: **before/after per-stage p50/p95 for every latency change — no unmeasured claims.**
>
> **Parts 1 and 2 of the original Run 4 are done** (R4.1–R4.5, plus a review pass that corrected R4.1 — it was a no-op). **Nothing since `12014b1` has been eval-verified.** This run is verification-first:
>
> **Part 0 — validate what is already on the branch (do this before writing any code).** Run `gate:eval` (15-case + gap taxonomy, external cloud judge). Baseline to hold: **28 PASS / 3 MINOR / 1 FAIL, routing 100%, verbatim 100%**. The PHI change in `5f1e973` now scrubs the turn *before every gate stage reads it*, so a grade drop here is a real regression and invalidates that commit's approach even though its compliance value stands — if it drops, diff the scrubbed turn against the raw one for the failing case and report which redaction caused it rather than reverting blind. Then run the four-turn latency harness and record the before/after table for `56d2666`; **no latency claim exists yet for any of it.**
>
> **Part 1 — R4.8 rerank A/B** (config-only, `docs/CODEX_RUN4_BACKLOG.md` Part 1b): `RAGFLOW_RERANK_ID=local` (FlashRank) vs the Cohere default. Report latency before/after **and** the `gate:eval` grade. A grade drop fails the A/B regardless of the speed win. Dev SLO for this box: deep-turn **p95 ≤ 90s with ZERO deadline overruns**, p50 ≤ 70s; 60s is deferred to production/ISI hardware.
>
> **Part 2 — S0** (was Part 3, still outstanding). (R4.6) Wire `AnswerAssembly` into the answer path behind the `SYNTHESIS_OWNER=adapter|laravel` valve (**default stays adapter**), Laravel synthesis on cloud, audited-snippet flag OFF. (R4.7) **S0 checkpoint:** `gate:eval` 15-case + gap-taxonomy via the Laravel synthesis path with the external cloud judge — **done when no grade drop + verbatim ≥98%**, adapter path intact at default.
>
> Guardrails: perf changes must preserve behavior (an altered answer is a bug, not a trade); S0 never becomes the default; cloud only; never touch `main`, deploy, push the adapter DB, or force-push; ⛔HUMAN → flag and continue. **Do not mark anything verified on syntax or unit tests alone — `gate:eval` is the bar.** End with a progress-log summary: Part 0 eval result vs the 28/3/1 baseline, latency before/after + dev SLO, R4.8 A/B, S0 checkpoint scorecard, done vs blocked, recommended next run.

## Current committed state (2026-07-24)
Everything through `6ada1ed` is on the branch and pushed. All of it is **UNVERIFIED against
`gate:eval`** — this PC has no host access, so the four-turn latency measurement, `gate:eval`, and the
S0 checkpoint are all **deferred**.

- Run 4 R4.1–R4.5 — `12014b1`. **R4.1 did not do what its log entry claimed:** the RAGFlow timeout
  rescope was defeated by facade instance caching and was a no-op after the first call in a process.
  Fixed in `56d2666`. Read the correction at the top of `docs/CODEX_PROGRESS.md` before trusting any
  earlier latency reasoning that assumed bounded child calls.
- R4.8 rerank A/B — `a0ff313`, still **unrun**.
- Review pass — `56d2666` (deadline binding), `5f1e973` (PHI scrubbing on the gate path),
  `6ada1ed` (consult session id derivation). 167 tests green apart from 4 pre-existing
  bridge-dependent failures; no eval.

**Next host with access: run checkpoint 0 first — `gate:eval` must be green before anything else is
believed, because the PHI change altered what every gate stage sees.**

## Review checkpoints when Run 4 returns
0. **`gate:eval` after the PHI change** — 28/3/1, verbatim 100%. Scrubbing now rewrites the turn every
   stage reads, so this is a real quality-regression vector, not a formality. A drop here invalidates
   `5f1e973` regardless of its compliance value. *(run first)*
1. **R4.8 rerank A/B** — `local` (FlashRank) vs Cohere default: latency before/after **and** grade held.
   A grade drop fails the A/B regardless of the speed win.
2. **No deadline overruns** + deep-turn p95 ≤ 90s (before/after table). `56d2666` should help here;
   nothing is claimed until measured.
3. **Retrieval-trap determination** written (drift vs fine; any general rubric change).
4. **S0 checkpoint scorecard** — no grade drop + verbatim ≥98% via the Laravel synthesis path (the real milestone).

## Doc index
- `docs/AGENTIC_GATE_V2_PLAN.md` — the spec (decisions §0, architecture §3, migration §8, adapter inventory §11).
- `docs/AGENTIC_GATE_V2_TIMELINE.md` — roadmap / jobs-to-be-done.
- `docs/CODEX_PROGRESS.md` — running build log (read the latest sections first).
- `docs/CODEX_HANDOFF.md` — autonomy rules + general backlog.
- `docs/CODEX_RUN4_BACKLOG.md` — the active run.
- `docs/AGENTIC_GATE_V2_REVIEW.md` + `..._MIGRATION_REVIEW.md` — the two design reviews.
- `eval/` — scenarios, benchmarks, 15-case baseline, latency artifacts.

> Note: `CLAUDE.md` may show as locally modified — that is context-mode plugin tooling noise, deliberately
> not committed. Ignore it; it does not travel between machines.
