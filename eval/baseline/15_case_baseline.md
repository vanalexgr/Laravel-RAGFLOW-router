# 15-case baseline grades — the binding non-regression gate

Authoritative baseline for the plan's binding launch gate ("≥ current production pass rate, no case
drops a grade"). Case **content** (questions + expected clinical answers) is in the repo root file
**`vascular_batch_validation_suite_v_1.md`** (cases C1–C3, S1–S6, F1–F6). Baseline **grades** are from
Batch Validation v1 (2026-03-26, production adapter v1.5.47+).

## Per-case baseline grade

| Case | Baseline grade | Failure code | Notes |
|------|----------------|--------------|-------|
| C1 | PASS | — | COMPACT; DOAC "not indicated" correct |
| C2 | PASS | — | COMPACT; no anticoag without thrombosis |
| C3 | PASS WITH MINOR | — | coverage=none but criteria synthesized correctly |
| S1 | PASS WITH MINOR | F7 (framing) | correct conclusion, slight negative framing |
| S2 | PASS | — | aspirin+rivaroxaban, vein-bypass pathway |
| S3 | PASS | — | ITP modifier front-loaded, tiered tree |
| **S4** | **FAIL** | F4 (specificity) | **carotid web — CONTENT gap in RAGFlow, not architecture** (see caveat) |
| S5 | PASS | — | anticoag alone; recent-surgery contraindication |
| S6 | PASS WITH MINOR | F7 (framing) | correct but slight under-emphasis on urgency |
| F1 | PASS | — | APS tiered modifier, bridging uncertainty correct |
| F2 | PASS | — | ITP first bullet, FULL mode correct |
| F3 | PASS | — | (was PASS WITH MINOR; fixed by SEQUENCING DECISIVENESS RULE) |
| F4 | PASS | — | infection first, EVAR preferred, rivaroxaban |
| F5 | PASS | — | GI-bleed modifier front-loaded |
| F6 | PASS | — | bypass not amputation, APS modifier |

## Grade ordering (for "no grade drop")

`FAIL < PASS WITH MINOR < PASS`. A case may not move down this ordering vs its baseline above.

## Baseline totals

**11 PASS, 3 PASS WITH MINOR (C3, S1, S6), 1 FAIL (S4).**

> Discrepancy flag (do not silently resolve): the memory summary header reads "12 PASS / 2 PASS WITH
> MINOR / 1 FAIL", but the per-case table lists **three** minors (C3, S1, S6) — likely because F3 was
> "PASS WITH MINOR → PASS" after a fix and the header was written mid-flight. Treat the **per-case table
> as authoritative** (11/3/1). Confirm with the human if the exact totals matter for the gate.

## S4 caveat — critical for defining the gate

S4 (symptomatic carotid web) FAILED at baseline because **ESVS has no dedicated carotid-web
recommendation** — the system correctly declared `coverage=none` and applied symptomatic-stenosis
principles; the FAIL is a **RAGFlow content gap, not an architecture defect**. Therefore:
- "No grade drop" for S4 means v2 must **also** correctly declare `not_covered` / `retrieval_uncertain`
  and fall back to principles via the interpretive frame — it is **not** expected to turn S4 into a PASS
  unless the corpus gains carotid-web content.
- S4 is the natural test for the two-frame answer + `retrieval_sufficiency` invariant: re-retrieve, then
  honestly report absence while still giving a usable, flagged non-ESVS answer.

## How the eval consumes this

Each of the 15 cases becomes an eval scenario (`eval/scenarios/`) with `expected.baseline_grade` set
from the table above. The runner scores v2's answer, maps to {FAIL, PASS_WITH_MINOR, PASS}, and asserts
**no downward move** against the baseline. Grading rubric is judged by the external strong-model judge
against the case's expected clinical content in `vascular_batch_validation_suite_v_1.md`.
