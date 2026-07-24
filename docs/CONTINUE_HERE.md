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
- **⛔ Human decisions pending:** clinician sign-off on the 4 audited snippets; plan §0 calls (one-tool,
  decommission window, PHI-at-rest, audit owner). See plan §0.

## The active next step = Codex Run 4

Backlog: **`docs/CODEX_RUN4_BACKLOG.md`**. Paste this prompt into the Codex app to continue:

> Continue the unattended Agentic Gate v2 run on branch **`claude/prototyping-summary-d597c2`**. **`git pull` first**, then open **`docs/CODEX_RUN4_BACKLOG.md`** and work it top-to-bottom. Follow the **autonomy rules in `docs/CODEX_HANDOFF.md`** and keep **`docs/CODEX_PROGRESS.md`** current. Measurement discipline: **before/after per-stage p50/p95 for every latency change — no unmeasured claims.**
>
> **This run has three parts, in order:**
>
> **Part 1 — one bounded latency pass (time-box it; do NOT chase 60s — it's retrieval/infra-bound on this CPU box).** Dev SLO for this environment: **deep-turn p95 ≤ 90s with ZERO deadline overruns** (every turn returns a scored candidate within the wall-clock), p50 ≤ 70s; note that 60s is deferred to production/ISI hardware. Do: (R4.1) **cancellable timeouts** around retrieval + cloud HTTP so a blocking child call can never outlive the parent deadline; (R4.2) **cut retrieval call count** (in-turn caching/dedupe, skip re-retrieval when the first attempt already clears a similarity/quantity threshold, reduce max attempts); (R4.3) re-measure + record the before/after table and confirm the dev SLO or document the residual retrieval floor. Keep the sequential + full-pipeline paths reachable by config.
>
> **Part 2 — retrieval-trap quality reframe (no forcing).** (R4.4) **Drop the "reroute must win" hard bar** (update any acceptance text/tests). (R4.5) Compare the initial vs rerouted answers in `eval/latency/run3/retrieval_trap_three_iterations.json` and write a determination: is the initial candidate clinically wrong (real drift) or fine? If the Critic *should* penalize a mis-routed candidate more, improve the **general** `routing_validity` rubric in `CriticAgent` (never case-specific) and report whether the reroute then wins on merit; if the initial is fine, document that the trap doesn't cause drift in v2 and close it.
>
> **Part 3 — pivot to S0.** (R4.6) Wire `AnswerAssembly` into the answer path behind the `SYNTHESIS_OWNER=adapter|laravel` valve (**default stays adapter**), Laravel synthesis on cloud, audited-snippet flag OFF. (R4.7) **S0 checkpoint:** run `gate:eval` 15-case + gap-taxonomy via the Laravel synthesis path with the external cloud judge — **done when no grade drop + verbatim ≥98%**, adapter path intact at default.
>
> Guardrails: perf changes must preserve behavior (an altered answer is a bug, not a trade); no case-specific behavior in Part 2 (fix the general rubric or accept the evaluator's verdict); S0 never becomes the default; cloud only; never touch `main`, deploy, push the adapter DB, or force-push; ⛔HUMAN → flag and continue. End with a progress-log summary: latency before/after + new dev SLO, retrieval-trap determination, S0 checkpoint scorecard, done vs blocked, recommended next run.

## Review checkpoints when Run 4 returns
1. **No deadline overruns** + deep-turn p95 ≤ 90s (before/after table).
2. **Retrieval-trap determination** written (drift vs fine; any general rubric change).
3. **S0 checkpoint scorecard** — no grade drop + verbatim ≥98% via the Laravel synthesis path (the real milestone).

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
