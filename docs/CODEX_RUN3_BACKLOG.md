# Codex Run 3 — backlog (latency first, then extend)

Branch `claude/prototyping-summary-d597c2`. `git pull` first. Follow the **autonomy rules in
`docs/CODEX_HANDOFF.md`** (progress log, commit often, verify-before-commit, cloud-only, never touch
`main`/deploy/adapter-DB/force-push, ⛔HUMAN → flag & continue). Keep `docs/CODEX_PROGRESS.md` current.

## Context (from Run 2)

Milestone A works and beats the AAA benchmark on quality (state retention + routing), BUT deep turns
take ~92–180 s and **the evaluate-and-improve loop times out mid-revision** — AAA T2 returned a
*disapproved* 0.40 candidate because the 2nd Critic timed out, and the retrieval-trap adversarial
failed before its corrected reroute could win. Latency is the blocker. Root cause: ~15 sequential
LLM+retrieval calls/turn (retrieval 10–20 s each, probe ~20 s, orient ~13 s).

## HARD ACCEPTANCE BAR (the run's definition of success)

- **Deep-turn p95 ≤ 60 s**, and **at least one full critique + one revision completes within the 90 s
  workflow deadline** on the AAA (all 3 turns) and retrieval-trap cases.
- The retrieval-trap corrected `case_new` reroute **wins** (replaces the retained candidate).
- **Eval stays green throughout:** `gate:eval` = 28 PASS / 3 MINOR / 1 FAIL (or better), routing 100 %,
  no-grade-drop YES, verbatim ≥ 98 %. **Every performance change must re-run the eval; perf may not cost
  correctness.**
- If the bar can't be met, document the wall (which stage/model/retrieval dominates) and the best
  achieved numbers — do not fake it.

## Measurement discipline

No unmeasured performance claims. Record **before/after** per-stage p50/p95 + total for each latency
change in the progress log, from the same cases.

## Ordered backlog

### Part 1 — Latency (primary)

1. **L1 Latency harness + baseline.** Add a repeatable latency benchmark (a `gate:probe2` flag or a
   small command) that runs AAA×3 + retrieval-trap and prints per-stage p50/p95 + total. Record the
   **current baseline** numbers. *Done when:* one command reproduces the latency scorecard.
2. **L2 Parallelize the deep path (cloud).** Run the ≤2 candidate pathways concurrently and overlap
   independent retrieval + pathway-assessment calls (Laravel `Concurrency`/async). Keep the sequential
   path behind a config flag (local/Ollama-faithful for later). *Done when:* deep-turn wall-time drops
   materially vs L1 and eval is green.
3. **L3 Cut retrieval cost.** Cache retrieval within a turn (dedupe identical queries), cap retry
   `top_k`, reserve the full pipeline for the final attempt only, reduce max attempts where diagnostics
   show diminishing returns. *Done when:* fewer/faster retrieval calls, eval green.
4. **L4 Fix the loop-timeout-mid-revision bug.** The deadline + bounce budgets must guarantee (a) the
   best **scored** candidate is returned, never a disapproved one that happened to finish first, and
   (b) at least one full critique+revision completes within the deadline. Restructure the deadline if
   needed. *Done when:* AAA T2 returns a completed, re-scored, better candidate within the deadline.
5. **L5 Right-size per-call budgets.** Tune max-tokens / reasoning-effort / prompt size per stage;
   use the cheapest viable model for orient + pathway; trim probe. *Done when:* per-stage p95 down with
   no eval regression.
6. **L6 Rerun + record.** Run AAA×3, retrieval-trap, and the remaining adversarial scenarios through
   the optimized workflow; paste transcripts + stage traces + the latency scorecard into the progress
   log. *Done when:* retrieval-trap reroute wins and the HARD BAR numbers are recorded.

### Part 2 — Extend (only after Part 1's bar is met or documented as blocked)

7. **A1 Begin S0 — `AnswerAssembly`** (plan §8, migration review Q1/Q2): deterministic PHP section
   skeletons (`response_mode` variants + gap taxonomy + canned strings + assets) + ONE cloud fill-call,
   behind the `SYNTHESIS_OWNER=adapter|laravel` valve, consuming the EXISTING planner/retrieval/
   gap_assessment. Audited-snippet flag stays **OFF** (⛔HUMAN sign-off). *Done when:* `gate:eval`
   15-case + gap-taxonomy shows **no grade drop** + verbatim ≥ 98 % via the Laravel synthesis path,
   adapter path intact at `=adapter`. (Large; commit incrementally — fine if it spans into a later run.)
8. **A2 Investigate the 3 legacy test failures** (`ChangeDetectionServiceTest` prompt-copy;
   `PreRetrievalServiceTest` two safe-default expectations). Determine if they are obsolete under the
   v2 direction or genuine. **Do NOT delete/modify tests that guard live production behavior** without
   a written justification. *Done when:* a determination + recommendation is in the progress log; only
   safe, justified changes made.
9. **A3 Document the 4 Composer advisories.** List each + severity + recommended action. **Do NOT
   auto-upgrade** (risks prism/vizra coexistence). *Done when:* documented + flagged for a separate
   dependency-maintenance change.

## Guardrails specific to this run

- Perf work must **preserve behavior**: if a parallelization/caching change alters an answer, treat it
  as a bug, not an acceptable trade.
- Keep the sequential + full-pipeline paths reachable by config (do not hard-delete the local-faithful
  path — it is the eventual Ollama target).
- S0 (A1) must never make the Laravel synthesis path the default — the valve stays `=adapter` by default.
- End with a progress-log summary: latency before/after table, eval scorecard, what's done vs blocked,
  and the recommended next run.
