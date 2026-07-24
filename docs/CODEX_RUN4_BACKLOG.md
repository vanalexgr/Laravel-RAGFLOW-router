# Codex Run 4 — bounded latency pass, retrieval-trap reframe, then S0

Branch `claude/prototyping-summary-d597c2`. `git pull` first. Follow the **autonomy rules in
`docs/CODEX_HANDOFF.md`** (progress log, commit often, verify-before-commit, cloud-only, never touch
`main`/deploy/adapter-DB/force-push, ⛔HUMAN → flag & continue). Keep `docs/CODEX_PROGRESS.md` current.
Measurement discipline: **before/after per-stage p50/p95 for every latency change** — no unmeasured claims.

## Context (from Run 3)

The gate works and quality is green (eval 28/3/1, routing 100%, verbatim 100%; 123 tests). Deep-turn
p95 is 108.5s, and this is **retrieval-infrastructure-bound** (RAGFlow on the CPU box: 2.5–32.7s/call,
~3 calls/turn); the LLM stages are already cheap. We are **not** chasing 60s on this hardware — the 60s
SLO is a production/ISI-hardware target. AAA T1 already completes a full approved critique+revision in
49.5s. Two decisions from the human set this run's scope:
1. **One bounded latency pass, then pivot to S0.**
2. **Retrieval-trap: reframe as a quality check** — the "reroute must win" hard bar is DROPPED (the
   corrected reroute scored 0.50 vs initial 0.90; forcing it would game the evaluator).

## Part 1 — Bounded latency pass (time-box it; do NOT chase 60s)

**Dev SLO for this environment:** deep-turn **p95 ≤ 90s** with **zero deadline overruns** (every turn
returns a scored candidate within the wall-clock), typical **p50 ≤ 70s**. Document that the 60s SLO is
deferred to production/ISI hardware.

1. **R4.1 Cancellable timeouts around retrieval + cloud HTTP.** The current blocker is that a blocking
   child call can outlive the parent deadline. Make retrieval/cloud calls enforce a real, abortable
   per-call timeout (Guzzle connect/read timeouts that actually cut the call; cap per-stage budget) so
   the workflow **always** returns the best scored candidate within the wall-clock deadline — never an
   overrun. *Done when:* no turn in the four-turn harness exceeds the wall-clock deadline, and each
   returns a scored candidate.
2. **R4.2 Cut retrieval call count.** More aggressive in-turn caching (dedupe queries across
   attempts/candidates); skip re-retrieval when the first attempt already clears a similarity/quantity
   threshold; reduce max attempts where diagnostics show diminishing returns. Target ≤2 retrieval
   calls/turn typical. *Done when:* measured retrieval calls/turn drop with eval still green.
3. **R4.3 Re-measure + set the dev SLO.** Rerun the four-turn latency harness + `gate:eval`; record the
   before/after table; confirm p95 ≤ 90s and no overruns, or document the residual retrieval floor.
   Keep the sequential + full-pipeline paths reachable by config (eventual Ollama target).

## Part 2 — Retrieval-trap quality reframe (no forcing)

4. **R4.4 Drop the "reroute must win" hard bar.** Update any acceptance text/tests that asserted it.
5. **R4.5 Judge the actual quality.** Compare the initial vs rerouted retrieval-trap answers
   (`eval/latency/run3/retrieval_trap_three_iterations.json`): is the initial candidate clinically
   wrong (real routing drift) or acceptable? Write the determination in the progress log.
   - If the Critic **should** penalize a mis-routed candidate more, improve the **general**
     `routing_validity` rubric in `CriticAgent` (never case-specific) so a genuinely mis-routed
     candidate scores lower, then re-run and report whether the reroute now wins on merit.
   - If the initial candidate is actually fine, document that the trap does not cause drift in v2 and
     close it as a pass. *Done when:* a written quality determination + any general rubric change, eval green.

## Part 3 — S0: wire AnswerAssembly + eval checkpoint (the pivot)

6. **R4.6 Wire `AnswerAssembly` into the answer path** behind the `SYNTHESIS_OWNER=adapter|laravel`
   valve (default stays **adapter**). Laravel synthesis uses cloud (`SYNTHESIS_MODEL=cloud`,
   gpt-5-mini/gpt-4.1). Audited-snippet flag stays **OFF** (⛔HUMAN sign-off). Consume the EXISTING
   planner/retrieval/gap_assessment outputs (do not disturb the live pipeline).
7. **R4.7 S0 checkpoint (the binding gate).** Run `gate:eval` 15-case + gap-taxonomy via the Laravel
   synthesis path with the **external cloud judge**. *Done when:* **no grade drop** + **verbatim ≥98%**
   through the Laravel path, adapter path intact at the default, scorecard recorded. This is the S0
   acceptance from plan §8.

## Guardrails specific to this run

- Perf work must preserve behavior: a caching/timeout change that alters an answer is a bug, not a trade.
- S0 must never make the Laravel synthesis path the default — `SYNTHESIS_OWNER=adapter` stays default.
- No case-specific behavior anywhere (Part 2): fix the general rubric or accept the evaluator's verdict.
- End with a progress-log summary: latency before/after + new dev SLO, retrieval-trap determination,
  S0 checkpoint scorecard, done vs blocked, recommended next run.
