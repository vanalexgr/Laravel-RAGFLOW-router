# Development plan — gate v2

Supersedes the priority ordering in `docs/CODEX_RUN7_BACKLOG.md` and
`docs/CODEX_RUN8_ABLATION.md`. Those remain valid as records of what was measured.

Written 2026-07-26, after the clinician reviewed a live answer and settled the product
philosophy. Development is paused until usage credits reset; this is the plan to execute on
resume.

---

## 0. Governing principle

**The app surfaces the available evidence so the clinician stays on top of the decision.
It is not meant to produce a single deterministic recommendation.**

Clinician, reviewing the S2 answer that offers VKA, aspirin, and aspirin+rivaroxaban without
ranking them:

> "The scope of the app is less to provide extremely deterministic answers and more to show the
> available evidence to the user. The user clinician should be on top of decisions and this
> answer leaves him on top of decision. It offers good support."

Clinical basis they gave: VKA has been standard for years where bypass patency is a concern;
aspirin alone is standard for a good bypass with good outflow; rivaroxaban+aspirin is the newer
entry for difficult cases and many would choose it for patient convenience and lower bleeding
risk. There is genuinely no single correct regimen, so any rubric demanding one is
over-specified.

Everything below derives from this.

---

## 1. What this retires or reverses

| Previously treated as | Now |
|---|---|
| "Excessive deference / won't commit" is a top failure class | **Not a defect.** Offering options with class and level, and leaving the call to the clinician, is the product working. This framing came from an external critique and was carried unexamined for a full day. |
| Build the decision-composition contract as designed | **Do not.** Keep the completeness and rule-based contraindication checks in `app/Ai/Gate/Decision/`. Drop the mandated-commitment fields and the deferral-code policing. |
| Off-topic retrieved recommendations are a precision failure | **Acceptable, even welcome.** They show what was processed and the clinician can click through; labelling them off-topic improves the reference list. Only ensure they never crowd out on-topic ones. |
| Interpretive-frame content from model training is a grounding leak | **Fine, provided it is flagged as non-ESVS.** This makes accurate provenance labelling load-bearing rather than cosmetic. |
| FAIL counts measure system quality | **Suspect.** See section 2. |
| Clickable citations are presentation polish (R7.8/R7.9) | **Priority feature.** The clinician wants to click through. |

---

## 2. Evaluation model change — the clinician is ground truth

The external GPT-5 judge graded the S2 answer FAIL for not committing to aspirin+rivaroxaban.
The clinician graded the same answer as good support. **The judge was wrong, because its rubric
encodes a product philosophy we have now rejected.**

Consequences:

- The LLM judge is demoted from ground truth to a **screening tool**. Useful for catching
  contract and mechanical failures at volume; not authoritative on clinical quality.
- Some meaningful share of the 11–12 FAILs in the last paired run may be the rubric penalising
  correct behaviour. That is a **third candidate cause** for the unexplained zero-PASS result,
  alongside the reranker switch and the intervening code changes.
- **Do not tune anything against those grades** until the rubric is rewritten or the clinician
  has scored the same cases.
- Rewriting the rubric is itself now a task (section 4, item 4).

---

## 3. Stage 1 on resume — clinician review round

The clinician has offered to review and score answers directly. This is the primary evaluation
gate from here.

### Mechanics

1. Run each case **once** through `php artisan gate:probe2 --scenario=<id> --json` with
   `GATE_V2_PERSIST_SNIPPET_DIGESTS=true`. No LLM judge — the clinician scores. This removes
   judge cost entirely.
2. Enable `GATE_V2_RETRIEVAL_DEV_CACHE_TTL=3600` for the batch. Rerank is billed per call, so
   the cache is the only real cost lever.
3. Export one review packet per case in the format that worked for S2: question as asked,
   patient model extracted, both answer frames verbatim, every snippet with recommendation id /
   class / level / source / relevance mark, and the retrieval table. Render as a page — the
   clinician engaged substantively with the rendered version, not the raw markdown.
4. Deliver the packets plus the scoring sheet below. Do not pre-judge; present the output and
   the flagged uncertainties, not a verdict.

### Proposed case set (12)

Chosen to span the failure classes rather than to flatter the system.

| # | Case | Why it is in the set |
|---|---|---|
| 1 | `batch_s2_post_vein_bypass_antithrombotics` | Anchor — already reviewed and judged good; detects regression |
| 2 | `batch_s6_urgent_cea_af_apixaban` | Safety-critical; earlier flagged as possibly unsafe advice |
| 3 | `batch_f5_urgent_cea_af_recent_gi_bleed` | Safety-critical; competing bleeding and stroke risk |
| 4 | `batch_f2_clti_itp_after_bypass` | Interaction gap — thrombocytopenia modifies a standard regimen |
| 5 | `batch_f1_clti_aps_warfarin` | Interaction gap — antiphospholipid syndrome |
| 6 | `batch_f3_aaa_clti_sequencing` | Competing priorities, no direct sequencing recommendation |
| 7 | `batch_f4_aaa_clti_sepsis_anticoagulation` | Three guidelines, sepsis dominance |
| 8 | `batch_c3_carotid_near_occlusion_criteria` | Pure knowledge question, no patient |
| 9 | `batch_c2_brachial_vein_compression` | Genuinely uncovered scenario |
| 10 | `batch_s5_iliofemoral_dvt_recent_surgery` | Recent surgery modifies anticoagulation |
| 11 | `batch_s4_symptomatic_carotid_web` | Rare entity, thin evidence |
| 12 | `aaa_evolving_context` (all 3 turns) | **Multi-turn state.** The largest measured failure class and currently unmeasurable — see section 4, item 1 |

### Scoring sheet — per case

Kept short deliberately; a surgeon will not fill twenty fields per case. Clinical and
mechanical dimensions are scored separately so a formatting miss never masks a clinical one.

**Clinical (what matters)**

| Item | Scale |
|---|---|
| Were the relevant recommendations surfaced? | none / some / all that matter |
| Was anything clinically important **missing**? | free text |
| Is anything stated **unsafe or misleading**? | no / minor / yes + free text |
| Are class and evidence level correct as shown? | yes / no + which |
| Does it leave you on top of the decision? | no / partly / yes |
| Would you use this output in practice? | no / with edits / yes |

**Mechanical (report separately, never averaged with the above)**

| Item | Scale |
|---|---|
| Provenance correctly labelled (ESVS vs other guideline vs model interpretation)? | yes / no + which |
| Any snippet truncated, garbled, or unusable? | count |
| Patient facts carried correctly across turns (multi-turn cases only)? | yes / no + what was lost |

**Free text:** anything the sheet does not capture.

### Success criterion for the round

Not a score threshold. The purpose is to find out **which of our remaining "defects" the
clinician actually cares about**, and to replace the rubric with their judgement. Today proved
that a full day can be spent optimising against the wrong target.

---

## 4. Backlog, reordered

1. ~~**Multi-turn review support.**~~ **DONE 2026-07-26 — it already existed.** `gate:probe2`
   iterates turns and threads state between them, so case 12 is unblocked. `gate:variance` still
   requires exactly one turn; use `gate:probe2` for multi-turn review packets.

1b. **MEASURED, and it is severe.** Running the 3-turn AAA case live
   (`docs/eval/run11_aaa_multiturn_state_loss.txt`): turn 3 discards the ENTIRE accumulated patient
   model and keeps only the newly-mentioned fitness. Age, sex, 5.8 cm diameter, juxtarenal anatomy,
   EVAR unsuitability, eGFR 28 and asymptomatic status all vanish; coverage collapsed to
   `not_covered` as a direct result. Not gradual erosion — Orient re-derives the model from the
   latest turn alone.
   A replay test now PROVES the state ledger fixes this: every field established in turns 1-2
   survives a turn 3 that does not mention them
   (`tests/Unit/GateEval/AaaMultiTurnStateRetentionTest.php`). The ledger is therefore the fix for
   the largest failure class — promoted to item 4 below.

1c. **NEW — atomic patient-model fields.** The deeper issue the ledger does NOT solve. Turn 2's
   lesion string drops "5.8 cm" while adding juxtarenal anatomy, and the reducer overwrites the
   whole string, because clinical facts are packed into free prose. Decompose `lesion` into atomic
   fields (diameter / anatomy / suitability) so an unmentioned diameter is simply retained. Ripples
   into the Orient schema, retrieval query construction and the answer prompt, so it needs a design
   pass first.

2. **Progress visibility — BLOCKED, needs a decision, not just code.** `/api/v1/clinical-gate`
   returns a single JSON response; there is no streaming, so server-side emissions cannot reach the
   user however they are wired. And the adapter does not call the gate endpoint at all yet. The
   transport must be chosen first — SSE, a polled progress endpoint, or client-side estimation in
   the adapter — and that is a product decision. The four breaks below are still accurate and still
   need fixing once transport is settled.

   **Progress visibility during the wait — nothing reaches the user today.** At p50 the user
   waits 47 s and at p95 80 s, and sees only OpenWebUI's pulsating dot. A clinician who sees a
   silent dot for 80 s assumes it has hung and retries, which starts a second full run.
   Four separate breaks, all of which must be fixed for any of it to show:
   - `GateWorkflowService::run()` defaults to `new NullGateProgress`, which discards every
     emission, and no caller on the HTTP path passes a real channel. The seven status lines that
     exist are therefore emitted to nobody. They surface only via `LogGateProgress` on the CLI.
   - The OpenWebUI adapter's `EMIT_STATUS_EVENTS` valve defaults to **false**.
   - The adapter does not call `/api/v1/clinical-gate` at all — it is still on the legacy
     `/vascular-consult` path, so gate v2 has no user-facing surface yet.
   - Granularity is wrong even once wired: `GatePathwayWorker` emits nothing, so the dominant and
     most variable stage — parallel per-guideline retrieval plus pathway assessment, p50 7.5 s per
     call across N branches — is entirely silent. The gap between "Retrieving…" and "Checking
     state…" is where most of the wait lives. The adapter also throttles identical text to one
     update per 8 s, so a long stage with one static line looks frozen.
   Emit per-branch progress ("Searching CLTI guidance… 2 of 3"), and surface the deliberate
   re-think events the README already intends to explain added latency.

3. **Latency re-measurement, as a gate before the review round.** Nothing has been latency-tested
   since any of today's changes, and multi-query is now ON by default on grade-neutrality alone,
   without its latency cost being measured. Last authoritative figures (Run 7, `docs/eval/
   run7_latency_20260725_220506.json`): p50 47.1 s, p95 80.6 s against a 90 s deadline, retrieval
   dominant at p50 7.5 s / p95 12.9 s per call. Run 6 control was p50 43.7 s and Run 6 with
   bridge-side Cohere rerank was p50 34.0 s, so Run 7 was already the slowest of the three.
   Run 7's default concurrent run **aborted at 24 of 32 turns** on a fixed 60 s child-process
   timeout — below the 80.6 s p95 — so the pipeline was exceeding its child budget before
   multi-query existed. Multi-query can take up to 1.5x on the dominant stage; the narrative-fetch
   fix and the now-working retry-skip gate push the other way. Net effect unknown.
   This gates the review round: a run that aborts mid-way produces packets that cannot be scored.
   Measure the 3-guideline shapes first, they are the most exposed.

4. **Rewrite the eval rubric** to score evidence surfacing rather than single-regimen
   commitment. Until then the LLM judge is screening only.
5. **Clickable citations** (R7.8 / R7.9). Promoted at the clinician's request. Depends on the
   labelled citation bucket, which now exists.
6. **Fix provenance labelling.** The answer says "From the retrieved ESVS text" over four
   snippets that came from Global Vascular Guidelines. If flagging is what makes interpretive
   content acceptable, a wrong flag is load-bearing.
7. **Fix class/level parsing.** Emits `Iib`, `Ila` and bare `2`. Matters more now that weighing
   class and level is the clinician's job.
8. **Matched reranker A/B** on identical code, Cohere versus local. Still the only way to
   explain the zero-PASS collapse — but note grades are a suspect measure, so read it on
   citation supply and relevance as well.
9. **State ledger** — PROMOTED, see item 1b; it is the proven fix for the largest failure class (`gate-state.shadow_enabled`, currently off). Multi-turn state loss is
   unaffected by the philosophy change and remains a genuine safety issue: a knowledge-question
   interleave converted an asymptomatic aneurysm into a symptomatic one.
10. **Decision contract, reduced scope.** Completeness and contraindication checks only.
11. **Drop truncated snippets** before they reach the answer stage.
12. Deferred: `similarity` scale is now correct but unvalidated against retrieval quality;
    `filterRawChunksToSelectedGuidelines` remains a weak guard for the legacy adapter path.

---

## 5. Known defects, current

- Provenance mislabel: GVG content presented as ESVS.
- Class/level parsing garbled (`Iib`, `Ila`, `2`).
- One narrative snippet arrived truncated mid-sentence and unusable.
- Multi-turn state loss, including an asymptomatic → symptomatic contradiction.
- The durable ledger event store is **unverified**: its test skips because the deployment host's
  PHP has only the `mysql` PDO driver while `phpunit.xml` uses `sqlite::memory:`.
- No progress is visible to the user during a 47-80 s wait: `NullGateProgress` is the effective
  default on the HTTP path, the adapter's status valve is off, and the worker emits nothing.
- Latency is unmeasured since the multi-query change, which is enabled by default.
- Synthesis is nondeterministic: identical evidence and identical upstream state produced
  different grades. Lower priority under the new philosophy, but it affects reproducibility and
  trust.

## 6. Process note — Antigravity is NOT read-only

Verified 2026-07-26: dispatched as a read-only reviewer, Antigravity created
`app/Ai/Gate/State/StateEventStore.php` and modified two files in the repo. The change was small,
correct and accepted after review, but the orchestration rule in `CLAUDE.md` describing it as
"independent read-only review" was factually wrong and has been corrected. Run `git status` after
any Antigravity call that touched a code question, and review its diff before accepting.

## 7. Standing constraints

- Rerank is billed **per call**, not per document. Lowering `top_k` saves nothing.
  `GATE_V2_RETRIEVAL_DEV_CACHE_TTL` is the lever; never enable it for a latency or reliability
  run.
- Long measurements must run detached on the server and be polled; an MCP call dies at its idle
  timeout.
- Run paired arms in the same session. Run order confounded an entire measurement once already.
- Every disposable checkout copies production `.env`. Remove it when finished.
