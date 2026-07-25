# Codex Run 5 — F3 deadline fix + adopt bridge-side Cohere rerank

Branch `claude/prototyping-summary-d597c2`. `git pull` first. Follow the **autonomy rules in
`docs/CODEX_HANDOFF.md`** (progress log, commit often, verify-before-commit, cloud-only, never touch
`main`/deploy/adapter-DB/force-push, ⛔HUMAN → flag & continue). **`gate:eval` is the bar — never
unit/syntax tests.** Measurement discipline: **before/after per-stage p50/p95 + the eval grade for
every change.** Runs on the Hetzner host (needs RAGFlow/Laravel/cloud judge); work in a disposable
checkout, do not change production `.env`/config permanently without the human's OK.

## Context (Run 4 verification, commit 3c78e27)

- `gate:eval` **aborted at F3** (`batch_f3_aaa_clti_sequencing`, turn 29/32): "No candidate completed
  Critic scoring before the deadline" → no 28/3/1 scorecard. PHI change cleared (F3 raw==scrubbed).
- Four-turn latency dev SLO met on the harness (p50 43.2s, p95 60.4s) — but the harness excludes F3.
- The rerank A/B was **void**: FlashRank `local` couldn't init (cache permission) and silently ran
  **no rerank**. **Decision: pursue bridge-side Cohere rerank** — same Cohere v3 model, smaller pool.

## R5.1 — [BLOCKER] Fix the F3 deadline / no-scored-candidate bug

The deadline fix (`56d2666`) prevents overruns but on the heaviest case fires **before the first
Critic score**, returning nothing. Change the budgeting so:
- the **first full pass** (orient → ground → probe → critic once) is **always allowed to complete and
  score** — it is NOT abortable by the wall-clock deadline;
- only the **improve-loop revisions** are deadline-gated;
- size the reserve around **F3** (AAA+CLTI, 2 guidelines, the worst case).

*Done when:* `gate:eval` runs **end-to-end without aborting**, F3 returns a scored candidate, and the
run holds the baseline **28 PASS / 3 MINOR / 1 FAIL, routing 100%, verbatim 100%** under the current
RAGFlow-side Cohere control. Record this as the control scorecard + a four-turn latency table.

## R5.2 — Enable + validate bridge-side Cohere rerank (the goal)

Flip `BRIDGE_RERANK_ENABLED=true` (config/ragflow.php:147 / `.env` in the **disposable checkout only**).
This makes Laravel stop forwarding `rerank_id` to RAGFlow (RAGFlow returns raw vector `top_k`, skipping
its full-pool Cohere hop) and rerank only the final ~36 candidates
(`BRIDGE_RERANK_TOP_N=12` × `CANDIDATE_MULTIPLIER=3`) via the Cohere v2 API directly — same
`rerank-english-v3.0` model.

Run the **four-turn latency harness AND full `gate:eval`** under bridge-Cohere and compare, side by
side, against the R5.1 RAGFlow-Cohere control:
- **latency** p50/p95 (expect a materially smaller tail — Cohere over ~36 vs ~256 candidates);
- **grade** — MUST hold **28/3/1 + verbatim 100%**. Bridge reranks a **smaller candidate set**, so a
  genuinely relevant chunk deep in the vector ranking could be missed → **a grade drop fails the
  option regardless of the speed win.** If it drops, report exactly which case(s)/turn(s) regressed and
  try raising `BRIDGE_RERANK_CANDIDATE_MULTIPLIER` (3→5) and/or `BRIDGE_RERANK_TOP_N` before concluding.

*Done when:* the control-vs-bridge A/B table (latency **and** grade) is recorded with a clear verdict.

## R5.3 — Recommendation + config cleanup (no unapproved prod change)

- If bridge-Cohere **holds the grade** with a latency win → recommend it as the default and prepare the
  change: `BRIDGE_RERANK_ENABLED=true`, and **fix the dead committed default** at
  `config/ragflow.php:30` (`Cohere-rerank-v4.0-pro___OpenAI-API` is an Azure-era leftover — change the
  code default to `rerank-english-v3.0`). **Do NOT flip the production `.env`/defaults yourself** —
  report the exact diff for the human to approve (it's a one-line env change).
- Small robustness fix if trivial: make a rerank init failure (e.g. the FlashRank cache case) **fail
  loud / fall back explicitly** rather than silently disabling reranking. Otherwise note it.

## Deferred / not this run
- **FlashRank (`local`) A/B** — remains the air-gapped-ISI fallback; repair its writable model cache in
  a maintenance window and A/B it later. Not needed for the bridge-Cohere decision.
- **S0** — only after R5.1 makes `gate:eval` pass green.

## End with a progress-log summary
Control scorecard (R5.1) + four-turn latency; bridge-Cohere A/B table (latency + grade) + verdict;
recommended config diff (for human approval); done vs blocked; recommended next run.
