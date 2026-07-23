# Prompt for Fable — adapter migration review (scoped)

You are a senior systems architect. This is a **scoped follow-up review, not a re-review of the whole
design.** You previously reviewed the Agentic Gate v2 plan; your review was adopted (see
`docs/AGENTIC_GATE_V2_REVIEW.md` and the resulting `docs/AGENTIC_GATE_V2_PLAN.md`). **Do not
relitigate settled decisions** (Ollama serving, Laravel-verbatim answers, general evaluate-improve
loop, single Laravel state brain, 60 s deep SLO, binding 15-case gate). **Write zero application
code.** Output an improved migration design + critique only.

## The one thing to review

The "all intelligence in Laravel, thin OpenWebUI adapter" commitment means migrating the live
`openwebui_tools/vascular_mcp_adapter.py` (~3000 lines, v1.5.x) into Laravel. An audit
(**plan §11 — read it first**) found the adapter holds 11 intelligence concerns, several missing from
earlier drafts. Your job: pressure-test **§11's migration coverage and additions** and tell us what to
port verbatim, what to redesign, and what to drop — before we wire anything.

## Read first (assume the reader knows them — do not summarize back)

- `docs/AGENTIC_GATE_V2_PLAN.md` **§11** (adapter inventory + gap analysis) and §3, §4, §7, §10.
- The live adapter `openwebui_tools/vascular_mcp_adapter.py` — especially `classify_turn`,
  `_guardrail_type`, `_response_mode`, `_requires_clinical_decision_summary`,
  `_build_two_layer_blueprint` / `_build_answer_blueprint`, the `consult_vascular_guidelines`
  docstring (SELECTION RULES + GUIDELINE REFERENCE), `explain_app_capabilities`, and the
  session / case_context / pending_pre_result state trio.
- `memory/benchmark_aaa_evolving_context.md` for the failure bar.

## The five questions to answer (priority order)

1. **[E]+[D] Port vs redesign the answer-assembly brain.** The two-layer blueprint + `_response_mode`
   variants + `_requires_clinical_decision_summary` (~300 lines of DECISIVENESS/ARTIFACT/FORBIDDEN-
   hedging rules, section order, gap-type sub-structures) is where the v1.5.x intelligence lives.
   Options: (a) lift the prescriptive rules verbatim into Laravel prompts; (b) restructure into
   **deterministic PHP section-scaffolding** that the model only fills with content; (c) hybrid. Given
   Laravel-verbatim output + a **weaker Ollama model** (prescriptive rules may matter *more*, not
   less), rank and pick. Name what breaks under each.
2. **[E] Gap taxonomy.** The adapter distinguishes `total_gap` / `question_gap` /
   `core_question_covered ∈ {none, partial}` with interaction-gap vs sequencing vs perioperative-drug
   sub-structures. Does this collapse cleanly into the plan's `evidence_status`
   (`esvs_sufficient|partial|absent|retrieval_uncertain`), or is the richer taxonomy load-bearing?
   If load-bearing, specify the minimal set of states worth keeping.
3. **[B]+[C] Guardrails & capabilities.** `prompt_injection`, `model_meta`, `capabilities_onboarding`,
   `out_of_scope` + the onboarding flow. Where does each belong — deterministic Laravel pre-Orient
   guard, the thin adapter, or Orient's mode enum? Draw the exact injection/security boundary (what
   must never reach an LLM). Is a canned-response path or an LLM path right for capabilities?
4. **[F] Tool-contract change.** Moving guideline selection from the model (docstring `guideline_1/2/3`
   + SELECTION RULES + 14-guideline REFERENCE) into Orient. What signal, if any, is lost when the OWUI
   model stops choosing guidelines? Is Orient strictly better, and how do we prove it (shadow metric)?
   Specify the new thin-tool request/response contract precisely.
5. **What is safe to DROP.** Of the 11 concerns, which are legacy cruft, redundant, or obsolete under
   the new architecture and should NOT be ported? Be specific; a smaller migration is a better one.

Then: **[migration sequencing]** §8 says "move answer-assembly + v1.5.x rules + response-mode + assets
together." That is a large, risky step gated on the 15-case suite. Propose a safer decomposition that
still never ships a half-migrated answer path.

## Rules of engagement

- No code. Control-flow sketches only where a diagram genuinely clarifies a proposed change.
- React to §11; don't restate it. Rank options and pick. Flag any codebase assumption you couldn't
  verify from the files rather than guessing.
- Tag every finding **[BLOCKER]** (must fix before migrating) / **[IMPROVEMENT]** / **[QUESTION]**.
- Distinguish **port-verbatim** vs **redesign** vs **drop** explicitly for each behavior you touch.

## Output format

1. **Verdict** — 3-5 sentences: is §11's coverage complete and correctly owned, and the single biggest
   change to the migration.
2. **The five questions** — one tight decision each (with the option you picked and why).
3. **Port / redesign / drop table** — the 11 concerns (plan §11 A–K), each tagged with your call and a
   one-line justification. Add any concern §11 still missed.
4. **Safer migration sequence** — the decomposition, with the valve/flag at each step and its 15-case
   checkpoint.
5. **Open decisions for the human** — the few calls only we can make.

Dense and decision-forward. This directly determines what we build for the thin adapter.
