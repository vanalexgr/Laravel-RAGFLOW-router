# Agentic Clarification Gate v2 — Design Plan (v2, Fable review incorporated)

**Status:** design locked pending a model capability spike; not wired, not deployed.
**Branch:** `claude/prototyping-summary-d597c2`.
**History:** v1 = the plan reviewed by Fable (`docs/FABLE_REVIEW_PROMPT.md`). This v2 folds in
Fable's review (`docs/AGENTIC_GATE_V2_REVIEW.md`) + the human decisions below.

---

## 0. Decisions locked (2026-07-24)

| Decision | Choice | Consequence |
|---|---|---|
| Model + serving | **Ollama** (single-stream) | Pathways run **sequentially**, candidates capped at 2; use `format`=JSON schema; weak JSON adherence → **deterministic PHP orchestration + parse-repair + retry**, no agentic tool loops. Parallel fan-out deferred (only pays off on vLLM). |
| Final answer | **Laravel-verbatim** | Laravel emits `answer_markdown` in the house template; OpenWebUI model renders verbatim (≤1 opening sentence). Retire the "ESVS expert" synthesis voice. Measure verbatim fidelity in eval. |
| Latency SLO | fast p95 ≤10 s; deep first-pass p95 ≤60 s | Hard **~90 s wall-clock deadline** overrides the iteration cap → return best-so-far with `budget_exhausted=true`. |
| Launch gate | **Binding** 15-case non-regression | Gate v2 cannot ship if it fixes AAA but drops a grade on any currently-passing case. |
| Spike model | **`qwen2.5:14b-instruct`** | Target for the capability spike (stronger JSON/instruction-following). |
| Embedded clinical assertions | **Audited snippet library** | Extract the blueprint's hardcoded medicine into a clinician-signed-off static library ("audited data"), not model-generated. Gates S0 skeletons. |
| S0/S1 fill-call engine | **Cloud (gpt-5-mini), temporary** | Run answer-assembly on cloud first to isolate the port from the local-model bet; swap to local at S1. Accepted widening of cloud dependence during migration. |

**Decided (above):** clinical assertions → **audited snippet library** (clinician sign-off still to be
scheduled); S0/S1 fill-call → **cloud gpt-5-mini** temporarily.

**Still needs a human call:**
- **One tool vs two** — Q4 deletes `explain_app_capabilities`; confirm every capability/onboarding turn
  round-tripping to Laravel (one fast HTTP call) is acceptable UX. *(Recommendation: delete.)*
- **Decommission trigger (S7)** — define "N weeks stable" concretely (suggest 4 weeks, zero valve
  rollbacks, zero sev-1 answer-quality reports).
- **PHI-at-rest policy** for `patient_model` in Redis under ISI/HIPAA (Gap 8): allowlist like
  `PendingCaseStateService`, or is on-prem storage exempt? (now gates **S3**).
- **Clinician sign-off + audit**: who signs off the audited snippet library and audits interpretive
  frames + `not_covered` verdicts, at what rate/cadence (gates **S0** library + **S6**).

---

## 1. Problem & commitments

Retire the closed-whitelist gate (`PreRetrievalService` SOFT WARN + adapter `_assess_context_gaps`).
Beat the **AAA evolving-context benchmark** (`memory/benchmark_aaa_evolving_context.md`): state loss
across turns (FU1 1/10, stayed "infrarenal"), router drift (FU2 pulled the Thoracic guideline),
overconfident first answer.

**Commitments (survived review — do not relitigate):** one general evaluate-and-improve loop, no
case-specific *reasoning* guards; fast path for knowledge Qs, deep loop only for cases; retrieval is
fallible → re-retrieve before "ESVS silent"; always answer with a flagged non-ESVS interpretive
frame; **all intelligence in Laravel, thin adapter**; local model runtime.

*Footnote (Fable):* "no case guards" governs *reasoning*. Deterministic **lints** (dose regex,
never-re-ask, banner text) and **audited data** (confirmed corpus-gap list) are not case guards —
keep them as cheap deterministic safety.

---

## 2. Framework

First-party `laravel/ai` (v0.10.1, PHP ^8.3 — Hetzner 8.5). **`OllamaProvider`** with `format`=JSON
schema. Deterministic orchestration in PHP; agents are single structured calls, not tool-loops.

**No Laravel update needed (verified against `composer.lock`, 2026-07-24):** framework is already
**v12.63.0** ≥ the `illuminate/json-schema ^12.62` that `laravel/ai` requires — no version bump.
`laravel/prompts` (v0.3.21) and `laravel/serializable-closure` (v2.0.13) are already present and
compatible. Required work is an **add, not an upgrade**: `composer require laravel/ai` (pulls the new
`illuminate/json-schema`), publish `config/ai.php`, `config:cache`. Bump `composer.json` `php ^8.2`→
`^8.3` (cosmetic; runtime is 8.5). Run `composer require laravel/ai --dry-run` on Hetzner first to
confirm coexistence with the installed `prism-php/prism` v0.92 + `vizra/vizra-adk` v0.0.42. None of
this is needed for the capability spike (standalone Python); only from **S0**.

---

## 3. Architecture (revised)

Divergences from v1 marked. Everything in `app/Ai/Gate/`.

```
turn (chat_id, message_id, user_message, raw_history, attachments_text)
  │  [idempotency: message_id seen? → return cached] + [per-chat_id Redis lock] + [load prior state]
  ▼
ORIENT  (cheap, 1 call)                                   ← absorbs Triage (was separate)
  in : prior patient_model + provenance, current turn verbatim, guideline_keys
  out: mode(knowledge|case), same_case + new_case_reason,   ← new-case detection (Gap 2)
       patient_model DELTA-MERGED (changed_fields[] + per-field provenance),  ← single source of truth
       differential, ranked candidate guidelines (≤2), open_questions updated (answered/declined)
  │
  ├─ knowledge ─► EXISTING planner + RetrievalService (unchanged, live) → AnswerAgent (1 call,
  │                two-frame answer). Runs with prior patient_model digest in context (Gap 5).
  │                escalate ⇒ deep path, CARRYING retrieval results forward (Gap 5/#11).
  │
  └─ case ─► GROUND  (deterministic PHP, per candidate, SEQUENTIAL):        ← no agentic tool loop (#4)
        for each candidate (≤2):
          retrieve (lean on retries, full pipeline on final attempt) →
          1 structured call "relevant? if not, propose better query" → ≤3 attempts →
          coverage ∈ {covered, partial, not_covered, retrieval_uncertain}   ← 4th state (#8)
        (query = deterministic serialization of patient_model slice + decision at issue — cannot omit merged facts)
     ─► PROBE  (capable): patient_model + snippets ONLY (never raw history) →   ← enforces #2
          unknowns, ≤2 questions, two-frame answer written directly in house STRICT_TEMPLATE sections
     ─► CRITIC (capable): candidate + capped snippet digests →                  ← now sees snippets (Gap 4)
          7 invariants + never-re-ask; per-stage bounce budget {orient_route:1, ground:1, probe:2},
          global cap 3, wall-clock deadline, KEEP BEST-SCORING candidate, oscillation detector, temp 0
     ─► TAIL (deterministic): ask iff ≥1 unresolved HIGH-impact unknown AND not previously declined
          (NO confidence float — discrete signals only, #4d);                   ← decision rule changed
          dose-lint + fixed non-ESVS banner on interpretive frame;
          commit state under lock (versioned); build answer_markdown; respond.

Transport: TWO POSTs — /gate/start → {run_id, mode} (~2s, triage/orient) so adapter shows the ONE
status line that matters ("deep analysis…" vs "answering directly"); /gate/result long-polls with
timed canned progress. No SSE (#7).
```

**Key structural fixes from the review:**
- **Raw text enters Orient only.** Every downstream stage consumes the serialized `patient_model` +
  current question verbatim — never the raw transcript. This is what actually kills the AAA stale-state
  bug (a downstream agent seeing raw history would quote turn-1 "infrarenal" past the merged state).
- **Orient delta-merges** (prior model + new turn), emits `changed_fields`; `state_completeness` checks
  the new model against the prior so silent field drops are catchable. `lesion` split with
  `other_findings[]` so "juxtarenal + 5.8 cm + inadequate neck" survives.
- **Discrete decision, no scalar confidence** — a 0.70 threshold on an uncalibrated Ollama float is
  fake precision. Keep `confidence` in output for logging only.

---

## 4. The single Laravel state brain (chat_id-keyed, reuse Redis DB5)

```
state[chat_id] = {
  case_id,                       # monotonic; increments on new-case detection; old state archived
  patient_model: { …Orient schema…,
     _provenance: { field → {turn_index, verbatim_source} },
     changed_this_turn: [] },
  open_questions: [ {question, status: pending|answered|declined, answer?} ],
  assumptions: [],               # declined questions become explicit assumptions, never re-asked
  last_answer_digest: {guideline_keys, cited_recommendations},
  last_stage_trace, schema_version, version, updated_at
}
```
TTL hours→days (not 300 s); this substate eventually subsumes `PendingCaseStateService`. State is
**recoverable from the transcript** via `state_echo` in the response if Redis is lost.

---

## 5. Three BLOCKER state fixes + safety net

1. **Idempotency / duplicate turns (Gap 1).** `message_id` idempotency key; per-`chat_id` Redis mutex
   (`SETNX`+expiry); versioned writes reject stale writers. Prevents double-merge + read-modify-write
   races (silent, invariant-invisible corruption).
2. **New-case detection (Gap 2).** Orient emits required `same_case` + `new_case_reason` judged vs the
   prior model digest; on `false`, archive under old `case_id`, start fresh. Prevents the chimera
   patient (AAA smoker *with* symptomatic carotid).
3. **Clarification-question lifecycle (Gap 3, your F9).** `open_questions[]` tracks pending/answered/
   declined; **declined → explicit assumption, never re-asked**; "never re-ask" is enforced
   **deterministically in the tail**, not left to the critic.
4. **Degradation ladder + kill switch (Gap 7).** `GATE_V2_ENABLED` flag; on triage/orient timeout or
   LLM-backend failure, fall through to the existing planner pipeline (kept deployed through migration
   anyway). Never 503 the whole consult.

---

## 6. General evaluator (7 invariants) + honesty

`state_completeness · routing_validity · retrieval_sufficiency · grounding · frame_integrity ·
question_value · calibration`. Earliest-stage bounce. Critic now receives snippet digests so
`grounding` is real (claims ⊆ snippets). Routing drift caught generically as `routing_validity`.

**Retrieval honesty (#8):** distinguish "bad query" from "corpus gap" via RAGFlow similarity of the
*rejected* candidates. Near-threshold hits in the right sections → `not_covered` credible. All-noise →
**`retrieval_uncertain`** ("I could not locate ESVS guidance on this" — honest, not a clinical claim).
Log every `not_covered`/`retrieval_uncertain` for clinician audit; confirmed gaps accumulate as data.

---

## 7. Two-frame answer + interpretive-frame safety (#9 — top clinical risk on a local model)

`guideline_grounded_answer` (ESVS-only) + `interpretive_frame` (non-ESVS, always populated) +
`evidence_status`. Guardrails, all general: (a) frame may extrapolate *from retrieved ESVS content +
stated patient facts* but may **not introduce clinical entities (drugs, doses, numeric thresholds)**
absent from snippets and question — Critic `frame_integrity` checks for novel entities; (b) the
"non-ESVS" banner is **deterministic rendered text**, never model-generated; (c) regex **dose-lint**
flags `mg`, `mg/kg`, units in the frame; (d) grounded frame wins any contradiction; (e) sampled
clinician audit at a committed rate.

**`evidence_status` is a structured object, not a scalar** (Fable Q2 — the adapter's gap taxonomy is
load-bearing clinical honesty, not styling):
```
evidence_status = {
  coverage: covered | partial_principles | interaction_gap | not_covered | retrieval_uncertain,
  core_question, covered_components[], gap_summary
}
```
`interaction_gap` (components covered, their interaction not) must cite the component recs while stating
"ESVS provides no recommendation on [the interaction]" — collapsing it to `not_covered` is exactly the
false-"ESVS silent" failure §9 caps at ≤5%. `partial_principles` mandates "general perioperative
principles apply — do NOT write 'no ESVS guidance'". Coverage is computed **once, in Laravel**, next to
the assessment that produces it; the adapter's re-derivation of these flags drops.

**Hardcoded clinical assertions in the blueprint are quarantined** (Fable BLOCKER): the current
blueprint embeds specific medicine (apixaban stop 48h / restart 24–72h; "no bridging for AF on DOAC";
"avoid triple therapy"; AAA ~1%/month rupture exemplar). Ported naively these **violate the
frame-integrity rule above**. They become an explicitly **audited static snippet library** (the
"audited data, not case guards" category) with clinician sign-off, or are dropped — **human decision,
gates S0 skeleton finalization** (§0).

---

## 8. Migration sequence (revised per Fable — answer-path decoupled from gate)

**Principle:** every step is a **whole-path** change behind a per-request valve — no step ever ships an
answer assembled half in Python, half in PHP. Valves: `STATE_OWNER | GATE_OWNER | SYNTHESIS_OWNER =
adapter|laravel`, **plus a new `SYNTHESIS_MODEL = cloud|local`** that separates "did the port break it?"
from "is the local model too weak?" — two failure modes the old §8 conflated.

The critical insight: **the answer-path migration and the gate migration are separable**, and shipping
the answer path first (against the *existing* live pipeline + a *cloud* model) isolates each risk.

0. **Capability spike (Hetzner, Ollama) — GO/NO-GO.** JSON-schema fidelity %, judgment on 10 canned
   cases, per-stage latency. Revisit model/decoding if weak.
1. **S0 — Answer path first, against the live pipeline** (D+E+G + structured `evidence_status`,
   together). Laravel `AnswerAssembly` = **deterministic PHP skeletons + one schema-constrained fill
   call** (Q1(b)); consumes the **existing** planner/retrieval/gap_assessment; emits `answer_markdown`;
   `SYNTHESIS_OWNER=laravel` renders verbatim, old blueprint intact at `=adapter`. Fill call on the
   **cloud model** (`SYNTHESIS_MODEL=cloud`, gpt-5-mini already wired) so S0 measures the *port* alone.
   **Checkpoint:** 15-case binding + verbatim ≥98% + gap-taxonomy scenarios.
2. **S1 — Local-model swap on the answer path** (`SYNTHESIS_MODEL=local`). Same skeletons, swap engine.
   **Checkpoint:** 15-case — any grade drop is now *provably* the model, not the migration. Earliest
   cheapest read on the Ollama bet under production-shaped load.
3. **S2 — Guard + capabilities** (B+C+K): deterministic pre-Orient guard in Laravel; adapter guardrail
   regexes off. **Checkpoint:** 15-case + guardrail/injection cases + gate-reply suppression cases.
4. **S3 — State brain** (I+H): chat_id-keyed state, idempotency, versioning, new-case detection;
   `STATE_OWNER=laravel` while the adapter still drives turn flow (extends the Stage-D pattern).
   **Checkpoint:** adversarial state set (chimera, duplicate-delivery, declined-question) + 15-case.
5. **S4 — Orient shadow** (A+F+P): Orient classifies + routes on live traffic, logged only; run the
   **routing proof harness** (log replay → shadow disagreements judged → hard bars). **Exit = the
   routing bars, no user-visible change.** Unify the Laravel-side prunes (antithrombotic prune,
   disabling-stroke boost) **into** Orient here (concern P) — else two routers disagree again.
6. **S5 — Tool-contract flip** (F+J): one-tool docstring, drop `guideline_1/2/3` + delete
   `explain_app_capabilities`; push via `push_adapter.py` with a staged rollback copy. **Least
   reversible** (changes what the OWUI model sees) → comes *after* routing is proven.
   **Checkpoint:** 15-case + AAA FU2 hard bar + live routing accuracy.
7. **S6 — Deep loop** (the §3 case path: Ground/Probe/Critic/tail) behind `GATE_OWNER=laravel`. Lands on
   a proven answer path + state + routing — the loop is the only new variable. **Checkpoint:** full §9
   suite incl. AAA end-to-end + latency SLOs.
8. **S7 — Decommission** adapter blueprint/state/guardrail paths after N weeks all-valves-laravel stable
   (define N — §0); adapter shrinks to transport + concern J.

`gate:probe2` (deterministic orchestration of §3, two-POST `/gate/start` + `/gate/result`, `GateProgress`,
`stage_trace` logs-only) is the dev harness used to build/validate S5–S6.

---

## 9. Evaluation (binding launch gate)

**Custom + thin**: JSON scenario files, one artisan runner, deterministic PHP checks where possible +
an **external strong-model judge (gpt-5-tier, never the ISI model judging itself)**. ≥40 scenarios:
ported 15-case suite (non-regression), AAA benchmark, ~10 multi-turn originals, ~10 knowledge, + the
adversarial set. Scenario shape carries **cumulative** `must_include_facts` (turn N inherits 1..N-1).

| Metric | Bar |
|---|---|
| Routing accuracy | ≥95%; AAA FU2 must route AAA — hard fail |
| State completeness | ≥90%; AAA FU1 must reflect juxtarenal + eGFR 28 — hard |
| Question precision / HIGH-recall | ≥80% / ≥70%; **re-ask of answered/declined = 0 (deterministic, hard)** |
| Frame integrity (grounded ⊆ snippets; never empty; dose-lint) | 0 violations; lint = 0 |
| Evidence honesty | false "ESVS silent" ≤5%; `retrieval_uncertain` used correctly |
| Latency | fast p95 ≤10 s; deep first-pass p95 ≤60 s; mean iterations ≤1.4 |
| **Non-regression (15-case)** | **≥ 12 PASS + 2 minor, no grade drop — LAUNCH GATE** |
| Verbatim fidelity | ≥98% content-identical |

**Adversarial scenarios:** case-switch chimera (Gap 2), correction flip (overwrite not average),
declined-question persistence (Gap 3), duplicate delivery (Gap 1), knowledge interleave (Gap 5),
retrieval trap (drift catch). 10% of judged scores double-scored by the clinician user before bars are
trusted.

---

## 10. Agent-scaffold changes required (delta from current `app/Ai/Gate/`)

The scaffolded agents encode the *right roles* but need rework before wiring:
- **Merge `TriageAgent` → `OrientAgent`**; `mode` expands to the full live taxonomy + guardrail
  classes (§11); add `same_case`, `new_case_reason`, delta-merge (`changed_fields` + provenance),
  `open_questions` update, `other_findings[]`. Reuse the adapter's regex predicates as pre-signals.
- **Add a deterministic pre-Orient guard** for prompt_injection / model_meta / capabilities /
  out_of_scope (canned responses; §11-B/C).
- **Migrate the guideline SELECTION RULES + 14-guideline REFERENCE** from the tool docstring into
  Orient routing; change the tool contract to drop `guideline_1/2/3` (§11-F).
- **Answer assembly is mode-aware + asset-bearing** (§11-D/E/G): reproduce `_response_mode` variants,
  the two-layer blueprint writing rules + gap taxonomy, and `assets[]` in the response.
- **`PathwayAgent`/`KnowledgeAnswerAgent`: remove `HasTools` agentic loops** → PHP-orchestrated
  retrieve→"relevant?/better query?" structured calls; add `retrieval_uncertain`.
- **`ProbeAgent`: consume patient_model + snippets only (no raw case text)**; write directly into house
  STRICT_TEMPLATE sections; keep `confidence` for logging, not decisions.
- **`CriticAgent`: receive snippet digests**; add never-re-ask; emit best-score tracking inputs.
- **Deterministic tail**: discrete ask/proceed rule; dose-lint; fixed banner; versioned state commit.
- **`GateProgress`**: keep; drive the two-POST coarse status.

---

## 11. Adapter behavior inventory — full migration coverage (gap analysis 2026-07-24)

Audit of the live `openwebui_tools/vascular_mcp_adapter.py` (3004 lines, v1.5.x). The §3.6 table
under-counted; the adapter holds **11 intelligence concerns**, several missing from earlier drafts.
Every one must land somewhere in v2 or be a conscious drop.

| # | Adapter behavior (methods) | v2 owner | Status |
|---|---|---|---|
| A | **Turn taxonomy** — `classify_turn` → 7 classes: NEW_CASE, EXPLICIT_NEW_CASE, GATE_REPLY, FOLLOWUP_VAGUE, FOLLOWUP_SUBSTANTIVE, KNOWLEDGE, GUARDRAIL | Orient | **was under-specified** — Orient emitted only knowledge\|case+same_case; must emit the full taxonomy |
| B | **Guardrails** — `_guardrail_type`: prompt_injection, model_meta, capabilities_onboarding, out_of_scope (+ canned replies) | Orient mode + deterministic responses; injection stays a deterministic security check | **MISSING from design** |
| C | **Capabilities/onboarding** — `explain_app_capabilities`, `APP_GUIDANCE_HEADER`, "yes/ok after nonclinical" | fast deterministic path (Laravel or thin adapter static) | **MISSING** |
| D | **Response-mode template variants** — `_response_mode` → management\|knowledge\|surveillance\|diagnostic\|case; `_requires_clinical_decision_summary` (adds Clinical Decision Summary + Perioperative Risk) | Laravel answer assembly | **MISSING** (plan assumed one template) |
| E | **Two-layer blueprint** — `_build_two_layer_blueprint`/`_build_answer_blueprint` (~300 lines): DECISIVENESS RULE, ARTIFACT RULE, FORBIDDEN hedging phrases, section order (🩺 Clinical Synthesis…), gap taxonomy `total_gap`/`question_gap`/`core_question_covered∈{none,partial}`, interaction-gap vs sequencing vs perioperative-drug sub-structures | Laravel ProbeAgent's writing contract | **understated** — this *is* the bulk of the v1.5.x intelligence; migration is large |
| F | **Guideline-selection rules** — SELECTION RULES + 14-guideline GUIDELINE REFERENCE currently in the *tool docstring* (read by the OWUI model to pick guideline_1/2/3) | Orient routing prompt/knowledge; **tool contract changes** — model stops selecting guidelines, just passes the question | **MISSING** (routing table must move) |
| G | **Assets** — `_format_assets_markdown`, `_format_rec_popup`, `has_assets` (figures/tables/rec popups) | Laravel returns asset refs; render in answer | **MISSING** from two-frame answer |
| H | **Confirmation-mode + change-detection** — `_call_confirmation_phase`, `_call_pre_retrieval`, `change_decision`, store/get `pending_pre_result`: after a clarify, detect whether the reply changed retrieval enough vs reuse the stored pre_result | Laravel gate + state (extends Gap 3/5) | **partially** covered; the change-detection step is a specific mechanic to keep |
| I | **Three state objects** — session (active gate) / case_context (completed case) / pending_pre_result, over in-memory TTLStore **and** STATE_BACKEND (Redis DB5) | single Laravel brain (§4) must absorb all three roles | **partially** — plan named only patient_model |
| J | **Anti-short-circuit / regeneration** — docstring rules: never answer from prior tool result, always re-call on follow-ups, regeneration rule | structurally enforced (every turn → Laravel) + the thin tool must always invoke, never answer from history | covered, make explicit |
| K | **Security** — prompt-injection detection + history sanitization | deterministic (Laravel middleware already sanitizes; keep injection check) | keep, don't drop |

### Design additions required (folding the gaps in)

- **Orient's `mode` enum expands** to the live taxonomy + guardrail classes:
  `knowledge | case_new | case_followup_substantive | case_followup_vague | gate_reply |
  capabilities | out_of_scope | model_meta | prompt_injection`. The battle-tested regex predicates
  (`_EXPLICIT_NEW_CASE_RE`, `_is_answer_only_turn`, `_is_vague_management_followup`,
  `_should_treat_as_new_query`, `_is_raw_guideline_knowledge_query`) are **reused as deterministic
  pre-signals + eval oracle**, not discarded (they are exactly the "deterministic lints, not case
  guards" Fable endorsed).
- **A deterministic pre-Orient guard** handles `prompt_injection` / `model_meta` / `capabilities` /
  `out_of_scope` with canned responses before any LLM reasoning (cheap, safe, preserves current UX).
- **Answer assembly is mode-aware**: ProbeAgent must reproduce the `_response_mode` variants and the
  two-layer blueprint's writing rules + gap taxonomy. The plan's `evidence_status` is **refined** to
  carry the blueprint's richer coverage model (interaction-gap ≠ total-gap ≠ partial).
- **Assets are first-class in the answer contract**: response gains `assets[]`; `answer_markdown`
  embeds figure/table/rec-popup references (reuse `GuidelineAssetService`).
- **Tool contract change is explicit**: the thin tool no longer takes `guideline_1/2/3` — it sends
  `{question, history}`; Laravel Orient routes. The SELECTION RULES/GUIDELINE REFERENCE migrate into
  Orient. The tool must still *always* invoke Laravel (never answer from history) — that anti-short-
  circuit rule is the one piece of docstring intelligence the thin adapter keeps.
- **Confirmation change-detection** is preserved as a state-aware step: on a `gate_reply`, Orient
  delta-merges and decides re-retrieve vs reuse `last_answer_digest`/pre_result (this subsumes
  `_call_confirmation_phase` + `pending_pre_result`).

### Net effect on effort
The biggest under-estimate is **E + D + G** (mode-aware, gap-aware, asset-bearing answer assembly) —
this is where the v1.5.x rules actually live. **F** (routing table) and **B/C** (guardrails/capabilities)
are net-new design surface.

### Fable migration-review refinements (`docs/AGENTIC_GATE_V2_MIGRATION_REVIEW.md`, 2026-07-24)

Scoped review against the live **v1.5.59** adapter. §11's inventory + ownership judged correct; the
framing/sequencing were fixed:

- **E is a REDESIGN, not a port.** The blueprint is an instruction sheet aimed at a strong renderer
  (gpt-5-chat) that won't exist under Laravel-verbatim; its imperative delivery mechanism is dead code.
  Split it: **structure → deterministic PHP skeletons; canned text → rendered strings; content rules →
  per-section prompt fragments + a hedging-phrase lint** joining the dose-lint. **Answer = PHP skeleton
  + ONE schema-constrained fill call** (per-section calls blow the 60 s SLO on single-stream Ollama;
  `maxItems` finally makes COMPACT enforceable). Verbatim-lifting the sheet into Ollama is guaranteed
  to fail (Q1).
- **Gap taxonomy is load-bearing** → structured `evidence_status` (see §7). `_response_mode` /
  `_requires_clinical_decision_summary` / gap-dispatch regexes / canned strings → **port verbatim as
  PHP/data**; `_format_gate_for_model` + STRICT_TEMPLATE imperative wrappers → **drop**.
- **Guard is deterministic but NOT stateless** (Q3): guardrail evaluation is *suppressed* when a pending
  gate / vague-case-followup exists (`classify_turn` line 567) — so it runs **pre-Orient but
  post-state-load**. Port `classify_turn`'s frozen priority order verbatim as the guard spec. Ratchet
  rule: an LLM may *tighten* to `out_of_scope`, never *loosen* a deterministic block. Injection-flagged
  text never reaches any LLM. Thin tool keeps only concern **J** (always-call).
- **Routing move is not "strictly better"** (Q4): trades a strong engine/weak structure (gpt-5-chat)
  for a weak engine/strong structure (Ollama + `patient_model` + `routing_validity`). Prove before
  flipping the contract: **log-replay corpus (broken down by turn class) → shadow disagreement judging
  (Orient wins/ties ≥90%, loses 0 on 15-case+AAA) → §9 hard bars.** New contract: **one tool**
  `consult_vascular_guidelines(question)`, drop `guideline_1/2/3`, **delete `explain_app_capabilities`**.
- **New concerns §11 missed (added):** **L** attachments ingestion → Orient input (provenance-tagged);
  **M** progress-string UX → keep strings, drop machinery; **N** FOLLOWUP_VAGUE rewrite → drop
  (subsumed by deterministic query serialization — but add a dedicated eval scenario before deleting);
  **O** history hygiene/budgets (`_strip_backend_history_noise`, last-20) → port as Orient input hygiene
  (doubles as Gap-6 budget); **[BLOCKER] P** the Laravel-side routing prunes (antithrombotic prune,
  disabling-stroke boost) **must be unified into Orient's routing** (in S4), or the two-disagreeing-
  routers problem returns inside one VM.
- **Sequencing rewritten** → §8 (answer-path first, decoupled from the gate; `SYNTHESIS_MODEL` valve).

## 12. What exists / what's next
- **Exists:** scaffolded agents + tool + progress contract (`app/Ai/Gate/`), v1 baseline
  (`AgenticGateService`, `gate:probe`), this plan, Fable's review.
- **Next:** (1) capability spike on Ollama; (2) if GO, rework agents per §10 + build `gate:probe2`;
  (3) eval harness + AAA/adversarial scenarios; (4) shadow → valves → cutover. Resolve the two human
  policy calls (PHI-at-rest, audit commitment) before launch.
