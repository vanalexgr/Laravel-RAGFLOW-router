# Codex Run 6 — trustworthy real baseline (for the human judge) + bridge-rerank latency

Branch `claude/prototyping-summary-d597c2`. `git pull` first. Autonomy rules in `docs/CODEX_HANDOFF.md`;
keep `docs/CODEX_PROGRESS.md` current. Runs on Hetzner; disposable checkout; **do not change production
`.env`/config/defaults.** `gate:eval --sut=http --judge=external` is the only real eval — stub runs are
plumbing tests, not quality.

## Why this run exists (Run 5 finding)

The historical "28/3/1" was **`--sut=stub --judge=stub` fixture output**, never a real measurement. The
first real external eval = **3 PASS / 14 MINOR / 15 FAIL** — but it is **confounded** (judge calibration
unverified; per-case `baseline_grade`s came from the fixture; the ≤2-guideline cap auto-fails 3-route
scenarios) and the artifact was **lost to checkout cleanup**. So we do NOT yet have a trustworthy quality
number. **The human (clinician) will be the judge-calibrator.** This run's job is to produce a fair,
inspectable real baseline for them — and, separately, the bridge-rerank latency number.

## Track A — trustworthy baseline (surface, don't chase the judge)

1. **R6.1 Eval-framework fixes.**
   - **[mandatory] Persist eval artifacts in the repo:** commit the full run JSON **and** a
     human-readable **`docs/eval/run6_fail_digest.md`** — one entry per FAIL/MINOR with: scenario_id,
     turn, expected vs routed guidelines, judge grade + reason + failure_labels, and the gate's
     `guideline_grounded_answer` + `interpretive_frame` verbatim. (Results must survive cleanup — the
     Run-5 artifact was lost.)
   - **Fix the ≤2-guideline cap:** it conflicts with scenarios expecting 3 routes and feeds the 87.5%
     routing. Reconcile with the tool's 1–3 guideline contract so a legitimate 3-route case is not
     auto-failed. Re-verify routing after.
   - **Reconcile dead code:** `BRIDGE_RERANK_CANDIDATE_MULTIPLIER` is unused — implement it or remove it
     and the docs that reference it.
2. **R6.2 Re-run the real external eval** (`--sut=http --judge=external`) and commit the artifact +
   `run6_fail_digest.md`. **Do NOT overwrite `baseline_grade`s** (they are fixture-derived/invalid) and
   **do NOT treat the gpt-5 judge as authoritative** — output the real grades for human review and note
   `no_grade_drop` is meaningless until re-baselined by the human. *Done when:* the digest + artifact are
   committed for the clinician to spot-read.

## Track B — bridge-rerank latency (standalone; needs NO quality baseline)

3. **R6.3 Bridge-rerank latency A/B (timing only).** Latency and quality are separable — measure the tail
   now, independent of the baseline. Run the **four-turn latency harness** under: (a) control =
   RAGFlow-side Cohere (current live), (b) `BRIDGE_RERANK_ENABLED=true` = bridge-side Cohere (same
   `rerank-english-v3.0`, reranks ~36 vs the full pool). Record per-stage **p50/p95 before/after** and the
   tail delta. **Do NOT draw a grade/quality conclusion** (that waits for the human-calibrated baseline);
   **do NOT flip prod defaults.** *Done when:* the latency A/B table is committed with the delta.

## Deferred (do not start)
- The **grade verdict** on bridge-rerank (needs the human-calibrated baseline).
- **Quality-improvement** work on the failure cluster (`missing_required_facts`/F3/F4/F6) — waits for the
  human's FAIL review to confirm what's a genuine failure vs strict-judge.
- FlashRank A/B; S0; latency chasing.

## Guardrails
Commit artifacts (the whole point of Track A). Never set `baseline_grade`s from the strict judge without
human sign-off. Cloud models only. Never touch `main`, deploy, push the adapter DB, force-push, or change
production `.env`/config/defaults. ⛔HUMAN → flag & continue. End with a progress-log summary: committed
digest path, real grade summary (for human review, NOT declared a pass/fail), bridge-rerank latency A/B
table + delta, recommended next step (await human FAIL review).
