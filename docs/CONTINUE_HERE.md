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

## Prod incident RESOLVED (2026-07-25)

OpenAI had a partial-degradation incident (Login/Responses/Embeddings) that confounded Run 5/6 (judge
401, empty/slow retrieval). **OpenAI recovered** (chat + embeddings verified 200 from Hetzner).
Separately, the live OWUI model `gpt-5-chat-latest` was **deprecated**; **repointed to `gpt-5.2-chat-latest`**
(ESVS-expert base + base row + `task.model`; webui.db backed up; restarted; 0 old refs). Prod OWUI +
vascular MCP adapter confirmed working. Laravel `.env AZURE_OPENAI_DEPLOYMENT=gpt-5-chat` is stale/inert
(gate uses gpt-5-mini) — cleanup later.

## The active next step = Codex Run 6 (RESUME, sequenced): baseline → ISOLATED bridge-rerank A/B → decisive split

Backlog: **`docs/CODEX_RUN6_BACKLOG.md`**. Infra fixes already validated on the resume (OpenAI recovered:
chat+embeddings 200; healthy RAGFlow retrieval = **6 chunks in 3.1s/6.7s**; judge-env fixed; the eval "401"
was the SUT hitting the API-key-protected `/api/v1/agent-consult`, so the SUT must invoke `GateWorkflowService`
directly, Run-5 style). **KEY OPEN QUESTION:** healthy per-call retrieval is only ~3–7s, so the premise that
bridge rerank fixes latency may be FALSE — the 25s that motivated it was the OpenAI embeddings incident. This
run must *test that assumption*, not assume it. The baseline is a quality artifact for the **clinician's**
judge-calibration (surface FAIL transcripts; don't chase the gpt-5 judge). Paste this prompt into Codex:

> Continue the Run 6 resume on **`claude/prototyping-summary-d597c2`** (Hetzner, disposable checkout). Autonomy rules in `docs/CODEX_HANDOFF.md`; keep `docs/CODEX_PROGRESS.md` current. Run these **in strict sequence — never concurrently** (concurrency skews the latency numbers). Both long runs must be **detached** (`nohup` + persistent log + exit marker; never foreground over SSH — a broken session already killed one 29/32 run before its all-at-end artifact).
>
> **Step 1 — finish + commit the baseline (already running detached).** On the exit marker, **commit the artifact + `docs/eval/run6_fail_digest.md`** — per FAIL/MINOR: scenario, turn, expected vs routed guidelines, judge grade+reason+labels, the gate's `guideline_grounded_answer`+`interpretive_frame` verbatim, **and a per-case "retrieval returned N chunks" flag** (separate retrieval-starvation FAILs from reasoning FAILs). Do NOT overwrite fixture `baseline_grade`s or treat the judge as authoritative — the human (clinician) calibrates. SUT = direct `GateWorkflowService` invocation (Run-5 style), NOT the auth-protected HTTP endpoint.
>
> **Step 2 — bridge-rerank latency A/B, in ISOLATION, detached.** Only after Step 1 completes and nothing else is hitting RAGFlow/OpenAI. Four-turn latency harness, two arms back-to-back: **control** = RAGFlow-side Cohere (`BRIDGE_RERANK_ENABLED=false`) vs **bridge** = `BRIDGE_RERANK_ENABLED=true` (bridge-side Cohere, same `rerank-english-v3.0`). Healthy OpenAI; 60s gate retrieval timeout (checkout only). Record **per-stage p50/p95 (orient / retrieve / pathway / probe / critic) + total** for BOTH arms + tail delta.
>
> **Step 3 — the decisive analysis (the point of the run).** From the **control** per-stage data, report **what fraction of a deep turn is retrieval (sum of ALL retrieve calls in the turn) vs the LLM stages (orient+pathway+probe+critic)**, then state the verdict: (a) retrieval a **large** slice AND bridge cuts it materially → bridge rerank helps (grade verdict pending the calibrated baseline); (b) retrieval a **small** slice under healthy conditions → **bridge rerank is a dead end for latency**; retarget the tail at the LLM stages / the re-retrieve+iteration loop (parallelize the ≤2 pathways, smaller/faster orient+pathway models, cap the loop). Note the **GPT-5 judge latency is an eval-harness cost, not production.**
>
> Guardrails: no grade verdict on bridge rerank (waits for the calibrated baseline); don't flip prod defaults/`.env`; commit artifacts; cloud only; never touch `main`/deploy/adapter DB/force-push; ⛔HUMAN → flag & continue. End with: committed baseline digest path, control-vs-bridge per-stage table, the retrieval-vs-LLM split, and the explicit verdict on whether bridge rerank solves the time issue.

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
