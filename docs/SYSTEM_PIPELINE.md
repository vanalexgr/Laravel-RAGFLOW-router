# System Pipeline (Retrieval + GraphRAG)

This document describes the current retrieval pipeline, the GraphRAG expansion flow, and all recent changes applied to production.

## High-Level Flow

1. **PHI Scrub**
   - Removes PHI from the user query before retrieval.
2. **Query Normalization / Intent**
   - When GraphRAG is enabled, `normalizeForRetrieval()` runs for all queries.
   - Produces: `normalized_query`, `language`, `intent`, `question_type`, `key_terms`.
   - The normalized query is **only** used for retrieval if:
     - The query contains non-ASCII, or
     - `GRAPHRAG_USE_NORMALIZED_QUERY=true`.
3. **Clinical Interpreter (Pre-retrieval Framing)**
   - Runs on the scrubbed query before routing.
   - Produces:
     - `clinical_frame` (1-2 sentence, non-recommendation framing)
     - `interpretation_terms` (added to retrieval queries)
     - `must_include_terms` (used to force at least one term-matched citation into LLM context)
4. **Guideline Routing**
   - LLM + guardrails select 1-3 guidelines.
5. **GraphRAG Expansion (NEW)**
   - Uses guideline `key_concepts` + intent `key_terms` as candidates.
   - LLM selects:
     - `core_concepts`
     - `related_concepts`
     - `slots` (anatomy/pathology/stage/intervention/imaging/complications)
   - Heuristic fallback if LLM output is invalid.
6. **Taxonomy Expansion (Optional)**
   - Uses an ESVS term index (taxonomy CSV) to add related guideline terms.
   - Controlled by `TAXONOMY_EXPANSION_ENABLED`.
   - Adds only a small, filtered set of related terms to avoid noise.
7. **Targeted Retrieval**
   - Narrative query = expanded query + GraphRAG retrieval terms.
   - Citation query = base query + core/slot terms.
8. **Base Retrieval**
   - Default params: `top_k=60`, `keyword=false`, `vector_weight=0.5`, `similarity_threshold=0.3`.
9. **Focused Recall (non-A/non-B only)**
   - Runs only if the first pass lacks any non-A/non-B chunk.
10. **Quality Pass (High Recall)**
   - Runs only when evidence is thin (min narrative/citation thresholds).
   - Hybrid search: `top_k=512`, `keyword=true`, `vector_weight=0.2`, `similarity_threshold=0.2`.
11. **Gap Detection**
   - Missing fields -> second pass retrieval.
   - Missing GraphRAG concepts can also trigger second pass.
12. **Formatting + Evidence Binding**
   - Narrative excerpts are trimmed **around the query match** to surface relevant text.
   - Citation chunks used for verbatim recommendation quotes.

---

## Recent Changes (What's New)

### 1) GraphRAG Concept Expansion (Implemented)
- New service: `app/Services/GraphRagService.php`
- New config: `config/graphrag.php`
- Summary:
  - Extracts intent + key terms for all queries (when enabled).
  - Expands query with core/related concepts.
  - Feeds slot concepts into targeted retrieval.
  - Missing GraphRAG concepts are tracked in gap detection.

### 2) Robust GraphRAG JSON Extraction
- Handles LLM outputs with ```json fences or extra text.
- Uses balanced brace parsing to safely extract JSON object.

### 3) Narrative Snippet Targeting (Global)
- Narrative text is now trimmed around query matches instead of front-cutting at 1000 chars.
- Prevents deep-section evidence from being dropped.

### 4) Quality Pass (High Recall) with Guardrails
- When enabled, runs **only if** base retrieval is thin.
- Uses RAGFlow UI-like retrieval settings (hybrid).
- Configured to cap `top_k` at 512 to reduce latency spikes.

### 5) Clinical Interpreter (Pre-retrieval Framing)
- New service: `app/Services/ClinicalInterpreterService.php`
- Adds a short interpretive frame and retrieval terms before routing/retrieval.
- `must_include_terms` are surfaced to OpenWebUI to force a term-matched citation when present.

### 6) Partial-match Answers (Best-fit with Caveats)
- OpenWebUI tool can return a best-fit answer even if no exact scenario match is found.
- Enables explicit caveats rather than a hard "no evidence" response.
- Controlled by `ALLOW_PARTIAL_EVIDENCE_ANSWERS` in the OpenWebUI tool valves.

### 7) Interpretive Frame in LLM Context (Optional)
- If `SHOW_CLINICAL_FRAME=true`, the tool adds:
  - `=== CLINICAL FRAME (INTERPRETIVE / NON-GUIDELINE) ===`
  - This is non-recommendation framing only.

### 8) Term-matched Evidence Inclusion (OpenWebUI Tool)
- The tool attempts to force at least one term-matched citation into LLM-visible evidence.
- Term candidates include intent key terms, interpreter terms, and must-include terms.

---

## GraphRAG Details

### Candidate Concepts
From:
- `config/guidelines.php` -> `key_concepts`
- Intent `key_terms` from query normalization

### LLM Output (Expected JSON)
```
{
  "core_concepts": [],
  "related_concepts": [],
  "slots": {
    "anatomy": [],
    "pathology": [],
    "stage": [],
    "intervention": [],
    "imaging": [],
    "complications": []
  }
}
```
If this fails, heuristic expansion uses matched concepts from the query.

### Query Injection
- Narrative query:
  - Base query + `core_concepts` + `related_concepts` + slot terms
- Citation query:
  - Base query + `core_concepts` + slot terms (intervention/imaging/complications)

---

## Gap Detection Changes

Gap detection now checks:
1. Required clinical fields (existing logic).
2. **Missing GraphRAG concepts** (if enabled).

If missing, second-pass retrieval is triggered with those concepts appended.

---

## Config Summary (Current)

### GraphRAG
```
GRAPHRAG_ENABLED=true
GRAPHRAG_LLM_ENABLED=true
GRAPHRAG_INTENT_ENABLED=true
GRAPHRAG_USE_NORMALIZED_QUERY=false
GRAPHRAG_MAX_CANDIDATES=60
GRAPHRAG_MAX_CORE=8
GRAPHRAG_MAX_RELATED=8
GRAPHRAG_MAX_QUERY_TERMS=12
GRAPHRAG_CONCEPT_GAP_CHECK=true
GRAPHRAG_CONCEPT_GAP_MAX=6
GRAPHRAG_DEBUG=false
```

### Quality Pass Guardrails
```
RAGFLOW_QUALITY_PASS_ENABLED=true
RAGFLOW_QUALITY_PASS_MIN_NARRATIVE=8
RAGFLOW_QUALITY_PASS_MIN_CITATION=4
RAGFLOW_QUALITY_PASS_TOP_K=512
RAGFLOW_QUALITY_PASS_KEYWORD_MODE=true
RAGFLOW_QUALITY_PASS_VECTOR_WEIGHT=0.2
```

---

## Operational Notes

### Logs to Watch
- `[GRAPHRAG] Concept expansion` -> core/related concepts + slots
- `[GRAPHRAG] LLM JSON parse failed` -> LLM output invalid (fallback used)
- `[QUALITY PASS]` -> high-recall pass running
- `[FOCUSED RECALL]` -> non-A/non-B retrieval retry
- `[GAP DETECTION]` -> missing fields or missing concepts
- `[CLINICAL INTERPRETER]` -> pre-retrieval frame + term expansion

### Rollback Switches
- GraphRAG off: `GRAPHRAG_ENABLED=false`
- Quality pass off: `RAGFLOW_QUALITY_PASS_ENABLED=false`
- Focused recall off: `RAGFLOW_FOCUSED_RECALL_ENABLED=false`
- Gap detection off: `GAP_DETECTION_ENABLED=false`
