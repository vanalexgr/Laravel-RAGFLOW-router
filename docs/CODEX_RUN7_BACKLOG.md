# Codex Run 7 — restore the discarded production subsystems

Branch `claude/prototyping-summary-d597c2`. `git pull` first. Autonomy rules in `docs/CODEX_HANDOFF.md`;
keep `docs/CODEX_PROGRESS.md` current. Runs on Hetzner (disposable checkout, detached `nohup` + exit
marker — never foreground over SSH). **Do not change production `.env`/config/defaults.** Only
`gate:eval --sut=http --judge=external` counts. SUT = direct `GateWorkflowService` invocation.

## Why this run exists

Run 6 gave the first trustworthy baseline: **4 PASS / 13 MINOR / 15 FAIL**, routing 100%, verbatim 100%
(`docs/eval/run6_fail_digest.md`). Diagnosis since then: **the gate discarded working production
subsystems** — it is not missing new engineering, it is missing restoration. Measured evidence:

- **Query construction**: the gate sends `json_encode($patientModel)` to the embedder and disables the
  planner/interpreter/graph. Measured live: gate-style query → top-5 sim **17.6/15.9/15.6/17/36** and
  **misses `rec_22`** ("AAA >55 mm should be considered for elective repair"); clean NL+terms query →
  **41.3/34.8/47.3/46.3/45.1** and finds it. **~3× similarity loss.** That is the AAA T1 failure.
- **Chunk cleaning**: the gate does `$chunk['content']` → `trim()`, nothing else. Measured: recommendation
  chunks are **52–56% signal** (44–48% HTML markup + `rec_id:…;category_name:…;rec_text_verbatim:` prefix);
  **34% waste** across 8 chunks; at the 1,200-char digest cap only **~793 chars** of clinical text reaches
  the model.
- **Dual retrieval flattened**: production has a two-KB split (narrative prose KBs with KG **on**; a shared
  recommendations dataset with metatags `recommendation_id/class/level/guideline`, KG **off**), separate
  citation query + tuning + per-guideline quotas. The gate merges both buckets into one flat 10-item list
  (citations first, `break 2`), erasing the recommendation-vs-prose distinction and letting one bucket
  crowd out the other.
- **Output formatting**: the adapter has a 23-section, mode-conditioned grammar; the gate emits flat
  two-frame prose. Orient already emits `response_mode` and the workflow passes it, but nothing downstream
  consumes it. Explains judge labels `formatting_noncompliant` / `formatting_heavy_markdown`.
- **16 clinical rules** from v1.5.x map ~1:1 onto Run-6 failures and are entirely absent from the gate.

## Measurement discipline

**Each item is landed and measured separately** so we learn what each is worth. After every item: run
`gate:eval` (record grade delta vs **4/13/15**) **and** the four-turn latency harness (record delta vs
**p50 43.7s / p95 71.1s** control, or **33.9/50.5** bridge). Commit the artifact + digest each time. If an
item does not improve the grade, say so plainly — negative results are results.

## Items (in order — each independently measurable)

1. **R7.1 Query construction.** Stop embedding JSON. Render `patient_model` as natural clinical prose.
   Fold expansion into **Orient's existing schema** (`expansion_terms`, `interpretation_terms`,
   `must_include_terms`) — **do NOT re-enable the merged planner** (extra LLM call + duplicates Orient's
   routing = the two-routers problem). Port `_case_anchor_terms` (10-category anatomical regex taxonomy:
   carotid/stroke/aorta/venous/thrombus/graft/limb/renal_mesenteric/access/trauma) as deterministic query
   anchors. Port `_rewrite_with_case_context` (`"{provisional_diagnosis} — {question}"`) for vague
   follow-ups. Build a **differentiated citation query** rather than reusing one string.
2. **R7.2 Chunk cleaning + metadata parsing.** Port `_html_table_to_text` (ESVS rec tables are HTML →
   `cell | cell` text), `_clean_narrative_text` (flatten tables → strip tags → unescape entities → strip
   markdown → collapse whitespace), `_parse_semicolon_kv` (metadata → **structured fields**, not prompt
   noise), `_truncate_for_llm` (explicit `[...truncated...]` marker). Re-measure the signal ratio.
3. **R7.3 Preserve the citation/narrative split.** Stop flattening. Pass the two buckets **separately and
   labelled** into Pathway/Probe/Critic with **per-bucket caps** (honour `citation_min`), so the model can
   tell an authoritative Class/Level recommendation from explanatory prose.
4. **R7.4 Mode-conditioned section templates.** Consume the `response_mode` Orient already emits.
   Deterministic PHP section skeletons per mode (management / gap / surveillance / diagnostic / knowledge)
   per the adapter's grammar — incl. `## Clinical Decision`, `## What is NOT indicated (if relevant)`,
   `## Guideline-Based Options`, `## Clinical Decision Summary`, `### 🎯 In practice`, `## Evidence Used`.
   Pairs with R7.3: `## Evidence Used` can only cite properly once citations are identifiable.
5. **R7.5 Tier-1 clinical rules.** **BROAD COVERAGE RULE** ("a guideline addressing the broader category
   covers specific sub-scenarios") → PathwayAgent coverage + Critic; fixes the false-`not_covered` class
   (AAA T3, F3 ×4). **NEGATIVE INDICATION FRAMING** (positive rec first, then exclusion) → AAA T1's
   framing. **DECISION-FIRST / DECISIVENESS / DOMINANT MODIFIER** → `insufficient_patient_specificity`,
   F7 ×5. **CRITICAL SCOPE RULE** ("only applicable if it addresses THE SAME condition and procedure") →
   Critic, citation-level (its `routing_validity` is guideline-level only).
6. **R7.6 Deterministic mode predicates as Orient priors.** Port `_is_raw_guideline_knowledge_query`,
   `_is_answer_only_turn`, `_looks_like_fresh_case_intro`, `_should_treat_as_new_query` as deterministic
   guards/priors on Orient's stochastic mode decision — the same mis-classification that caused three
   baseline aborts. Bias to `case` when signals conflict.
7. **R7.7 Numeric-threshold check.** The one failure no adapter rule covers: AAA T1 judged a **58 mm**
   aneurysm by a **"<55 mm"** recommendation. Require an explicit numeric comparison of the patient's
   measurement against any retrieved threshold before applying a recommendation.

## Do NOT port
`_format_gate_for_model` and the "MANDATORY BEHAVIOR / copy exactly" wrappers (dead under
Laravel-verbatim); the adapter's state machinery (superseded by the state-brain design).

## Guardrails
No case-specific reasoning guards — the ported rules are general clinical-writing rules and deterministic
lints. Never touch `main`, deploy, push the adapter DB, force-push, or change prod defaults. ⛔HUMAN →
flag & continue (clinician sign-off on audited snippets still outstanding; flag stays OFF). Commit eval
artifacts every time. End with a per-item table: grade delta, latency delta, and what each item was worth.
