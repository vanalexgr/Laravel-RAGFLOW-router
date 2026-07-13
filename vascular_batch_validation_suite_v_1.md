# Vascular Guideline System Batch Validation Suite (15 Cases)

## Purpose
This document is for Claude Code to run a structured validation batch against the current ESVS vascular guideline system, classify failures by type, and return a concrete action plan.

The goal is **not** to patch individual answers case by case. The goal is to:
1. Run all 15 cases.
2. Score each answer by **failure mode** and **severity**.
3. Detect repeated patterns.
4. Propose a small number of **generic architectural fixes**.
5. Avoid adding more reactive injection blocks unless absolutely necessary.

---

## What Claude Code Should Do

For each test case below:
1. Run the case through the current system.
2. Save the raw system output.
3. Classify the answer into one of the expected modes:
   - COMPACT
   - STANDARD
   - FULL
4. Score the answer using the failure taxonomy below.
5. Decide whether the output is:
   - PASS
   - PASS WITH MINOR ISSUES
   - FAIL
6. At the end of the full batch, group failures by **pattern**, not by case.
7. Produce a **concrete action plan** with:
   - fixes to implement now
   - fixes to defer
   - prompt / adapter / retrieval / template changes required

---

## Failure Taxonomy

Use these labels only.

### F1. Pathway contamination
Wrong procedural or disease pathway leaks into the answer after the correct pathway is known.
Examples:
- bypass branch shown in an endovascular case
- endovascular options retained after vein bypass confirmed

### F2. Modifier misplacement
A major modifier is present but appears too late or too weakly.
Examples:
- ITP mentioned only in follow-up
- recent GI bleed buried after standard recommendation
- anticoagulation modifier appears after final regimen already stated

### F3. False gap detection
The answer claims “guideline gap” or “partial guidance” when the guideline is actually usable.
Examples:
- negative indication misclassified as gap
- contraindication misclassified as gap
- broad but sufficient recommendation misclassified as gap

### F4. Specificity collapse
After clarification, the answer stays generic instead of narrowing to the actual case.
Examples:
- still discusses all revascularisation types after vein bypass confirmed
- still generic PAD answer after tibial angioplasty clarified

### F5. Over-expansion / cognitive overload
Too many options are shown when one main decision is clear.
Examples:
- simple DVT answer includes unnecessary branches, figures, long follow-up
- asymptomatic carotid answer becomes a mini-review instead of a direct decision

### F6. Multi-condition reasoning failure
FULL-mode answer does not reason clearly across interacting conditions.
Examples:
- hedge-heavy answer
- misses dominant priority
- lists evidence but does not resolve interaction

### F7. Decision clarity failure
The answer explains options correctly but does not state what should actually be done.
Examples:
- “consider X / Y / Z” without choosing one
- no direct recommendation in a simple or standard case

### Severity labels
For each failure, assign one severity:
- S1 = cosmetic / formatting
- S2 = usability / clarity
- S3 = clinical trust problem
- S4 = safety-critical

Safety-critical examples:
- bleeding modifier buried after unsafe standard regimen
- false reassurance around anticoagulation
- wrong procedural priority in sepsis / bleeding / stroke scenarios

---

## Expected Mode Logic

### COMPACT
Use when:
- single-path case
- direct guideline recommendation or direct negative indication
- no unresolved multi-condition interaction

### STANDARD
Use when:
- guideline applies, but there is a relevant modifier or selective indication
- still answerable without a true gap section

### FULL
Use when:
- there is a true multi-condition interaction not directly addressed by ESVS
- the system must separate guideline-supported statements from non-guideline synthesis

---

## Structured Header to Assess (Target State)

If the system outputs or can be made to output a reasoning header, assess whether it correctly declares:
- Mode
- Pathway
- Modifier
- Coverage
- Optional: Decision status

Suggested target examples:
- Mode: COMPACT / Pathway: proximal femoral DVT / Modifier: none / Coverage: full
- Mode: STANDARD / Pathway: endovascular tibial / Modifier: ITP-bleeding risk / Coverage: full
- Mode: FULL / Pathway: AAA + CLTI + sepsis / Modifier: infection + anticoagulation / Coverage: gap

---

# 15-Case Validation Batch

## COMPACT CASES (3)

### Case C1 — Proximal DVT, simple
**Question:**
Patient with proximal femoral DVT, no severe symptoms, no cancer, no bleeding risk. Should this patient receive anticoagulation alone, undergo thrombolysis, or have an IVC filter?

**Expected mode:** COMPACT
**Expected core answer:**
- Anticoagulation alone
- DOAC for 3 months
- No thrombolysis
- No IVC filter

**Main failure risks:** F3, F5, F7

---

### Case C2 — Tumor compressing brachial vein, no thrombosis
**Question:**
Patient with tumor compressing the brachial vein. No thrombosis. Symptomatic swelling. Should he take anticoagulation?

**Expected mode:** COMPACT
**Expected core answer:**
- No anticoagulation without confirmed thrombosis
- Manage underlying compression
- Monitor for DVT

**Main failure risks:** F3, F5, F7

---

### Case C3 — Carotid near-occlusion criteria
**Question:**
Give the criteria of carotid near-occlusion.

**Expected mode:** COMPACT
**Expected core answer:**
- Definition-based answer
- Distal ICA collapse / reduced calibre / severe proximal stenosis / comparison to contralateral side
- No unnecessary management branch unless specifically asked

**Main failure risks:** F5, F7

---

## STANDARD CASES (6)

### Case S1 — Asymptomatic carotid stenosis
**Question:**
Patient with asymptomatic carotid stenosis 75%, age 72, on best medical therapy, no prior stroke/TIA, unilateral disease, CTA confirmed, low surgical risk. Should this patient undergo CEA, CAS, or medical therapy only?

**Expected mode:** STANDARD
**Expected core answer:**
- Continue best medical therapy alone
- No routine intervention
- CEA only in selected high-risk-feature cases
- CAS only if CEA unsuitable / selected context

**Main failure risks:** F3, F5, F7

---

### Case S2 — Post-vein bypass antithrombotics
**Question:**
What is the recommended antithrombotic therapy after lower limb revascularization for peripheral arterial disease?
Clarifications: vein BK bypass, rest pain pre-op, no high bleeding risk.

**Expected mode:** STANDARD
**Expected core answer:**
- After infrainguinal vein bypass: aspirin 75–100 mg daily + rivaroxaban 2.5 mg BID if bleeding risk acceptable
- Avoid prolonged clopidogrel addition beyond 30 days
- Alternative: single antiplatelet if bleeding risk high / rivaroxaban unsuitable

**Main failure risks:** F1, F3, F4, F5

---

### Case S3 — Post-endovascular antithrombotics with ITP
**Question:**
What is the recommended antithrombotic therapy after lower limb revascularization for peripheral arterial disease?
Clarifications: endovascular tibial angioplasty DEB, symptomatic IC/CLTI, ITP.

**Expected mode:** STANDARD
**Expected core answer:**
- Standard post-endovascular options: aspirin+rivaroxaban OR short-course DAPT
- But only if bleeding risk acceptable
- ITP must modify the recommendation early in the answer
- Consider de-escalation if platelet count / bleeding risk unfavorable

**Main failure risks:** F2, F4, F7

---

### Case S4 — Symptomatic carotid web
**Question:**
How to treat symptomatic carotid web in a 40-year-old patient?
Clarifications: symptoms 1 week ago, 70% stenosis, no prior history.

**Expected mode:** STANDARD
**Expected core answer:**
- Offer revascularisation
- CEA generally preferred in young fit patient unless reasons for CAS
- Acknowledge limited evidence / Class IIb style support

**Main failure risks:** F5, F7

---

### Case S5 — Acute iliofemoral DVT after recent surgery
**Question:**
Patient with acute iliofemoral DVT extending into the common femoral vein, severe swelling and pain (phlegmasia alba), recent major abdominal surgery 5 days ago. Stable Hb, no phlegmasia cerulea, no other contraindications. Should this patient receive anticoagulation alone, catheter-directed thrombolysis, or surgical thrombectomy? How should bleeding risk influence the decision?

**Expected mode:** STANDARD
**Expected core answer:**
- Anticoagulation alone
- Thrombolysis / thrombectomy not favored due to recent major surgery and no limb threat
- Filter only if anticoagulation impossible

**Main failure risks:** F3, F5, F7

---

### Case S6 — Urgent CEA with AF on apixaban
**Question:**
Patient with symptomatic carotid stenosis (80%) scheduled for urgent CEA is found to have AF on apixaban. Recent TIA 10 days ago. Persistent AF. How should anticoagulation be managed perioperatively, and should antiplatelet therapy be added?

**Expected mode:** STANDARD
**Expected core answer:**
- CEA still urgent
- Stop DOAC perioperatively, no routine bridging for AF
- Use antiplatelet therapy for CEA
- Avoid prolonged combination strategies

**Main failure risks:** F2, F3, F7

---

## FULL CASES (6)

### Case F1 — CLTI + APS on warfarin
**Question:**
Patient with CLTI undergoing infrainguinal bypass has antiphospholipid syndrome on warfarin. Should anticoagulation be bridged perioperatively, and should antiplatelet therapy be added postoperatively?

**Expected mode:** FULL
**Expected core answer:**
- Guideline support for conduit / post-bypass strategies exists
- No APS-specific ESVS perioperative protocol
- High-risk APS often bridged in practice
- Routine combined therapy not automatic; depends on bleeding vs graft thrombosis risk

**Main failure risks:** F2, F6, F7

---

### Case F2 — CLTI + ITP after bypass
**Question:**
Post-op management of CLTI patient with ITP submitted to bypass surgery.

**Expected mode:** FULL
**Expected core answer:**
- Standard aspirin+rivaroxaban recommendation only applies if bleeding risk acceptable
- ITP may place patient outside standard group
- Therapy should be modified dynamically by platelet count / bleeding status
- Stepwise or delayed escalation reasonable

**Main failure risks:** F2, F6, F7

---

### Case F3 — AAA + CLTI sequencing
**Question:**
Patient with a 5.8 cm infrarenal AAA and concomitant CLTI (Rutherford 5, tissue loss). Which should be treated first — aneurysm repair or limb revascularisation?
Clarifications: asymptomatic AAA, stable.

**Expected mode:** FULL
**Expected core answer:**
- Both conditions are guideline-covered individually
- No ESVS sequencing rule
- Limb revascularisation usually prioritised first in stable asymptomatic AAA
- Explicit sequencing decision required

**Main failure risks:** F3, F6, F7

---

### Case F4 — AAA + CLTI + sepsis + anticoagulation
**Question:**
Patient with 6.5 cm infrarenal AAA and concomitant CLTI (Rutherford 6, wet gangrene with sepsis) on rivaroxaban for AF. Should you prioritise aneurysm repair, limb surgery, or infection control first? How should anticoagulation and antiplatelet therapy be managed perioperatively?
Clarifications: AAA asymptomatic, stable.

**Expected mode:** FULL
**Expected core answer:**
- Sepsis / source control first
- Limb surgery before AAA repair if AAA stable
- Stop rivaroxaban perioperatively, no routine bridging for standard AF
- Restart when haemostasis / infection control allow

**Main failure risks:** F2, F6, F7

---

### Case F5 — Urgent CEA + AF + recent GI bleed
**Question:**
75-year-old with TIA 2 days ago, 80% symptomatic carotid stenosis, AF on apixaban, recent upper GI bleed 7 days ago now stable. Proceed to urgent CEA or delay? How should apixaban and antiplatelet therapy be managed?

**Expected mode:** FULL or high-end STANDARD depending on implementation
**Expected core answer:**
- Urgent CEA still indicated within days
- Stop apixaban perioperatively, no routine bridging for AF
- Antiplatelet still needed, but bleeding modifier must be front-loaded
- Avoid triple therapy / overaggressive combination

**Main failure risks:** F2, F3, F6, F7

---

### Case F6 — APS + CLTI bypass vs amputation
**Question:**
Patient with CLTI and antiphospholipid syndrome on Sintrom. Should he be offered below-knee bypass surgery or primary amputation?
Clarifications: Rutherford 4, suitable distal target, good functional status.

**Expected mode:** FULL-borderline or STANDARD with modifier depending on implementation
**Expected core answer:**
- Core decision is still guideline-defined: offer bypass, not primary amputation
- APS modifies perioperative management, not the limb-salvage indication
- Avoid false gap on bypass vs amputation decision

**Main failure risks:** F2, F3, F6, F7

---

# Required Output Format for Claude Code

## Part A — Per-case table
For each case, output a row with:
- Case ID
- Expected Mode
- Actual Mode
- PASS / PASS WITH MINOR ISSUES / FAIL
- Failure labels (F1–F7)
- Severity (S1–S4)
- 1–2 sentence justification

## Part B — Pattern summary
Aggregate by failure type:
- Which failures occurred most often?
- Which were safety-critical?
- Which were cosmetic only?

## Part C — Action plan
Provide exactly 3 sections:

### 1. Implement now
High-value architectural or template changes justified by repeated failures.

### 2. Defer
Ideas worth keeping but not yet justified.

### 3. Do not do
Things that would likely worsen complexity or prompt sprawl.

## Part D — Recommendation on architecture
Specifically answer:
1. Is the current failure taxonomy sufficient?
2. Is there evidence for a missing 7th or 8th failure mode?
3. Is structured self-declaration ready to replace current injection logic?

---

## Important Instructions
- Do not optimize for making the answer longer.
- Do not reward broadness when a narrow answer is appropriate.
- Do not label something a “guideline gap” if it is actually:
  - a contraindication case
  - a negative indication case
  - a selective indication case
  - a broad but usable recommendation
- Prioritize **clinical trust** over stylistic completeness.
- If a safety-critical modifier is buried after a standard regimen, score that failure at least **S3**.

---

## End Goal
The end goal is a concrete release-level action plan that improves the system generically, not another round of case-by-case patches.

