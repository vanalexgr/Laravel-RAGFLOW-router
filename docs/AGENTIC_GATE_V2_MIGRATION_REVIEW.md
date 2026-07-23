# Agentic Gate v2 — Adapter-Migration Review (scoped follow-up)

Reviewed: `docs/AGENTIC_GATE_V2_PLAN.md` §11 (+ §3/4/7/8/10), live `openwebui_tools/vascular_mcp_adapter.py`
**v1.5.59 @ 3004 lines** (main checkout — NOTE: this worktree carries a stale v1.5.53/2563-line copy; all
findings below are against v1.5.59), `docs/AGENTIC_GATE_V2_REVIEW.md`, `memory/benchmark_aaa_evolving_context.md`.

**Unverifiable assumptions (flagged):** (a) production DB copy of the adapter matches repo v1.5.59 —
memory says v1.5.59 deployed, not re-verified live; (b) Laravel's `gap_assessment` payload
(`has_guideline_gap`, `question_gap`, `core_question_covered`, facets) is produced by
CoverageAssessment/GapDetection as memory describes — I read the adapter's consumption, not the producer;
(c) production routing logs are rich enough to build the §Q4 routing-replay corpus (guideline params are
logged per request — assumed from CLAUDE.md, not sampled).

---

## 1. Verdict

**§11's inventory is substantially complete and the ownership assignments are correct.** The 11 concerns
are real, none is misassigned, and the audit correctly identified E+D+G as the under-estimated bulk. What
§11 gets *wrong* is one framing and one sequencing decision:

- **[BLOCKER] E is not a port — it cannot be one.** The two-layer blueprint is not "answer-assembly
  logic"; it is a ~300-line *prompt-instruction sheet addressed to a strong downstream renderer*
  (gpt-5-chat) that no longer exists in the target architecture. Under Laravel-verbatim, the delivery
  mechanism (imperative instructions like "copy the supplied image lines verbatim", "Begin with exactly:
  '⚠️ …'", `_format_gate_for_model`'s "MANDATORY BEHAVIOR: copy exactly") is dead code the moment
  Laravel emits final markdown. Only the blueprint's *content policy* survives — section order, mode
  variants, gap dispatch, decisiveness/forbidden-phrase rules, canned sentences — and it survives by
  being **split**: structure → deterministic PHP; canned text → rendered strings; content rules →
  per-section prompt fragments + lints. Lifting the sheet verbatim into an Ollama prompt is the one
  option guaranteed to fail (see Q1).
- **[BLOCKER] §8's sequencing bundles the answer-path migration with the gate migration.** They are
  separable, and separating them is the single biggest de-risking available: the D/E/G port can ship
  *first*, behind a `SYNTHESIS_OWNER` valve, against the **existing live pipeline** (planner + current
  retrieval + existing `gap_assessment`), validated on the 15-case suite while the trusted gate and the
  trusted routing are still live. §11's "move E+D+G together" is right; "together *with the gate
  cutover*" is not (see §4).
- **[BLOCKER — new finding, missing from §11]** The blueprint embeds **hardcoded clinical assertions**
  (apixaban stop 48h pre-op / restart 24–72h; "no bridging for AF on DOAC"; "AVOID triple therapy";
  AAA ~1%/month rupture-risk exemplar). Ported naively these *violate §7's own frame-integrity rule*
  (no clinical entities absent from snippets + question). They must become an explicitly **audited
  static snippet library** (Fable's "audited data, not case guards" category) with clinician sign-off,
  or be dropped — this is a human decision, not an engineering one (§5, decision 1).

§11's genuine misses beyond that are second-order (L–Q in the table): attachments ingestion, the
FOLLOWUP_VAGUE rewrite (subsumed but must be named so the eval covers it), transcript-recovery
mechanics (droppable), history-hygiene budgets, progress-string UX, and — the only structural one —
**Laravel-side routing prunes (antithrombotic prune, disabling-stroke boost) must be unified into
Orient's routing or you rebuild the two-disagreeing-routers problem §1 of the adopted review killed**.

---

## 2. The five questions — one decision each

### Q1 [E]+[D] — Answer-assembly brain: port verbatim vs PHP scaffolding vs hybrid

**Ranking: (b) deterministic PHP scaffolding ≻ (c) hybrid ≻ (a) verbatim prompt-lift. Pick (b), with
(c)'s one concession: a single model fill-call, not per-section calls.**

Decision: **PHP owns everything the blueprint currently *begs* the model to do; the model only writes
prose inside a schema.**

- **Deterministic PHP (port as code):** mode selection (`_response_mode` +
  `_requires_clinical_decision_summary` → straight predicate ports — cheap, battle-tested, inputs
  [intent_profile/query_type] already come from Laravel); gap dispatch (`total_gap` derivation,
  `is_periop_drug`/`is_sequencing` regex skeleton-selectors); section skeletons per
  mode × coverage (headings, order, mandatory "🎯 In practice" block, Evidence Used assembled from
  citation metadata, assets block); **all canned strings** (banners, practice-openers, closing
  disclaimers, "No applicable ESVS recommendations…") emitted as rendered text — strictly stronger
  than today, where a model must faithfully copy them.
- **One schema-constrained fill call:** Ollama `format`=JSON schema (already locked, §0) with one field
  per skeleton slot, `maxItems` enforcing what is today a pleading "maximum 5 bullets" — COMPACT mode
  becomes *enforceable* for the first time. Per-section prompt fragments carry the content rules
  (DECISIVENESS, ARTIFACT, scope rule, forbidden phrases).
- **Lints backstop the rules the model will still break:** hedging-phrase regex ("may be considered",
  "generally warrants", "it is reasonable to") joins the dose-lint in the deterministic tail — same
  endorsed lint class; violations bounce to Probe within the existing budget or get flagged, never
  silently shipped.

**What breaks under each option:**
- (a) verbatim lift: a 7–14B model handed a 2,000-token rule sheet violates section order, emits
  forbidden hedges, skips mandatory blocks — and every fix is prompt surgery + full regression.
  Verbatim fidelity of the *rendering* becomes moot because the *structure* is already wrong. Dead on
  arrival with the locked model choice.
- (b) pure scaffolding: risk is fragmentation — per-section calls on single-stream Ollama blow the 60s
  SLO, and cross-section rules ("do not repeat the same fact", coherent narrative) need global view.
  Hence the single fill-call concession. Residual real loss: cross-section dedup gets weaker →
  covered by a Critic check, acceptable.
- (c) full hybrid (some sections prompt-ruled, some scaffolded): two authoring models for one answer;
  drift between them is exactly the template-inconsistency class v1.5.x spent ten releases fixing.

**Tags:** `_response_mode` / `_requires_clinical_decision_summary` / gap-dispatch regexes /
canned strings → **port verbatim (as PHP/data)**. Blueprint instruction sheets
(`_build_two_layer_blueprint`, `_build_answer_blueprint`) → **redesign** (skeleton + schema-fill).
`_format_gate_for_model`, STRICT_TEMPLATE imperative wrappers → **drop** (subsumed by verbatim
contract). Embedded clinical content → **quarantine pending decision** (§5.1).

### Q2 [E] — Gap taxonomy: collapse into `evidence_status`?

**Decision: load-bearing — do NOT collapse. Promote `evidence_status` from scalar to a structured
coverage object; drop only the adapter-side re-derivation.**

The taxonomy encodes three *clinically different honesty claims* that a scalar destroys:
- `interaction_gap` (components covered, interaction not) → "ESVS provides no recommendation on [the
  interaction]" **while still citing component recs** — collapsing this to `not_covered` produces the
  false-"ESVS silent" failure §9 caps at ≤5%.
- `partial_principles` (`core_question_covered=partial`) → mandated wording "general perioperative
  principles apply — do NOT write 'no ESVS guidance'". This distinction exists because earlier versions
  *did* claim no-guidance when principles existed; it is a fixed clinical-honesty bug, not styling.
- The periop-drug vs sequencing sub-structure dispatch selects genuinely different decision skeletons
  (drug timing lines vs treatment-priority-first). Port the dispatch regexes verbatim as deterministic
  skeleton selectors.

Proposed shape: `evidence_status = {coverage: covered | partial_principles | interaction_gap |
not_covered | retrieval_uncertain, core_question, covered_components[], gap_summary}` — the plan's
`retrieval_uncertain` (§6) slots in as the fifth value rather than living in a parallel field. Mapping
from today's flags is mechanical (`total_gap`→`not_covered`; `question_gap`+covered
components→`interaction_gap`; partial→`partial_principles`). **What drops:** the adapter's
re-derivation layer (line ~1541's `total_gap =` recomputation) — v2 computes coverage once, in
Laravel, next to the assessment that produces it; the adapter today re-deriving Laravel's own flags is
exactly the smeared-intelligence §11 exists to end. The COMPACT/STANDARD/FULL self-declared "Mode:"
line also drops as a user-visible mechanism (mode is now a PHP decision; keep it as a logged field for
the gap-detection-v2 comparison work).

### Q3 [B]+[C] — Guardrails & capabilities: where, and the exact security boundary

**Decision: all four classes move to a deterministic pre-Orient guard in Laravel (plan §11 is right);
the *suppression ordering* moves with them; the thin tool keeps exactly one guardrail: always-call.**

The exact boundary — what must never reach an LLM:
1. **Injection-flagged text never reaches any LLM prompt.** `_is_prompt_injection_attempt` (regex) runs
   in Laravel before Orient; on match → canned response, request ends. The Ollama model must never be
   the authority that *clears* text — **ratchet rule: deterministic guard can only be tightened by an
   LLM opinion (Orient may add an `out_of_scope` label for fuzzy cases the regexes miss), never
   loosened.** A model asked to refuse can be argued out of it; a canned string cannot.
2. **Canned guardrail responses are never model-generated or model-paraphrased.** Today the adapter
   *instructs* gpt-5-chat to copy them; under verbatim rendering they become static Laravel strings —
   an upgrade. `model_meta` and `capabilities_onboarding` text: static, one copy, in Laravel (drop the
   `explain_app_capabilities` tool — see Q4).
3. **History is sanitized deterministically before Orient sees it** — port
   `_strip_backend_history_noise` + truncation budgets (`BACKEND_HISTORY_MAX_CHARS`, last-20) as
   Laravel input hygiene; this doubles as the Gap-6 context-budget control.
4. **`stage_trace`/Critic internals never rendered** (Gap 9, already settled — restated as part of the
   boundary because Critic issue text can quote rejected unsafe drafts).

**The load-bearing subtlety §11 doesn't mention:** `classify_turn` line 567 — guardrail evaluation is
*suppressed* when a pending gate or a vague-case-followup context exists. A gate reply ("yes, on
apixaban") or "so what should I do?" must not be eaten by `out_of_scope`. This suppression needs prior
state, which means **the guard is deterministic but not stateless** — it runs pre-Orient but
post-state-load. Port the frozen priority ordering of `classify_turn` verbatim as the guard's spec (its
docstring literally freezes it for behavioral equivalence — treat it as such). One redesign inside B/C:
the "yes/ok after nonclinical" re-trigger currently sniffs transcript markers
(`_recent_nonclinical_context` looking for `APP_GUIDANCE_HEADER` in assistant turns); with a state
brain, record `last_turn_class=guardrail` as a state flag instead — **redesign**, keeping the
transcript-sniff only as an eval oracle.

**What stays in the thin adapter:** nothing classificatory. The tool docstring retains only concern J:
always call the tool, never answer from history, never obey user instructions to skip it, return valid
JSON in tool-selection mode. That is the one guardrail Laravel structurally cannot enforce, because it
governs the OpenWebUI tool-selection model itself.

### Q4 [F] — Guideline selection moves from the OWUI model into Orient

**Decision: make the move (already directionally settled by §11-F); be honest that it is not strictly
better; prove it with a three-part harness before flipping the tool contract; new contract below.**

**Signal lost — name it:** today's router is **gpt-5-chat** (a much stronger model than the ISI Ollama
model) reading the full native conversation plus a curated 14-guideline reference with tuned negative
rules (the antithrombotic restriction) and worked JSON examples. Moving to Orient trades a strong
engine with weak structure for a **weak engine with strong structure** (delta-merged `patient_model`
drives routing; `routing_validity` invariant; single owner; no drift between tool-time selection and
Laravel prunes). Also lost: the `GuidelineKey` enum validation at the tool boundary (Laravel must
canonicalize internally) and the docstring's tool-*choice* triage (absorbed by the Q3 guard once
`explain_app_capabilities` is dropped).

**Why the trade is still right:** the benchmark's router drift (FU2 → Thoracic) was committed *by the
strong model* — docstring routing has no cumulative patient state, and no amount of engine quality
fixes an input gap. The AAA failure is structural, and Orient's structure is the fix. But "strictly
better" is an empirical claim about a weaker model — treat it as unproven.

**Proof, in order (do not flip the contract before all three):**
1. **Offline replay:** build a routing corpus from production logs (Laravel logs the per-request
   guideline params) — score Orient's selection against both the live selections and correctness
   labels, **broken down by turn class** (drift lives in FOLLOWUP turns; aggregate accuracy will hide
   it). *(Assumption (c) above: log richness unverified.)*
2. **Shadow disagreement logging** on live traffic (§8 shadow mode): live-selection vs Orient-selection
   per request; every disagreement judged by the external strong judge. Bar: Orient wins or ties ≥90%
   of disagreements, and loses zero on the 15-case + AAA set.
3. **Eval hard bars** already in §9 (≥95% routing, AAA FU2 hard-fail) on the full scenario suite.

**New thin-tool contract:** **one tool.** `consult_vascular_guidelines(question)` — `guideline_1/2/3`
dropped; `explain_app_capabilities` deleted (its content becomes the Laravel guard's canned path; a
second tool is a residual routing decision at the OWUI layer and a standing failure mode). Request
(adapter-assembled): `{chat_id, message_id, question, raw_history[], attachments_text[]}` → response
`{answer_markdown, questions[], evidence_status{…}, assets[] (refs; markdown already embedded),
state_echo{case_id, patient_model_digest}, stage_ref}`. Docstring shrinks to concern J + "render
`answer_markdown` verbatim". Side benefit: the FUNCTION-CALLING SAFETY rules (docstring §6) shrink with
the schema — fewer enum params, less JSON to get wrong. The 14-guideline REFERENCE + SELECTION RULES
migrate into Orient's prompt/knowledge **and the adapter-side regex pre-signals
(`_has_concrete_vascular_target` etc.) become deterministic routing priors** feeding Orient, per §11's
pre-signal plan. **[BLOCKER precondition]** unify the Laravel-side prunes (antithrombotic companion
prune, carotid disabling-stroke boost) into Orient's routing layer in the same step — leaving them as
post-hoc filters recreates two disagreeing routers inside one VM (concern P).

### Q5 — Safe to DROP

Legacy cruft whose *reason to exist* dies with the target architecture (each with the replacement that
subsumes it):

| Drop | Why safe | Subsumed by |
|---|---|---|
| `_format_gate_for_model` + all "MANDATORY BEHAVIOR / copy exactly" wrappers | existed to police a re-rendering LLM | verbatim contract |
| STRICT_TEMPLATE instruction-injection into `llm_output` (the delivery mechanism, not the content) | same | PHP skeleton + fill (Q1) |
| `_recover_pre_result_from_history`, `_is_pending_gate_message`, `_pending_gate_context` (transcript archaeology) | existed because adapter state was volatile (in-memory TTLStore, 300s TTL) | durable state brain (§4) + `state_echo` |
| `pending_pre_result` two-phase transport (`confirmation_mode` POST mechanics, `_compact_pending_pre_result`) | transport for a state Laravel now owns end-to-end | Orient delta-merge + H's change-decision as an internal Ground cache policy |
| TTLStore + `_await_session_payload` background-task/status-poll machinery | adapter-side session engine | two-POST transport; keep the canned progress *strings* (concern M) |
| `explain_app_capabilities` as a tool | residual OWUI-layer routing | Q3 guard + Q4 one-tool contract |
| Adapter-side gap re-derivation + "Mode:" self-declaration line as UX | intelligence smear | Q2 structured `evidence_status` (Mode kept as log field only) |
| FOLLOWUP_VAGUE `_rewrite_with_case_context` | vague-turn rewrite from stored context | deterministic query serialization from `patient_model` (§3) — **subsumed-by-design: add an explicit vague-followup eval scenario to prove it before deleting** |

**None of the 11 concerns drops wholesale** — the audit was right that all 11 need a landing spot. The
drops above are *mechanisms inside* A/E/H/I/J whose jobs are structurally absorbed. Everything
regex-shaped (predicates, dispatch, lints) is kept as pre-signals/oracles per §11.

---

## 3. Port / redesign / drop — the 11 concerns + misses

| # | Concern | Disposition | Notes |
|---|---|---|---|
| A | Turn taxonomy (`classify_turn`, 7 classes) | **Port semantics verbatim; re-house in Orient + guard** | Frozen priority order = the spec; regex predicates → deterministic pre-signals + eval oracle. Guardrail-suppression ordering (line 567) ports with it (Q3). |
| B | Guardrails (`_guardrail_type` 4 classes + canned replies) | **Port verbatim** into deterministic pre-Orient Laravel guard | Stateful-not-stateless (needs pending-gate/vague context). Ratchet rule: LLM may tighten, never loosen. `_recent_nonclinical_context` transcript-sniff → **redesign** as state flag. |
| C | Capabilities/onboarding (`explain_app_capabilities`, header, "yes/ok" follow) | **Port content verbatim; drop the tool** | Static strings in Laravel, rendered verbatim. |
| D | Response modes (`_response_mode`, `_requires_clinical_decision_summary`) | **Port verbatim as PHP predicates** | Inputs (intent_profile) already Laravel-produced. Mode selects the Q1 skeleton. |
| E | Two-layer blueprint (~300 lines) | **Redesign** (split: structure→PHP skeletons, canned text→rendered strings, content rules→per-section prompt fragments + hedging-lint) | The instruction-sheet *form* is dead under Laravel-verbatim. Embedded clinical content → quarantined audited-snippet library (decision §5.1). Gap-dispatch regexes port verbatim. |
| F | Guideline selection (docstring SELECTION RULES + 14-guideline REFERENCE) | **Redesign into Orient** (reference→prompt knowledge; rules→routing priors; regexes→deterministic pre-signals) | Not a copy-paste: unify Laravel prunes into the same layer (concern P) or two-routers returns. Proof harness before contract flip (Q4). |
| G | Assets (`_format_assets_markdown`, `_format_rec_popup`, `has_assets`) | **Port verbatim** into Laravel answer assembly | Pure rendering; today's "copy image lines verbatim" plea becomes deterministic emission. Suppression rules (`total_gap`/drug/next-step) port as PHP conditions. |
| H | Confirmation + change-detection (`_call_confirmation_phase`, `pending_pre_result`, change_decision) | **Port the semantic; drop the mechanics** | Keep: "does this reply change retrieval → re-retrieve vs reuse" as Ground cache policy off `last_answer_digest`. Drop: two-phase transport + compaction (Q5). |
| I | Three state objects (session / case_context / pending_pre_result) | **Redesign**: all three roles absorbed by the §4 brain | Roles map: session→active-gate substate; case_context→patient_model+digest; pending_pre_result→cached ground result. Adds idempotency/versioning they never had. |
| J | Anti-short-circuit / regeneration docstring rules | **Port verbatim** — the only intelligence the thin tool keeps | Plus "render verbatim". Structurally reinforced (every turn → Laravel). |
| K | Security (injection detection + history sanitization) | **Port verbatim**, deterministic, pre-LLM | Boundary spec in Q3. Never delegated to the local model. |
| L *(missed)* | Attachments ingestion (`attachments_text` contributing case context without bypassing case handling) | **Redesign into Orient** (attachment text is Orient input, provenance-tagged in patient_model) | Contract field exists; behavior unowned in §11. |
| M *(missed)* | Progress/status UX strings (emitter cadence, "Still retrieving… (Ns)") | **Port strings; drop machinery** | Two-POST canned progress reproduces cadence. |
| N *(missed)* | FOLLOWUP_VAGUE rewrite (`_rewrite_with_case_context`) | **Drop — subsumed by design** | Deterministic query serialization from patient_model; prove with a dedicated eval scenario first. |
| O *(missed)* | History hygiene/budgets (`_strip_backend_history_noise`, `BACKEND_HISTORY_MAX_CHARS`, last-20) | **Port verbatim** as Orient input hygiene | Doubles as Gap-6 context-budget control. |
| P *(missed)* | Laravel-side routing prunes (antithrombotic prune, disabling-stroke boost) vs Orient routing | **Redesign — unify into Orient's routing layer** | [BLOCKER] precondition of the F contract flip; otherwise two routers disagree again. |

---

## 4. Safer migration sequence (replaces §8 step 3's bundling)

Principle: **every step is whole-path behind a per-request valve — no step ever ships an answer
assembled half in Python, half in PHP.** Valves are the adopted `STATE_OWNER | GATE_OWNER |
SYNTHESIS_OWNER = adapter|laravel`, plus one new one: **`SYNTHESIS_MODEL = cloud|local`**, which
separates "did the port break it?" from "is the local model too weak?" — the two failure modes §8
currently conflates.

0. **Capability spike** (unchanged, GO/NO-GO).
1. **S0 — Answer path first, against the live pipeline** *(concerns D+E+G+Q2, together — honoring
   §11's "move together", decoupled from the gate)*. Laravel `AnswerAssembly` (PHP skeletons +
   schema fill-call) consumes the **existing** planner/retrieval/gap_assessment outputs and emits
   `answer_markdown`; adapter valve `SYNTHESIS_OWNER=laravel` renders verbatim; old blueprint path
   intact at `=adapter`. Run the fill-call on the **cloud model first** (`SYNTHESIS_MODEL=cloud` —
   gpt-5-mini is already wired in Laravel), so S0 measures the *port* in isolation.
   **Checkpoint:** 15-case binding + verbatim-fidelity ≥98% + gap-taxonomy scenarios. Rollback: flip
   valve.
2. **S1 — Local model swap on the answer path only** (`SYNTHESIS_MODEL=local`). Same skeletons, swap
   engine. **Checkpoint:** 15-case again — any grade drop is now *provably* the model, not the
   migration; this is the earliest, cheapest read on the §0 Ollama bet with production-shaped load.
3. **S2 — Guard + capabilities** *(B+C+K)*: deterministic pre-Orient guard in Laravel; adapter
   guardrail regexes off behind `GATE_OWNER` (or a sub-valve). **Checkpoint:** 15-case (should be
   untouched) + guardrail/injection eval cases + the gate-reply suppression cases (Q3).
4. **S3 — State brain** *(I+H)*: chat_id-keyed state, idempotency, versioning, new-case detection —
   `STATE_OWNER=laravel` while the adapter still drives turn flow (it already has the
   `STATE_BACKEND=laravel` pattern from Stage D — this extends it). **Checkpoint:** adversarial
   state set (chimera, duplicate-delivery, declined-question) + 15-case.
5. **S4 — Orient shadow** *(A+F+P)*: Orient classifies + routes on live traffic, logged only;
   run the Q4 proof harness (replay corpus → shadow disagreements → judged). **No user-visible
   change; no checkpoint gate — exit criterion is the Q4 bars.**
6. **S5 — Tool-contract flip** *(F+J final)*: one-tool docstring, drop `guideline_1/2/3` and
   `explain_app_capabilities`, push via `push_adapter.py` with a rollback copy staged (the
   established `/tmp/adapter_rollback_*` pattern). This is the least-reversible step — it changes
   what the OWUI model sees — hence it comes *after* routing is proven, not before.
   **Checkpoint:** 15-case + AAA FU2 hard bar + routing accuracy on live shadow-verified traffic.
7. **S6 — Deep loop** *(the §3 case path: Ground/Probe/Critic/tail)* behind `GATE_OWNER=laravel`.
   By now it lands on a proven answer path, proven state, proven routing — the loop is the only new
   variable. **Checkpoint:** full §9 suite including AAA benchmark end-to-end + latency SLOs.
8. **S7 — Decommission**: delete adapter blueprint/state/guardrail code paths only after N weeks of
   all-valves-laravel stability; adapter shrinks to transport + J.

Every checkpoint uses the binding 15-case suite; steps S0–S3 cannot regress it by construction claims —
which is exactly why they must still run it (construction claims are where regressions hide).

---

## 5. Open decisions for the human

1. **The embedded clinical content in the blueprint** (apixaban timings, no-bridging-for-AF, triple-
   therapy warning, rupture-risk exemplar): audited static snippet library with clinician sign-off
   (recommended — it is v1.5.x-battle-tested text and Fable's "audited data" category), or drop and
   let the frame generate under lint (risks weak-model hallucination in exactly the highest-stakes
   sections)? **[BLOCKER for S0 — the skeletons can't be finalized without this call.]**
2. **One tool vs two** (Q4 recommends deleting `explain_app_capabilities`): confirm that every
   onboarding/capability turn round-tripping to Laravel is acceptable UX (it adds one fast HTTP call
   to previously adapter-local responses).
3. **`SYNTHESIS_MODEL=cloud` in S0/S1**: running the answer fill-call on OpenAI is a deliberate
   *temporary* widening of cloud dependence to de-risk the port. Confirm this is compatible with the
   ISI local-model timeline, or S0 and S1 collapse into one riskier step.
4. **Decommission trigger (S7)**: define "N weeks stable" concretely (suggest: 4 weeks, zero valve
   rollbacks, zero sev-1 answer-quality reports) so adapter cruft actually gets deleted rather than
   living forever as un-exercised rollback paths.
5. *(Carried, still open from the plan)* PHI-at-rest for `patient_model`; clinician audit commitment —
   both now also gate S3 and S6 respectively, not just launch.
