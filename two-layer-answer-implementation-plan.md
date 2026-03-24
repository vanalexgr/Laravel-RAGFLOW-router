# Implementation Plan: Two-Layer Clinical Answer Architecture

## Objective

Extend the vascular clinical decision-support system to produce **two-layer answers**: a guideline-derived layer (primary) and a supplementary clinical reasoning layer (secondary), with explicit gap detection, provenance tagging, and strict prompt enforcement. The supplementary layer activates only when guideline evidence is insufficient, and is always visibly labeled as non-guideline-derived.

---

## Architecture Context

- **Backend**: Laravel with RAGFlow for chunk retrieval
- **AI orchestration**: Azure OpenAI for query expansion and synthesis
- **Interface**: OpenWebUI (primary), with MCP server enabling Claude Desktop and Codex as additional clients
- **Key services already in place or in progress**: `ChunkSelectionService` (server-side chunk selection in Laravel), `PreRetrievalService` (parallel retrieval pattern), `vascular_mcp_adapter.py` (thin OpenWebUI client)

---

## Phase 1: Gap Detection Service (Laravel)

### 1.1 Create `GapDetectionService.php`

Location: `app/Services/GapDetectionService.php`

This service evaluates whether retrieved chunks adequately cover the clinical query. It runs **after** chunk selection but **before** answer synthesis.

**Inputs:**
- The original user query (post-expansion)
- The selected chunks from `ChunkSelectionService`
- The expanded query facets (from `PreRetrievalService`)

**Logic — implement a coverage scoring approach:**

1. Extract the **clinical facets** of the query. The `PreRetrievalService` already produces expanded query terms — reuse these. For the APS/CLTI example, facets would be:
   - `revascularization_for_clti` (likely covered)
   - `anticoagulation_management` (partially covered)
   - `antiphospholipid_syndrome_specific` (likely NOT covered)
   - `perioperative_anticoagulation_strategy` (likely NOT covered)

2. For each facet, check whether **any selected chunk** contains a direct recommendation or substantive guidance on that facet. This is an LLM call, not keyword matching. Send a structured prompt:

```
You are evaluating whether retrieved guideline chunks address specific clinical facets of a query.

Query: {query}
Clinical facets to evaluate:
{facet_list}

Retrieved chunks:
{chunks}

For each facet, respond with:
- facet: the facet name
- coverage: "direct" | "partial" | "none"
- evidence: brief quote or reference if coverage exists, "none" if not

Respond ONLY in JSON array format, no other text.
```

3. Compute a **gap score**: if any facet has `coverage: "none"` or if the majority have `coverage: "partial"`, flag `has_guideline_gap: true`.

**Output — a `GapAssessment` object:**

```json
{
  "has_guideline_gap": true,
  "covered_facets": ["revascularization_for_clti"],
  "partial_facets": ["anticoagulation_management"],
  "uncovered_facets": ["antiphospholipid_syndrome_specific", "perioperative_anticoagulation_strategy"],
  "gap_summary": "ESVS guidelines address CLTI revascularization indications and general antithrombotic principles for anticoagulated patients, but contain no APS-specific perioperative anticoagulation recommendations.",
  "supplementary_reasoning_permitted": true
}
```

### 1.2 Integration point

Call `GapDetectionService` from the main query pipeline, after `ChunkSelectionService` returns selected chunks and before the synthesis prompt is assembled. Pass the `GapAssessment` object into the synthesis prompt as structured context.

---

## Phase 2: Two-Layer Synthesis Prompt

### 2.1 Redesign the main synthesis system prompt

Replace the current single-layer synthesis prompt with a two-layer prompt. The prompt must enforce strict separation between guideline-derived and supplementary content.

**System prompt structure:**

```
You are a clinical decision-support system for vascular surgery. You produce structured answers with strict evidence provenance.

## Answer Structure Rules

You MUST produce your answer in the following sections, in this exact order. Never merge or blend sections.

### 1. BOTTOM LINE
A 2-3 sentence clinical summary. Every claim here MUST be supportable by either the guideline evidence (Section 2) or supplementary reasoning (Section 4). Tag each sentence with [GUIDELINE] or [SUPPLEMENTARY].

### 2. GUIDELINE-BASED ANSWER
Use ONLY the retrieved guideline chunks provided below. Include recommendation numbers and class of evidence where available.
- State what the guideline directly supports
- State what the guideline directly recommends against
- Do NOT infer, extrapolate, or add clinical reasoning beyond what the chunks state

### 3. GUIDELINE GAP STATEMENT
{gap_detection_output}
If has_guideline_gap is true: State clearly which aspects of the query are NOT addressed by the retrieved guidelines, using the gap_summary provided.
If has_guideline_gap is false: State "The retrieved guidelines directly address all key aspects of this query." and SKIP Section 4 entirely.

### 4. SUPPLEMENTARY CLINICAL REASONING (only if gap exists)
This section is ONLY permitted when has_guideline_gap is true.

Begin this section with the exact label:
"⚠️ Supplementary clinical reasoning — not directly derived from ESVS guidelines"

RULES FOR THIS SECTION:
- You may draw on broader medical knowledge
- You MUST NOT use phrases like "ESVS recommends", "guidelines support", "the guideline suggests" or any language that implies guideline derivation
- You MUST NOT provide specific dosing regimens, exact timing protocols, or definitive treatment sequences
- You MUST frame content using ONLY these permitted heading types:
  a) "Key clinical considerations" — factors the surgeon should weigh
  b) "Common practice patterns" — what is generally done (framed as descriptive, not prescriptive)
  c) "Decision factors" — variables that would change management
  d) "Specialist input recommended" — when other specialties should be involved
  e) "Information needed for decision-making" — what additional data would help
- You MUST NOT use any other heading types in this section
- End this section with: "This reasoning reflects general clinical practice and should be interpreted with clinical judgement."

### 5. PROVENANCE TAGS
For every substantive claim in Sections 2 and 4, append a tag:
- [GUIDELINE: Rec X.X, Class Y] for guideline-derived claims
- [MODEL: general vascular reasoning] for supplementary claims
- [MODEL: requires specialist input] for claims flagging the need for MDT or subspecialty consultation

## NEGATIVE EXAMPLES — never do this:
- "The guideline suggests considering bridging anticoagulation" (if the guideline does not mention this)
- "ESVS recommends hematology involvement" (if this is model reasoning, not guideline text)
- "Administer enoxaparin 1mg/kg BID as bridging" (too specific for supplementary reasoning)
- "The correct approach is..." (too authoritative for supplementary reasoning)

## POSITIVE EXAMPLES — this is correct:
- "The retrieved ESVS guidance does not address APS-specific perioperative management." [GUIDELINE GAP]
- "In patients with high-thrombotic-risk conditions, perioperative anticoagulation strategy is typically individualized, often involving hematology consultation." [MODEL: general vascular reasoning]
- "Factors that would influence the perioperative plan include: thrombotic history, planned procedure type (open vs endovascular), and feasibility of maintaining therapeutic anticoagulation intraoperatively." [MODEL: general vascular reasoning]
```

### 2.2 Dynamic prompt assembly

In your Laravel pipeline (or MCP server, depending on where synthesis is orchestrated):

```php
// Pseudocode for prompt assembly
$gapAssessment = $gapDetectionService->assess($query, $selectedChunks, $queryFacets);

$synthesisContext = [
    'query' => $expandedQuery,
    'chunks' => $selectedChunks,
    'gap_detection_output' => json_encode($gapAssessment),
    'supplementary_permitted' => $gapAssessment->supplementary_reasoning_permitted,
];

// If no gap detected, append an instruction to skip Section 4
if (!$gapAssessment->has_guideline_gap) {
    $synthesisContext['section4_instruction'] = 'SKIP Section 4. Do not produce supplementary reasoning.';
}
```

### 2.3 Anti-drift safeguards in the prompt

Add these as hard rules in the system prompt:

```
## HARD RULES — violations of these make the answer unsafe

1. NEVER let supplementary reasoning contradict guideline evidence. If the guideline says X, supplementary reasoning cannot suggest not-X.
2. NEVER produce supplementary reasoning when has_guideline_gap is false.
3. NEVER use the word "recommend" in the supplementary reasoning section.
4. NEVER provide numerical targets (INR ranges, dosing, timing intervals) in supplementary reasoning unless quoting a non-ESVS source with explicit attribution.
5. If the case involves high-risk decision-making (e.g., perioperative management, emergency treatment sequencing), ALWAYS include "Specialist input recommended" as a subsection in Section 4.
```

---

## Phase 3: Provenance Badge System (Frontend)

### 3.1 Badge definitions

Implement visual badges in OpenWebUI (and expose via MCP for other clients). Define these badge types:

| Badge | Color | Meaning |
|---|---|---|
| `GUIDELINE-DERIVED` | Green | Claim directly supported by retrieved ESVS evidence |
| `GUIDELINE GAP` | Amber | Identified gap — guideline silent on this aspect |
| `SUPPLEMENTARY REASONING` | Blue-grey | Model-based clinical reasoning, not guideline-derived |
| `SPECIALIST CO-MANAGEMENT` | Red/Orange | Case warrants subspecialty or MDT involvement |
| `CONFIDENCE: HIGH` | — | Strong guideline coverage, Class I-II evidence |
| `CONFIDENCE: MODERATE` | — | Partial coverage or Class IIb evidence |
| `CONFIDENCE: LOW` | — | Mostly supplementary reasoning, limited evidence |

### 3.2 Rendering approach

The synthesis prompt already produces provenance tags in the text (e.g., `[GUIDELINE: Rec 6.9, Class I]`). The frontend should:

1. Parse these tags from the response
2. Replace them with styled badge components
3. Use the tag type to determine badge color and icon

For MCP clients (Claude Desktop, Codex), these badges should be rendered as clearly formatted text labels since rich UI isn't available.

### 3.3 Overall confidence indicator

At the top of each answer, display an overall confidence badge based on the gap assessment:

- **HIGH** — all facets covered, `has_guideline_gap: false`
- **MODERATE** — some facets covered, some partial
- **LOW** — majority of facets uncovered, heavy reliance on supplementary reasoning

---

## Phase 4: Guardrails and Validation

### 4.1 Post-generation validation (Laravel)

Create `AnswerValidationService.php` that checks the generated answer before returning it to the user:

**Checks to implement:**

1. **Section structure check**: Verify all required sections are present and in correct order. Reject answers that merge guideline and supplementary content into a single section.

2. **Forbidden phrase scan**: Scan the supplementary reasoning section (Section 4) for phrases that imply guideline authority:
   - "ESVS recommends"
   - "guideline supports"
   - "guidelines suggest"
   - "recommended by"
   - "the correct treatment"
   - "should be treated with" (too prescriptive)
   
   If found: flag and either auto-correct (rewrite via a quick LLM call) or return with a warning to the user.

3. **Specificity check**: Scan supplementary reasoning for overly specific clinical directives:
   - Regex for dosing patterns (e.g., `\d+\s*mg`, `\d+\s*units`)
   - Regex for timing patterns (e.g., `every \d+ hours`, `for \d+ days`)
   - Regex for specific INR/lab targets (e.g., `INR \d+\.\d+`)
   
   If found: flag and rewrite or strip.

4. **Contradiction check**: Verify that no claim in Section 4 directly contradicts a claim in Section 2. This requires an LLM call:

```
Given the following guideline-based answer and supplementary reasoning, identify any direct contradictions.

Guideline answer:
{section_2}

Supplementary reasoning:
{section_4}

If contradictions exist, list them. If none, respond "NO_CONTRADICTIONS".
```

### 4.2 Logging and audit trail

Every answer should log:
- The `GapAssessment` object
- Whether supplementary reasoning was generated
- Which validation checks were triggered
- The final answer as delivered

Store in a `clinical_answer_logs` table for quality review.

---

## Phase 5: Testing with Reference Cases

### 5.1 Build a test suite of edge cases

Create a JSON file of test cases that exercise the gap detection and supplementary reasoning pipeline. Each case should specify:

```json
{
  "id": "aps_clti_001",
  "query": "Revascularization for CLTI and management of anticoagulation in patient with antiphospholipid syndrome",
  "expected_gap": true,
  "expected_covered_facets": ["clti_revascularization_indication", "antithrombotic_general"],
  "expected_uncovered_facets": ["aps_specific_perioperative", "bridging_strategy"],
  "expected_badges": ["GUIDELINE-DERIVED", "GUIDELINE GAP", "SUPPLEMENTARY REASONING", "SPECIALIST CO-MANAGEMENT"],
  "forbidden_in_supplementary": ["ESVS recommends", "enoxaparin 1mg/kg", "INR target 2.5-3.5"],
  "required_in_supplementary": ["hematology", "thrombotic risk", "individualized"]
}
```

**Suggested test cases to include (minimum 10):**

1. APS + CLTI revascularization (the current example)
2. AAA + concurrent CLTI — treatment sequencing
3. Carotid stenosis post-stroke — timing of intervention
4. Type B aortic dissection with malperfusion — TEVAR timing
5. EVAR with hostile neck anatomy — device selection
6. CLTI in dialysis-dependent patient — access preservation vs limb salvage
7. Acute limb ischaemia in pregnancy
8. Mycotic aneurysm management
9. PAD with concurrent active malignancy — revascularization timing
10. Marfan syndrome + aortic root dilation — surveillance thresholds (pure guideline case — should NOT trigger supplementary reasoning)

### 5.2 Automated test runner

Create an artisan command or script that:

1. Runs each test case through the full pipeline
2. Checks gap detection output against expected values
3. Checks synthesis output for forbidden/required phrases
4. Checks badge assignment
5. Produces a pass/fail report

---

## Implementation Order

| Step | Task | Depends on | Estimated effort |
|---|---|---|---|
| 1 | `GapDetectionService.php` — facet extraction + coverage scoring | `ChunkSelectionService` complete | Medium |
| 2 | Redesigned synthesis prompt with two-layer structure | Step 1 | Medium |
| 3 | Dynamic prompt assembly in pipeline | Steps 1-2 | Small |
| 4 | `AnswerValidationService.php` — post-generation checks | Step 2 | Medium |
| 5 | Provenance tag parsing + badge rendering in OpenWebUI | Step 2 | Medium |
| 6 | MCP server output formatting for badge labels | Step 5 | Small |
| 7 | Test case suite + automated runner | Steps 1-4 | Medium |
| 8 | `clinical_answer_logs` table + logging integration | Step 4 | Small |
| 9 | End-to-end testing with all 10+ reference cases | Steps 1-7 | Medium |

**Recommended approach**: Implement steps 1-3 first and test manually with the APS/CLTI case. Once the two-layer output is producing correct structure, add steps 4-6 for safety and UX. Steps 7-9 formalize quality assurance.

---

## Key Design Decisions (Pre-decided)

These are settled — do not re-architect:

1. **Mode B only** — guideline-first with supplementary reasoning when gaps exist. Do not implement Mode A or Mode C toggles.
2. **Gap detection is server-side** in Laravel, not client-side or purely in the synthesis prompt. The `GapAssessment` object is a first-class data structure passed into synthesis.
3. **Supplementary reasoning is constrained to five permitted heading types** (listed in Phase 2). The model cannot freestyle.
4. **No specific dosing, timing, or lab targets** in supplementary reasoning. The model describes considerations, not protocols.
5. **Every answer with supplementary reasoning must include a "Specialist input recommended" subsection** when the case involves perioperative management or emergency decision-making.
6. **Post-generation validation is mandatory**, not optional. Answers that fail validation are rewritten or flagged before delivery.

---

## Files to Create or Modify

**New files:**
- `app/Services/GapDetectionService.php`
- `app/Services/AnswerValidationService.php`
- `database/migrations/xxxx_create_clinical_answer_logs_table.php`
- `app/Models/ClinicalAnswerLog.php`
- `tests/Feature/TwoLayerAnswerTest.php`
- `tests/fixtures/edge_case_test_suite.json`

**Files to modify:**
- Main query pipeline controller/service (wherever synthesis is currently orchestrated) — to integrate gap detection and validation
- Synthesis prompt template — full replacement with two-layer structure
- MCP server response formatting — to include provenance tags in text output
- OpenWebUI frontend components — to parse and render badges
