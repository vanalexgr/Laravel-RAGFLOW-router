# Agentic Gate v2 — Design Review (Fable)

Reviewed: `docs/AGENTIC_GATE_V2_PLAN.md`, `app/Ai/Gate/README.md` + scaffolds (Triage/Orient/Pathway/Probe/Critic/KnowledgeAnswer agents, `RetrieveEsvsSnippetsTool`, `GateProgress`), `memory/benchmark_aaa_evolving_context.md`. Sources live uncommitted in worktree `prototyping-summary-d597c2`.

**Unverified assumptions I'm making** (flag, per rules of engagement): (a) the ISI model and serving stack are not yet chosen — nothing in the provided files names them; (b) `laravel/ai` v0.10.1 `Concurrency::run()` behavior is taken from Laravel framework docs knowledge (default `process` driver), not verified against the installed version; (c) I assume the existing `STATE_BACKEND=laravel` Redis case-state (DB5) and `PendingCaseStateService` are still live in production as described in memory; (d) I could not verify ISI's PHI-at-rest policy from `docs/HIPAA_COMPLIANCE.md` (not read).

---

## 1. Verdict

The skeleton is sound and the commitments are right — general loop over guards, two tiers, single-brain Laravel, always-answer — and I recommend wiring it, but not as scaffolded. The single biggest thing to change first is to **de-risk the local model before anything else**: name the model and serving stack, require grammar-constrained decoding, and replace the agentic tool-calling loops (PathwayAgent, KnowledgeAnswerAgent) with deterministic PHP-orchestrated retrieve→assess steps, because a 7–14B model doing multi-step tool judgment plus six strict JSON schemas plus calibrated confidence is the design's load-bearing wall and currently pure assumption. Second, three state-ownership problems are unowned and will corrupt the patient_model in production regardless of how good the loop is: retry idempotency, new-case detection, and clarification-question lifecycle. Fix those in the design now; they are [BLOCKER]s that no evaluate-and-improve loop can compensate for.

---

## 2. Answers to the 12 open questions

**#1 Integration boundary + adapter slimming — [BLOCKER, decided].** Gate v2 must *absorb* the merged planner on the case path, not wrap it: Orient+Ground do the planner's normalize/route/interpret/expand with grounding, and running both creates two routers that can disagree — which reintroduces router drift *inside* your own stack. But keep `PreRetrievalPlannerService` untouched on the **knowledge path**: it is live, fast, and exactly right for that tier; delete it from the case path only after the eval says the gate wins. `GapDetectionService` and the quality pass stay below the retrieval seam — but beware retry stacking: PathwayAgent's ~3 reformulations × RetrievalService's quality pass multiplies latency; retries should hit lean retrieval, with the full pipeline reserved for the final attempt.

*Minimal Laravel state model, keyed by chat_id* (reuse the existing Redis DB5 case-state backend, not a new store):
- `case_id` — monotonic per chat; increments on new-case detection; old state archived under it.
- `patient_model` — the Orient schema object, plus per-field **provenance** `{turn_index, verbatim_source}` and `changed_this_turn[]`. Never rebuilt from scratch: Orient does a **delta-merge** over (prior model + new turn) and must emit `changed_fields`; the Critic's `state_completeness` compares against the prior model so silent field drops are catchable.
- `open_questions[]` — each `{question, status: pending|answered|declined, answer?}`.
- `assumptions[]`, `last_answer_digest` `{guideline_keys, cited_recommendations}`, `last_stage_trace`, `schema_version`, `updated_at`.
- TTL hours→days (not 300s); this substate eventually subsumes `PendingCaseStateService`.

*Thin-adapter contract*: request `{chat_id, message_id, user_message, raw_history[], attachments_text[], client_caps:{streaming}}` → response `{answer_markdown, questions[], evidence_status, stage_trace, state_echo:{case_id, patient_model_digest}}`. Two additions to the plan's version: `message_id` (idempotency — see Gap 1) and `state_echo` (state becomes recoverable from the transcript if Redis is lost).

*Cutover*: (1) shadow mode — Laravel runs gate v2 alongside the current pipeline, logs disagreements, nothing user-visible; (2) per-concern adapter valves `STATE_OWNER`, `GATE_OWNER`, `SYNTHESIS_OWNER` = `adapter|laravel`, flipped independently and reversibly; (3) migration order as plan §5 **but merge steps 4 and 5** — answer assembly cannot move to Laravel without the v1.5.x rules, because those rules *are* the answer-assembly logic; move them together, gated on the 15-case suite passing.

**#2 patient_model as single source of truth — [BLOCKER, decided].** The current scaffold does not enforce it: PathwayAgent and ProbeAgent receive "$case" (raw text). Rule: **raw text enters Orient only.** Every downstream stage — pathway retrieval queries, probe synthesis, critic — consumes the serialized patient_model + the current question verbatim, never raw history. If a downstream agent sees the raw transcript, the AAA stale-state bug survives (the model quotes turn-1 "infrarenal" straight past your merged state). Also: the Orient schema's single `lesion` string is too coarse for the benchmark (post-FU1 it must carry "juxtarenal + 5.8 cm + inadequate neck"); keep it general but add `other_findings[]` and the provenance/changed-fields machinery above — provenance is what makes "state completeness" checkable instead of vibes. Retrieval-query construction becomes a deterministic serialization of (patient_model slice + decision at issue), so the query *cannot* omit merged facts.

**#3 Concurrency under PHP-FPM — [IMPROVEMENT, answered].** `Concurrency::run()`'s default `process` driver spawns fresh PHP processes (`artisan invoke-serialized-closure`) — it works under FPM, each task pays a framework boot (tens–hundreds of ms; negligible vs LLM seconds), and closures must be serializable (construct agents inside the closure, don't capture DI'd services). But the real question is the *model server*: on Ollama's default single-stream serving, parallel requests serialize anyway — fan-out buys nothing and adds failure modes. Decision rule: vLLM with continuous batching → parallelize; Ollama/TGI single-stream → run pathways sequentially, cap candidates at 2 (Orient ranks them). Since candidates are usually 1–2, sequential is likely fine; don't build queued fan-out until measurement says so.

**#4 Local-model capability — [BLOCKER, the design's biggest bet].** The scaffold demands, simultaneously: six strict JSON schemas, multi-step tool calling *with judgment* (recognizing "off-target" snippets), calibrated scalar confidence, and competent criticism. Small local models reliably fail the last two and are flaky on the first two. Mitigations, in order: (a) **name the model now** — every latency, schema, and loop decision is model-dependent, and the eval must run per-model; (b) require **grammar-constrained decoding** at the server (vLLM guided JSON / llama.cpp grammars / Ollama `format=json`) so schema validity is enforced by the decoder, not by parse-retry loops; (c) **remove agentic tool-calling entirely**: the workflow (PHP) calls retrieval deterministically, passes snippets into a single structured prompt asking "relevant? if not, propose a better query" — this converts PathwayAgent's fragile tool loop into ≤3 simple structured calls with the same re-retrieval semantics; (d) **drop the scalar confidence from the decision rule**: derive ask/proceed from discrete signals (count of unresolved HIGH-impact unknowns, coverage verdicts) — a 0.70 threshold on an uncalibrated small-model float is fake precision. Keep confidence in the output for logging only. Run a 1-day capability spike (schema fidelity %, judgment quality on 10 canned critiques) before wiring anything.

**#5 Latency budget — [BLOCKER, arithmetic first].** Deep path first pass ≈ triage + orient + 2×(1–3 retrievals + 1 pathway call) + probe + critic ≈ 6–9 LLM calls + 2–6 retrievals. At a local ~40 tok/s with ~500-token structured outputs that's ~60–90 s *first pass*; one `orient_route` bounce nearly doubles it and blows the adapter's 120 s timeout. Set budgets now: fast path p95 ≤ 10 s; deep first pass p95 ≤ 60 s; **hard wall-clock deadline (~90 s) that overrides the iteration cap** and returns best-so-far with `stage_trace.budget_exhausted=true`. Merging Triage into Orient (see §4) removes one call from every request; deterministic retrieval (see #4) removes the tool-loop round-trips; per-stage bounce budgets (see #6) bound the tail.

**#6 Loop convergence — [BLOCKER, decided].** There is no convergence guarantee with a stochastic critic and you shouldn't seek one; engineer boundedness + monotonicity: (a) global cap 3, **per-stage bounce budget** `{orient_route: 1, ground: 1, probe: 2}` — if Orient needs re-running twice, Orient is broken, not under-iterated; (b) keep the **best-scoring candidate** across iterations and return best-so-far on cap-hit, never last-so-far (a revision can be worse); (c) **oscillation detector**: same invariant failing twice with substantially the same issue → stop, downgrade, surface in trace; (d) temperature 0 everywhere; (e) cap-hit-unapproved is not an error state: return the best candidate with `evidence_status` downgraded one notch and a deterministic "flagged for review" line — never block the user, never loop silently.

**#7 Cross-VM streaming — [IMPROVEMENT, decided: (b)+].** Skip SSE at wiring time; it's cross-VM plumbing (Caddy + Docker + OpenWebUI tool sandbox) for cosmetic gain. Do the **two-POST** variant: `POST /gate/start` returns `{run_id, mode}` within ~2 s (triage/orient result), adapter emits the one status line that matters ("deep analysis…" vs "answering directly"), then `POST /gate/result` long-polls; adapter shows timed canned progress while waiting. This delivers §3.5's real requirement — latency is *explained* — with zero streaming infrastructure. Revisit SSE only on user complaint.

**#8 Retrieval ceiling — [IMPROVEMENT].** Distinguish "bad query" from "corpus gap" with signals you already have: RAGFlow similarity scores of the *rejected* candidates. If all reformulations return near-threshold hits in plausibly-right sections → corpus is thin on this point → `not_covered` is credible. If every attempt returns low-similarity noise → the query/embedding failed → verdict becomes **`retrieval_uncertain`, a new third state**, rendered as "I could not locate ESVS guidance on this" — which is honest — rather than "ESVS is silent" — which is a clinical claim. Log every `not_covered`/`retrieval_uncertain` for offline clinician audit; genuine corpus gaps are enumerable and stable, so an audited gap list accumulates as *data* (not a case guard).

**#9 Interpretive-frame safety — [BLOCKER].** On a small local model, the always-populated frame is where hallucinated medicine will live; it's the top clinical risk in the design. Guardrails, all general: (a) prompt contract — the frame may extrapolate *from retrieved ESVS content and stated patient facts* (e.g., weigh two covered options for an uncovered combination) but may not introduce clinical entities (drugs, doses, numeric thresholds) absent from both snippets and question; Critic `frame_integrity` checks for novel entities; (b) the "non-ESVS" banner is **deterministic rendered text**, never model-generated; (c) a regex-level lint (not a case guard — it's universal) strips/flags dosing patterns (`mg`, `mg/kg`, units) in the frame; (d) grounded frame wins on any contradiction between frames (Critic-enforced); (e) sampled clinician audit of frames with a committed rate (see §5).

**#10 Eval harness — [decided: custom, thin].** Not `laravel/ai` eval (too young to bet a clinical launch gate on) and not Vizra eval (couples you to the framework being retired). JSON scenario files + one artisan runner + deterministic PHP checks where possible + an external strong-model judge (gpt-5-tier, *not* the ISI model judging itself) with rubric. Full design in §6.

**#11 Triage error cost — [decided: bias to case, then merge triage away].** The costs are asymmetric — a mis-fast-pathed patient gets a shallow clinical answer; a mis-deep-pathed knowledge question wastes seconds — so bias to `case` ("any patient-specific quantitative fact or identifiable patient → case") and accept 20–30% over-triggering. The `escalate` hatch must **carry its retrieval results forward** so escalation doesn't re-pay the fast path's work. But the better fix is structural: merge Triage into Orient (§4), which eliminates the triage/orient disagreement class and gives the knowledge path patient-context for free. Measure classifier accuracy in the eval regardless; a 1-call classifier should exceed 95%.

**#12 Who writes the final answer — [decided: (b), Laravel-verbatim].** The Critic loop's guarantees (grounding, frame integrity, no-drift) are void if an uncontrolled downstream model rewrites the answer — you'd be QA-ing a draft nobody reads. In a CDS tool, consistency beats fluency. Laravel emits final `answer_markdown` (ProbeAgent writes directly in the house STRICT_TEMPLATE sections, so no post-hoc templating layer exists to drift); the OpenWebUI model is instructed to render it verbatim, permitted at most one conversational opening sentence. You cannot *hard*-guarantee an OpenWebUI tool-calling model won't paraphrase — so measure **verbatim fidelity** (diff-based) in the eval, and treat persistent violation as the trigger to bypass the model entirely (direct tool-result rendering). Fluency worry is overblown: multi-turn conversational glue ("given the eGFR of 28 you mentioned…") derives from patient_model, which Laravel owns — Laravel *can* be fluent.

---

## 3. Gaps the plan missed (ranked)

1. **[BLOCKER] Retry/duplicate-turn idempotency.** Scenario: deep path takes 90 s; OpenWebUI tool times out at 120 s or the user resends; the same FU1 is merged into patient_model twice, or two concurrent runs race read-modify-write on Redis and one clobbers the other's merge — silent state corruption that no invariant catches (the doubled state is internally consistent). Fix: `message_id` idempotency key on the request; per-`chat_id` mutex (Redis `SETNX` with expiry); state writes carry a version counter and reject stale writers.

2. **[BLOCKER] New-case detection is unowned.** The fat adapter owned "gate reopens on a clearly different patient"; the plan moves state to Laravel and never reassigns this. Scenario: user closes the AAA discussion and pastes a fresh carotid case into the same chat; Orient dutifully delta-merges → chimera patient (74M AAA smoker *with* 70% symptomatic stenosis) driving retrieval and synthesis. Fix: Orient's schema gains required `same_case: bool` + `new_case_reason`, judged against the prior model digest; on `false`, archive state under the old `case_id` and start fresh. Must be an eval scenario (§6).

3. **[BLOCKER] Clarification-question lifecycle.** Scenario: gate asks 2 questions; user answers one, ignores the other, and adds a new wrinkle. Nothing in the plan tracks which questions are open/answered/declined, and nothing prevents re-asking — historically the most user-hated gate failure (your own F9). Fix: `open_questions[]` substate (§2/#1); Orient marks answered/declined on merge; **declined → recorded as an explicit assumption and never re-asked**; fold "never re-ask answered/declined" into the `question_value` invariant *and* enforce it deterministically in the tail (don't rely on the critic for it).

4. **[IMPROVEMENT] The Critic can't actually check grounding.** As scaffolded it sees the candidate (pathways' `guideline_basis` strings) but not the retrieved snippets — so a hallucinated basis sails through the `grounding` invariant, which then provides false assurance (worse than no check). Fix: pass capped snippet digests / tool transcripts into the Critic prompt; grounding = claims ⊆ snippets.

5. **[IMPROVEMENT] Fast path is stateless in both directions.** Scenario A: mid-case knowledge question ("remind me of the repair threshold?") answered with zero patient context. Scenario B: that knowledge turn never touches state, so the next case turn merges over a history hole. Fix: run triage/orient with the prior patient_model digest in context; knowledge turns still append to raw history; in an *active case*, default borderline turns to `case`.

6. **[IMPROVEMENT] Loop context growth vs small local context windows.** Issues + prior candidate + snippets re-injected each iteration grows the prompt superlinearly; an 8–16k local context truncates silently and the "revision" quietly loses the original case. Fix: carry only (issues + the changed stage's outputs + patient_model); digest pathways to a token budget; assert total prompt size per call, fail loudly.

7. **[IMPROVEMENT] No degradation ladder.** LLM backend down or crawling → the entire consult 503s, strictly worse than today's single-call pipeline. Fix: `GATE_V2_ENABLED` flag; on triage/orient timeout, fall through to the existing planner pipeline (which stays deployed through migration anyway — that's your kill switch for free).

8. **[QUESTION] PHI-at-rest and scrubbing scope.** patient_model keyed by chat_id in Redis is durable clinical state — does it need `PendingCaseStateService`-style allowlisting? Does PHIScrubber still gate calls when the model is on-prem? Policy call; couldn't verify from provided files.

9. **[IMPROVEMENT] `stage_trace` leaks internals.** Critic issue text can contain rejected draft content ("the draft wrongly recommended X for this patient") — if the adapter renders the trace, users see the unsafe draft. Fix: trace goes to logs and eval only; the adapter renders `answer_markdown` + `questions[]`, nothing else.

10. **[IMPROVEMENT] Over-asking is an eval blind spot.** All benchmark failures are wrongness/under-merge; the historical gate failure is redundant questions. An eager small model behind an "ask iff HIGH unknown" rule may interrogate everyone. Question precision needs a hard bar (§6), or the new gate will be *more* annoying than the whitelist it replaces.

---

## 4. Revised architecture (plan §3–4, with divergences marked)

```
turn (chat_id, message_id, user_message, raw_history, attachments)
  │  [idempotency check + per-chat lock; load prior state]          ← NEW (Gap 1)
  ▼
ORIENT (cheap, 1 call)                                              ← DIVERGED: absorbs Triage
  in:  prior patient_model + provenance, current turn, guideline_keys
  out: mode(knowledge|case), same_case, patient_model (delta-merged,
       changed_fields + provenance), differential, ranked candidates(≤2)
  ├─ knowledge ─► existing planner + RetrievalService (unchanged)   ← DIVERGED: reuse live
  │                └─ AnswerAgent: 1 call → two-frame answer          fast path, no new
  │                   (escalate ⇒ deep, carrying retrieval forward)   KnowledgeAnswerAgent tool-loop
  └─ case ─► GROUND (deterministic PHP loop, per candidate,          ← DIVERGED: no agentic
             sequential unless vLLM): retrieve → 1 structured          tool-calling (open q #4)
             "relevant? better query?" call → ≤3 attempts, lean
             retrieval on retries → coverage ∈ {covered, partial,
             not_covered, retrieval_uncertain}                       ← NEW 4th state (open q #8)
       ─► PROBE (capable): patient_model + snippets ONLY (no raw
             history) → unknowns, ≤2 questions, two-frame answer
             written directly in house-template sections             ← DIVERGED (open q #2, #12)
       ─► CRITIC (capable): candidate + snippet digests              ← DIVERGED (Gap 4)
             7 invariants + never-re-ask; bounce budgets
             {orient:1, ground:1, probe:2}, global cap 3,
             wall-clock deadline, best-so-far kept                   ← DIVERGED (open q #5, #6)
       ─► DETERMINISTIC TAIL: ask iff ≥1 HIGH unknown unresolved
             AND not previously declined (no confidence float);      ← DIVERGED (open q #4d)
             dose-lint + fixed non-ESVS banner on interpretive
             frame; state commit under lock (versioned); respond.
```

Transport: two POSTs (`/gate/start` → mode fast; `/gate/result` → answer), coarse adapter status between them. Contract and state model per §2/#1. Cutover: shadow → per-concern valves → plan §5 order with steps 4+5 merged.

What I did **not** change: the two-tier commitment, one general critic loop with earliest-stage bounce, always-answer two-frame output, re-retrieve-before-silent, thin adapter, all-intelligence-in-Laravel. The commitments survive review; the scaffolding around them mostly doesn't.

One commitment worth a footnote, not a fight: "no case-specific guards" is right for *reasoning*, but deterministic *lints* (dose regex, never-re-ask, banner text) and *audited data* (confirmed corpus-gap list) are not case guards — don't let the principle talk you out of cheap deterministic safety.

---

## 5. Evaluation design

**Dataset shape.** One JSON file per scenario:
`{id, tags[], turns: [{user, attachments?, expected: {mode, same_case, guideline_keys[], must_include_facts[], must_not_include[], expected_questions_semantic[], max_questions, evidence_status}}]}` — `must_include_facts` are cumulative (turn N inherits turns 1..N-1 unless superseded), which is exactly what the AAA benchmark tests. Target ≥40 scenarios: the existing 15-case suite ported (single-turn, non-regression), the AAA benchmark, ~10 multi-turn originals, ~10 knowledge/fast-path, plus the adversarial set below.

**Metrics and pass bars.**

| Metric | How scored | Bar |
|---|---|---|
| Routing accuracy (guidelines retrieved vs expected, per turn) | deterministic | ≥95%; AAA FU2 must route AAA — hard fail otherwise |
| State completeness (answer reflects cumulative `must_include_facts`) | judge + string checks | ≥90%; AAA FU1 must reflect juxtarenal + eGFR 28 — hard |
| Question precision / recall (semantic match to expected) | judge | precision ≥80%, HIGH-recall ≥70%; **re-ask of answered/declined = 0 (deterministic, hard)** |
| Frame integrity (grounded claims ⊆ snippets; frame never empty; no dose patterns) | judge + lint | 0 violations on grounded frame; lint = 0 |
| Evidence honesty (audited `not_covered`) | human audit | false "ESVS silent" ≤5%; `retrieval_uncertain` correctly used |
| Latency | measured | fast p95 ≤10 s; deep first-pass p95 ≤60 s; mean iterations ≤1.4 |
| Non-regression | 15-case suite | ≥ current production pass rate (12 PASS + 2 minor), no case drops a grade — launch gate |
| Verbatim fidelity (if narrator kept) | diff | ≥98% content-identical |

Judge = external strong model (gpt-5-tier) with rubric — never the ISI model judging itself; 10% of judged scores double-scored by the clinician user before the bars are trusted. Runner = one artisan command emitting a scorecard; every run stores `stage_trace`s so failures are replayable.

**Adversarial multi-turn scenarios (beyond AAA):**
1. **Case-switch chimera** — 3 carotid turns, then a brand-new AAA patient in the same chat. Pass: state resets (`same_case=false`), no carotid facts leak into the AAA answer. (Tests Gap 2.)
2. **Correction flip** — "70% symptomatic stenosis" → "correction: asymptomatic, 50–69%." Pass: recommendation flips appropriately, superseded values absent (`must_not_include: symptomatic, 70%`), provenance shows the correction. (Tests delta-merge overwrite, not averaging.)
3. **Declined question persistence** — gate asks smoking status; user: "irrelevant, just answer." Pass: proceeds with explicit assumption, never re-asks across 2 further turns. (Tests Gap 3.)
4. **Duplicate delivery** — identical FU sent twice (simulated adapter retry). Pass: state merged exactly once; deterministic check on provenance. (Tests Gap 1.)
5. **Knowledge interleave** — mid-AAA-case: "what diameter threshold does ESVS use in women?" Pass: fast-path answer ≤10 s, correct, and the *next* case turn retains the full patient_model. (Tests Gap 5.)
6. **Retrieval trap** — a case whose surface phrasing attracts the wrong guideline (e.g., aortic mural thrombus phrasing that pulls thoracic-aorta chunks). Pass: re-retrieval + `routing_validity` land the right guideline; `queries_tried` shows the recovery. (Tests §3.3 + the general drift catch.)

---

## 6. Open decisions for the human

1. **Which ISI model + serving stack** (vLLM with guided decoding vs Ollama)? This gates the capability spike, the concurrency decision, and every latency number — nothing should be wired before it's answered.
2. **Latency SLO sign-off** — is deep-path p95 ≈ 60 s clinically acceptable to your users? If not, the loop must shrink (cap 2, candidates 1) before wiring, not after complaints.
3. **Open question #12** — verbatim (my recommendation) vs one-sentence narrator. Someone who owns the user experience should ratify killing the "ESVS expert" voice.
4. **PHI-at-rest policy** for patient_model in Redis under ISI/HIPAA rules (Gap 8) — allowlist like `PendingCaseStateService`, or is on-prem storage exempt?
5. **Launch gate** — confirm the 15-case non-regression bar is binding (I recommend yes; a gate that fixes AAA but degrades carotid answers is a net loss).
6. **Clinician audit commitment** — who audits interpretive frames and `not_covered` verdicts, at what sampling rate, on what cadence? The safety story in #9 is only as real as this commitment.
