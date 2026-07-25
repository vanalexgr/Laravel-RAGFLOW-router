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

## ⚠️ REALITY CHECK (Run 5, 2026-07-25): the "28/3/1 baseline" was FAKE

It was `gate:eval --sut=stub --judge=stub` fixture output, never real. The first **real** external eval
(`--sut=http --judge=external`) = **3 PASS / 14 MINOR / 15 FAIL** — but confounded (judge calibration
unverified; baseline_grades fixture-derived; ≤2-guideline cap auto-fails 3-route cases) and the artifact
was lost to cleanup. **We do NOT yet have a trustworthy quality measurement.** R5.1 (F3 deadline/first-pass
guarantee) worked but was NOT committed (quality gate failed). Latency/bridge-rerank/S0 were all premature.
**The human (clinician) will be the judge-calibrator.** Mission is now: get a trustworthy real baseline,
then actually raise quality.

## The active next step = Codex Run 6 (trustworthy baseline + bridge-rerank latency)

Backlog: **`docs/CODEX_RUN6_BACKLOG.md`**. Two parallel tracks: (A) fix the eval framework (COMMIT
artifacts + a `docs/eval/run6_fail_digest.md`, fix the 2→3 guideline cap, kill dead
`CANDIDATE_MULTIPLIER`), re-run the real external eval, and commit per-FAIL transcripts for the human to
spot-read; (B) standalone **bridge-rerank latency A/B** (control vs `BRIDGE_RERANK_ENABLED=true`, timing
only — needs no quality baseline). Paste this prompt into Codex:

> Continue on branch **`claude/prototyping-summary-d597c2`**. **`git pull` first**, open **`docs/CODEX_RUN6_BACKLOG.md`**, work both tracks. Autonomy rules in `docs/CODEX_HANDOFF.md`; keep `docs/CODEX_PROGRESS.md` current. Runs on Hetzner (disposable checkout); **do not change production `.env`/config/defaults**. Only `--sut=http --judge=external` counts as real eval.
>
> **The "28/3/1" was fixture (`--sut=stub --judge=stub`), never real.** First real eval = 3/14/15, confounded, and its artifact was lost. Don't chase the gpt-5 judge — the human (clinician) will calibrate it. This run produces a fair, inspectable baseline + the bridge-rerank latency number.
>
> **Track A — trustworthy baseline.** (R6.1) **[mandatory] commit eval artifacts**: the full run JSON **and** a human-readable `docs/eval/run6_fail_digest.md` (per FAIL/MINOR: scenario, turn, expected vs routed guidelines, judge grade+reason+labels, and the gate's `guideline_grounded_answer`+`interpretive_frame` verbatim) — results must survive checkout cleanup (Run-5's were lost). Fix the **≤2-guideline cap** vs scenarios expecting 3 routes (reconcile with the 1–3 guideline contract; re-verify routing). Remove/implement the dead `BRIDGE_RERANK_CANDIDATE_MULTIPLIER`. (R6.2) Re-run the real external eval; commit artifact + digest. **Do NOT overwrite fixture `baseline_grade`s and do NOT treat the judge as authoritative** — output real grades for human review; note `no_grade_drop` is meaningless until re-baselined.
>
> **Track B — bridge-rerank latency (standalone, no baseline needed).** (R6.3) Four-turn latency harness: control = RAGFlow-side Cohere (live) vs `BRIDGE_RERANK_ENABLED=true` = bridge-side Cohere (same `rerank-english-v3.0`, reranks ~36 vs full pool). Record per-stage **p50/p95 before/after** + the tail delta. **No grade/quality conclusion** (waits for the human-calibrated baseline); **don't flip prod defaults.**
>
> Deferred: bridge-rerank grade verdict, quality-improvement work, FlashRank, S0. Guardrails: commit artifacts; never set baseline_grades from the strict judge without human sign-off; cloud only; never touch `main`, deploy, adapter DB, force-push, or prod defaults; ⛔HUMAN → flag & continue. End: committed digest path, real grade summary (for human review, not a pass/fail), bridge-rerank latency A/B + delta, next step = await human FAIL review.

## The active next step = Codex Run 5 (F3 deadline fix + bridge-side Cohere rerank) [SUPERSEDED by Run 6]

Backlog: **`docs/CODEX_RUN5_BACKLOG.md`**. **Requires the Hetzner host** (RAGFlow/Laravel/cloud judge).
Run-4 verification (`3c78e27`) found: `gate:eval` aborts at F3 (deadline/no scored candidate — no 28/3/1
yet); PHI change cleared; latency SLO met on the harness; the FlashRank A/B was void (cache permission →
silent no-rerank). **Decision: pursue bridge-side Cohere rerank.** Paste this prompt into the Codex app:

> Continue the unattended Agentic Gate v2 run on branch **`claude/prototyping-summary-d597c2`**. **`git pull` first**, then open **`docs/CODEX_RUN5_BACKLOG.md`** and work it top-to-bottom. Follow the **autonomy rules in `docs/CODEX_HANDOFF.md`**; keep **`docs/CODEX_PROGRESS.md`** current. **`gate:eval` is the bar — never unit/syntax tests.** Record **before/after per-stage p50/p95 + the eval grade for every change.** Runs on Hetzner; work in a disposable checkout; do not change production `.env`/config without the human's OK.
>
> **R5.1 — [BLOCKER] Fix the F3 deadline / no-scored-candidate bug.** The deadline (`56d2666`) prevents overruns but on the heaviest case (F3 `batch_f3_aaa_clti_sequencing`, AAA+CLTI, 2 guidelines) fires before the first Critic score → returns nothing → `gate:eval` aborts. Change the budgeting so the **first full pass** (orient→ground→probe→critic once) always completes and scores (NOT abortable by the wall-clock), and only the **improve-loop revisions** are deadline-gated; size the reserve around F3. **Done when:** `gate:eval` runs end-to-end without aborting, F3 returns a scored candidate, and it holds **28 PASS / 3 MINOR / 1 FAIL, routing 100%, verbatim 100%** under the current RAGFlow-side Cohere control. Record the control scorecard + four-turn latency table.
>
> **R5.2 — Enable + validate bridge-side Cohere rerank (the goal).** Flip `BRIDGE_RERANK_ENABLED=true` (disposable checkout only). Laravel then stops forwarding `rerank_id` to RAGFlow (raw vector `top_k`, no full-pool Cohere hop) and reranks only the final ~36 candidates (`BRIDGE_RERANK_TOP_N=12`×`CANDIDATE_MULTIPLIER=3`) via Cohere v2 directly — same `rerank-english-v3.0`. Run the four-turn latency harness **and** full `gate:eval` and compare, side by side, to the R5.1 control: **latency** p50/p95 (expect a smaller tail) **and grade — MUST hold 28/3/1 + verbatim 100%.** Bridge reranks a smaller candidate set, so a relevant chunk deep in the vector ranking could be missed → **a grade drop fails it regardless of the speed win**; if it drops, report which case(s) regressed and try raising `BRIDGE_RERANK_CANDIDATE_MULTIPLIER` (3→5) / `BRIDGE_RERANK_TOP_N` before concluding. **Done when:** the control-vs-bridge A/B table (latency + grade) is recorded with a verdict.
>
> **R5.3 — Recommendation + config cleanup (no unapproved prod change).** If bridge-Cohere holds the grade with a latency win → recommend it as default and prepare the diff: `BRIDGE_RERANK_ENABLED=true` + fix the **dead** committed default `config/ragflow.php:30` (`Cohere-rerank-v4.0-pro___OpenAI-API`, Azure-era) → `rerank-english-v3.0`. **Do NOT flip production `.env`/defaults yourself — report the diff for approval.** If trivial, make a rerank init failure fail loud / fall back explicitly instead of silently disabling rerank.
>
> Deferred: FlashRank `local` A/B (air-gapped-ISI fallback; repair cache later); **S0** only after R5.1 makes `gate:eval` pass green. Guardrails: perf changes must preserve behavior (an altered answer is a bug); never touch `main`, deploy, push the adapter DB, force-push, or change prod defaults unapproved; ⛔HUMAN → flag and continue. End with a progress-log summary: control scorecard + latency, bridge-Cohere A/B table + verdict, recommended config diff, done vs blocked, next run.

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
