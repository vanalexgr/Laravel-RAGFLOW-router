# Retrieval Pipeline

How a question becomes evidence chunks, inside the Laravel service. This is the
detailed view of step 4 in [`SYSTEM_ARCHITECTURE.md`](SYSTEM_ARCHITECTURE.md#2-request-lifecycle).
All tunables referenced here are documented in [`CONFIGURATION.md`](CONFIGURATION.md).

---

## Stage overview

```
question
  → PHIScrubberService            strip PHI before any external call
  → PreRetrievalPlannerService    1 OpenAI call → RetrievalPlan
       (fallback: legacy chain — GuidelineRouter + ClinicalInterpreter + normalize)
  → RetrievalService              build dual retrieval requests from the plan
       → bridge /retrieve_dual    parallel narrative + citation branches
            → RAGFlow             vector search + Cohere rerank per branch
  → quality pass (conditional)    re-retrieve if too few citation chunks
  → GapDetectionService (opt.)    detect missing evidence → second pass
  → ChunkSelectionService         authoritative scoring / selection / dedupe
  → GuidelineAssetService         attach figures/tables for cited guidelines
  → response: narrative_chunks, citation_chunks, assets, query_normalization
```

---

## 1. The plan (`PreRetrievalPlannerService` → `RetrievalPlan`)

One LLM call (prompt contract in `PlannerPrompt`, six sections) returns a JSON
plan, parsed into the `App\ValueObjects\RetrievalPlan` value object:

- `normalized_query` — cleaned, standalone query
- `query_type` — **`knowledge`** (definitions, thresholds, population-level) vs
  **`single_case`** (a specific patient). Drives lean vs full retrieval sizing.
- `guidelines` + `guideline_scores` — which ESVS datasets to search, with
  confidence (low-confidence companions are pruned, e.g. `antithrombotic_therapy`
  unless anticoagulation is actually asked)
- `intent` (definition / management / recommendation …)
- `interpretation_terms`, `must_include_terms` — clinical framing / hard filters
- `expansion_terms` — extra retrieval terms
- `graph` — core/related concepts + slots (anatomy, pathology, stage, intervention…)

If the planner call fails or returns unparseable JSON, the service returns `null`
and the **legacy multi-call chain** runs unchanged (normalize → route → interpret
→ expand). `PreRetrievalPlannerService::parseJson` repairs lightly-truncated JSON
(reasoning models sometimes drop a trailing brace) before falling back.

`[PRE-RETRIEVAL TIMING] {"plan_applied":true,…}` in the retrieval log confirms the
merged planner produced a usable plan.

---

## 2. Dual retrieval (`RetrievalService` → bridge `/retrieve_dual`)

Two branches run in parallel against the selected datasets:

- **Narrative branch** — broader context for synthesis (guideline prose).
- **Citation branch** — metatag-scoped, graded recommendation chunks used as
  citations.

Each branch is a vector search over a candidate pool (`RAGFLOW_TOP_K` /
`_LEAN_TOP_K` / `_SINGLE_CASE_TOP_K`, capped by `_TOP_K_CEILING`), then reranked by
Cohere (`RAGFLOW_RERANK_ID`, RAGFlow-side). Final chunk counts are tightly capped
(`RAGFLOW_NARRATIVE_MAX`, `RAGFLOW_CITATION_MAX`). The candidate pool is the
*reranker input size*, not the returned count.

---

## 3. Quality pass

If the citation branch returns fewer than the minimum useful chunks, a second
retrieval runs with a larger pool (`RAGFLOW_QUALITY_PASS_*`). Historically the pool
here caused 60–100s timeouts on multi-guideline queries; it is now capped.

---

## 4. GraphRAG concept expansion (`GraphRagService`)

Expands the query with related clinical concepts (core + related + slots) to catch
evidence the literal query would miss. **The LLM path is disabled**
(`GRAPHRAG_LLM_ENABLED=false`) — a deterministic expander produces good terms
(e.g. AAA → EVAR, open repair, surveillance, 5.5 cm, rupture) with no external
call. Can be re-enabled against any OpenAI-compatible model later.

---

## 5. Gap detection & second pass (`GapDetectionService`)

Checks the retrieved evidence for missing required fields for the case type. A true
gap (not merely partial coverage) triggers a targeted second retrieval. Partial
coverage does **not** trigger a gap — it flows through to synthesis (the adapter’s
answer-mode logic handles compact vs full answers).

---

## 6. Chunk selection (`ChunkSelectionService`)

**Authoritative** for final chunk scoring, selection, dedupe, and intent — the
Laravel side, not the bridge, decides the returned chunk sets. Scoring weights live
in `config/chunk_scoring.php`.

---

## 7. Assets (`GuidelineAssetService`)

Maps the cited guidelines to their configured figures/tables
(`config/guideline_assets.php`) and returns them alongside the chunks.
> Known gap: `descending_thoracic_aorta` has no assets configured.

---

## Failure taxonomy (for debugging synthesis quality)

Tracked classes used during validation: **F0** routing (wrong guideline selected),
**F1–F7** synthesis framing/gap/sequencing/specificity, **F8** decision sequencing
(e.g. anticoagulation before thrombolysis in ALI), **F9** clarification redundancy
(gate asks for info already given). Most F-class issues are handled by the
adapter’s synthesis rules — see [`VASCULAR_MCP_ADAPTER.md`](VASCULAR_MCP_ADAPTER.md).
