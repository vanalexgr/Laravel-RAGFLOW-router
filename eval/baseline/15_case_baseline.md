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
| **S4** | **FAIL** | F4 (specificity) | ⚠️ **diagnosis CORRECTED 2026-07-26** — this was a **retrieval** failure, not a corpus gap; ESVS carotid-web guidance exists (clinician-confirmed). See the S4 section below. |
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

## ⚠️ S4 — THE ORIGINAL DIAGNOSIS WAS WRONG (corrected 2026-07-26, clinician-confirmed)

**Superseded.** The March 2026 batch validation concluded S4 (symptomatic carotid web) failed because
*"ESVS has no dedicated carotid-web recommendation — a RAGFlow content gap, not an architecture defect."*
**That is incorrect.** In Run 7, the improved query construction (R7.1) retrieved an **actual carotid-web
recommendation (Class IIb, Level C — "CEA or CAS may be considered…")**, and the **clinician has confirmed
that ESVS guidance on carotid web exists**.

**So S4's baseline FAIL was a RETRIEVAL failure, not a corpus gap** — the evidence was in the corpus all
along; the old query construction could not reach it. R7.1 closed a real clinical gap that had been
misdiagnosed for ~4 months.

**Consequences:**
- The previous instruction — *"S4 must stay `not_covered`; do not expect a PASS"* — is **withdrawn**.
  Reporting `covered` on S4 is **correct behaviour**, not a false pass. A canary/regression guard asserting
  `not_covered` would force the system to suppress real evidence (Codex correctly refused to implement it).
- S4's `baseline_grade` (FAIL) is retained for arithmetic continuity, but **it is a floor, not a target**:
  S4 improving is a genuine win.
- **[ACTION] Audit every other `not_covered` / `retrieval_uncertain` verdict.** If one confirmed "corpus
  gap" was really a retrieval gap, others may be too — the system may have been **systematically
  under-claiming coverage**. This is now the highest-value use of the clinician's review time, because a
  false "ESVS is silent" is a clinically worse failure than a merely incomplete answer.

**Note on the metric:** S4 was `PASS_WITH_MINOR` in both Run 6 and Run 7 — the *grade* did not move, but
the *evidence_status* went from a false absence to a correctly cited recommendation. The strict-judge
aggregate did not reward this. Treat aggregate grade as a **lossy** measure of clinical improvement.

## How the eval consumes this

Each of the 15 cases becomes an eval scenario (`eval/scenarios/`) with `expected.baseline_grade` set
from the table above. The runner scores v2's answer, maps to {FAIL, PASS_WITH_MINOR, PASS}, and asserts
**no downward move** against the baseline. Grading rubric is judged by the external strong-model judge
against the case's expected clinical content in `vascular_batch_validation_suite_v_1.md`.
