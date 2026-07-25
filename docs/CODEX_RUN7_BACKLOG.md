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

## Measurement discipline — FAST TIERS (do not run the full eval after every item)

The full 32-turn eval takes ~45–60 min. Running it 8 times is hours of dead waiting, and **most items do
not need the judge at all**. Validate in three tiers, escalating only as needed.

**Tier 0 — deterministic probes (SECONDS, no LLM judge).** Several items have purely mechanical
acceptance criteria. Build a tiny `gate:probe-retrieval` helper (or reuse tinker) and check:
| Item | Deterministic check | Pass bar |
|---|---|---|
| R7.1 | top-5 similarity of the built query; does `rec_22` appear for the AAA T1 case | sim ≫ the measured 17.6/15.9/15.6 baseline; `rec_22` present |
| R7.2 | signal ratio = cleaned chars / raw chars over ~8 chunks | ≫ the measured **66%** (target ≥90%) |
| R7.3 | inspect the assembled Pathway/Probe payload | both buckets present, labelled, per-bucket caps honoured, `citation_min` met |
| R7.4 | section headings emitted per `response_mode` | correct ordered set per mode |
| R7.8 L1 | `## Evidence Used` output | real `recommendation_id`/class/level, no fabricated ids |
**Iterate here until green — this is a seconds-long loop, not a 45-minute one.**

**Tier 1 — 5-turn canary (~6–8 min).** Add a `--only=<scenario_ids>` (or `--canary`) filter to
`gate:eval`. Canary set, each chosen because it isolates a diagnosed failure:
- `aaa_evolving_context` **T1** — wrong-threshold + missing `rec_22` → tests R7.1 / R7.5 / R7.7
- `aaa_evolving_context` **T3** — false "no coverage" → tests R7.5 **BROAD COVERAGE**
- `batch_f2_clti_itp_after_bypass` — multi-condition FAIL → tests R7.3 / sequencing rules
- `batch_s4_symptomatic_carotid_web` — known RAGFlow **content gap**; must stay an *honest* `not_covered`
  → **regression guard** (must NOT become a false PASS)
- `adversarial_knowledge_interleave` **T2** — knowledge fast path → tests R7.6 mode predicates

**Tier 2 — full 32-turn eval + latency harness (~45–60 min).** Run **once** after a batch of items is
Tier-0/Tier-1 green — not after each one. Record grade delta vs **4/13/15** and latency delta vs
**p50 43.7s / p95 71.1s**. Commit artifact + digest.

**Suggested first slice for a fast result:** land **R7.1 + R7.2** only (the two largest measured wins,
both Tier-0 verifiable in seconds), run the canary, then one full eval. That produces a real,
comparable number in well under an hour instead of a full day. Report it, then continue with the rest.

If an item does not improve things, say so plainly — negative results are results.

## Items (in order — each independently measurable)

1. **R7.1 Query construction.** Stop embedding JSON. Render `patient_model` as natural clinical prose.
   Fold expansion into **Orient's existing schema** (`expansion_terms`, `interpretation_terms`,
   `must_include_terms`) — **do NOT re-enable the merged planner** (extra LLM call + duplicates Orient's
   routing = the two-routers problem). Port `_case_anchor_terms` (10-category anatomical regex taxonomy:
   carotid/stroke/aorta/venous/thrombus/graft/limb/renal_mesenteric/access/trauma) as deterministic query
   anchors. Port `_rewrite_with_case_context` (`"{provisional_diagnosis} — {question}"`) for vague
   follow-ups. Build a **differentiated citation query** rather than reusing one string.
   **Language handling (do NOT build a language-detection pipeline — the reasoning model is multilingual
   and Orient is an implicit normalizer). Two small additions only:**
   - `serializeRetrievalQuery` currently embeds **`$turn` verbatim** (Greek if the user wrote Greek).
     Have Orient emit an English **`core_question`** and build the query from that + `patient_model`, so
     the query is English by construction.
   - **Orient is never instructed to output English** — a multilingual model given Greek input may emit
     Greek *values* (`"lesion":"ανεύρυσμα κοιλιακής αορτής"`), silently breaking that assumption. Add one
     prompt line: *"patient_model values and core_question must be in English clinical terminology,
     regardless of input language."* (Answer-language for the user is a separate product decision — ⛔HUMAN,
     do not decide it here.)
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

8. **R7.8 Verifiable evidence rendering (references, popups, figures).** *Verifiability is a core clinical
   requirement — the clinician must be able to trace every claim back to source.* **The data is already
   there and the gate discards it:** citation chunks return structured fields
   `type, similarity, recommendation_id, class, level, guideline, text`, but `RetrieveEsvsSnippetsTool`
   keeps only `['text','similarity','source']`. Three layers, **all rendered deterministically in PHP —
   never model-generated**:
   - **L1 References.** Preserve `recommendation_id / class / level / guideline` through
     `snippet_digests`; inline `[n]` markers bound to citation records; render `## Evidence Used`, e.g.
     `[1] Recommendation 22 — ESVS 2024 AAA — Class IIa, Level C — "Men with an AAA >55 mm should be
     considered for elective repair."`
   - **L2 Recommendation popups.** Port `_format_rec_popup`'s layout (Recommendation N — Guideline (Year) /
     Category / Strength: Class X; Level Y / Evidence first authors / verbatim text). Pure formatting over
     fields we already have.
   - **L3 Figures/tables.** Call the **existing `GuidelineAssetService`** (it already scans retrieved
     narrative for Figure/Fig/Table/Algorithm references + caption keyword overlap) and render a
     deterministic `## 🖼️ Figures / Tables` section. **484 images are present** on the Hetzner disk
     (`storage/app/public`, `public/storage`) — but `config/guideline_assets.php` is **manifest-driven**
     and the manifest contents are unverified. **Check the manifest first**: if empty/stale, report it as a
     data task (⛔HUMAN) rather than writing code against nothing.

   **Why deterministic rendering matters:** a model can hallucinate *"Recommendation 47, Class I"*; a
   renderer that can only emit from actually-retrieved citation records cannot. This is structurally
   stronger than the legacy adapter, which merely *instructed* the LLM to copy citations faithfully. It
   also makes the Critic's `grounding` invariant mechanically checkable — every claim must map to a
   `recommendation_id` present in the retrieved set, with no LLM judgement required.
   Depends on **R7.3** (labelled citation bucket) and slots into **R7.4**'s `## Evidence Used` section.

## Deferred to S3 / follow-on — clarification-wait retrieval (NOT in Run 7)

The legacy adapter retrieved **in the background while the user typed a clarification answer**
(`asyncio.create_task` at `_prefetch` / `background_task`, stored as `pending_pre_result`, then reused via
`_call_confirmation_phase`'s change-detection). **The gate has no equivalent**, and its `groundCache` is
`private array` — **in-memory, reset every run** — so it evaporates the moment the turn ends.

Note the gate is *better positioned* in one respect: it retrieves **before** asking (Orient → Ground →
Probe decides `ask`), so it already holds evidence at the moment it poses a question. What is missing:

- **[S3, cheap, strictly wins] Cross-turn reuse + change detection.** Today the gate retrieves, asks a
  question, **throws the retrieval away**, then re-retrieves from scratch when the answer arrives — waste
  on *every* clarification turn. Persist `snippet_digests` in the chat_id-keyed state brain alongside
  `last_answer_digest`; on the next turn, check whether the delta-merged `patient_model` **materially**
  changes retrieval before re-running. This is Fable's Q5 recommendation ("keep the change-detection
  semantic as a Ground cache policy off `last_answer_digest`") — already in the plan, never implemented.
- **[follow-on] Branch-speculative retrieval during the wait.** The gate knows its branches explicitly —
  Probe enumerates `unknowns` with `discriminating_variables`, Pathway enumerates candidate pathways — so
  while the clinician types "symptomatic", pre-retrieve the discriminating branches. Dispatch a **queued
  job** on `decision: ask` that writes into the chat_id-keyed state (Laravel is request/response; the
  adapter could use `asyncio` only because it is a long-lived process). Fable's two-POST transport
  (`/gate/start` + `/gate/result`) is the alternative.

**This does NOT contradict removing the speculative prefetch in R7.1** — the two are different:

| | Prefetch removed in R7.1 | Clarification-wait prefetch |
|---|---|---|
| Query quality | **bad** (raw turn, pre-Orient) | **good** (post-Orient, branch-specific) |
| Timing | **on the critical path** | **dead wall-clock** (human reading/typing) |
| Cost of a miss | wasted call **+ triggered a retry** | free — nobody is waiting |

Speculation is only worth it with a good query, during time that is otherwise idle.

## Porting principle (apply to any further candidates)
**Port what constrains stochasticity or saves tokens. Do NOT port workarounds for missing reasoning.**
Multilingual normalization was the latter — dropped, because the reasoning model already does it (see
R7.1). Chunk cleaning, deterministic mode predicates, the clinical rules, and deterministic citation
rendering are the former — keep them.

## Do NOT port
`_format_gate_for_model` and the "MANDATORY BEHAVIOR / copy exactly" wrappers (dead under
Laravel-verbatim); the adapter's state machinery (superseded by the state-brain design).

## Guardrails
No case-specific reasoning guards — the ported rules are general clinical-writing rules and deterministic
lints. Never touch `main`, deploy, push the adapter DB, force-push, or change prod defaults. ⛔HUMAN →
flag & continue (clinician sign-off on audited snippets still outstanding; flag stays OFF). Commit eval
artifacts every time. End with a per-item table: grade delta, latency delta, and what each item was worth.
