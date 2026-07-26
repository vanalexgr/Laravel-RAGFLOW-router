# Clinical Decision-Composition Contract

## 1. Structure: Forcing Decisiveness

The goal is to force the model through a clinical synthesis pathway that explicitly separates the "guideline default" from "patient complexity." If the model is allowed to generate free-text prose, it will use "MDT review" to smooth over the cognitive leap between standard guidelines and complex patients.

**The Required Output Schema (Strict JSON/Structured Output):**

1.  `baseline_pathway`: (String) What is the standard ESVS recommendation for the primary pathology, assuming no comorbidities?
2.  `patient_deviations`: (List of Strings) Explicitly list the patient factors that conflict with or modify the baseline pathway (e.g., "Recent GI bleeding", "ITP"). Must be `[]` if none.
3.  `actionable_plan`: (Object)
    *   `timing`: (String) Specific timeframe (e.g., "< 14 days", "Routine"). Cannot be "As soon as possible".
    *   `pharmacotherapy_regimen`: (String) Explicit drug, dose, and duration (e.g., "Aspirin 75mg OD + Rivaroxaban 2.5mg BD").
    *   `what_not_to_do`: (List of Strings) Explicit contraindications. **This is critical for preventing unsafe default assumptions** (e.g., "DO NOT prescribe routine LMWH bridging", "DO NOT prescribe long-term triple therapy").
4.  `escalation_and_reassessment`: (Object)
    *   `trigger_event`: (String) What specific clinical event requires a change to this plan? (e.g., "Recurrent bleeding", "Platelets < 50").
    *   `action_on_trigger`: (String) What to do if the trigger occurs (e.g., "Stop Rivaroxaban, refer to Haematology").

**Critique of your original list:**
*   "Clinical conclusion" and "Committed recommendation" overlap too much and invite generic summarizing. By splitting the recommendation into `timing`, `pharmacotherapy`, and `what_not_to_do`, you force concrete decisions.
*   "Residual uncertainty" is a trap; it gives the model permission to be non-committal. Replace it with explicit `escalation_and_reassessment` triggers.

**Failure Mode of Forced Fields:**
If a field is forced (e.g., `pharmacotherapy_regimen`) but the evidence genuinely contains *no* pharmacological guidance, a naive model will hallucinate a standard drug.
*   *Mitigation:* The schema must allow an explicit `EVIDENCE_ABSENT` enum for these fields, but if used, the model *must* populate the `what_not_to_do` field with standard harms to avoid, preventing total silence on a domain.

---

## 2. The Deferral Boundary: MDT as a Destination, Not an Evasion

"MDT review" is currently being used as an evasion tactic for interaction gaps. It is only a valid clinical answer when arbitration is required beyond the scope of a single guideline.

**Encoding the Distinction:**
You must ban the terms "MDT", "individualise", and "local protocols" from the `actionable_plan` unless they are accompanied by a specific `deferral_justification` code.

Valid Deferral Codes:
1.  `GUIDELINE_MANDATED`: The ESVS explicitly states "complex anatomy requires MDT".
2.  `UNRESOLVABLE_CONTRAINDICATION`: The primary treatment is strictly contraindicated by a comorbidity, and no secondary option is provided in the retrieved text.
3.  `MULTISPECIALTY_CONFLICT`: e.g., ESVS says operate, ESC says do not stop anticoagulation, creating a direct clash.

**The Validator:**
A simple post-generation regex/rule check. If `actionable_plan` contains "MDT", "individualise", or "multidisciplinary", it *must* check if a valid `deferral_justification` is present. If not, the output is **REJECTED** and sent back for revision with the prompt: *"You recommended an MDT but did not provide a valid clinical conflict. Synthesize a default plan based on available evidence, and state what specific factors the MDT needs to arbitrate."*

---

## 3. Safety Checklists: Domain-Specific Guardrails

Should you implement deterministic completeness checks? **Yes, but only targeted ones.** Building a massive hardcoded checklist for 14 guidelines is a maintenance nightmare and will fail when guidelines update.

Instead, build **Trigger-Based Domain Checklists**. 

When the query involves "Carotid" + "Anticoagulation", trigger a specific validator for that domain.

**The Validator Logic:**
1.  **Extract:** Run a fast, cheap model (or regex on the structured output) to extract binary states: `Bridging_Addressed (Y/N)?`, `Triple_Therapy_Recommended (Y/N)?`, `Urgency_Specified (Y/N)?`.
2.  **Evaluate against Rules:**
    *   If `Condition = Carotid + DOAC` AND `Bridging_Addressed = N`, **BLOCK/REVISE**.
    *   If `Triple_Therapy_Recommended = Y`, **BLOCK/REVISE** (unless explicitly justified).

**What NOT to build:** Do not build a universal checklist that tries to validate every ESVS recommendation. Maintain only a small set of "Never Events" (e.g., missing antiplatelet after bypass, missing bridging plan for DOACs, missed urgency on symptomatic carotids). 

---

## 4. Determinism: Variance Reduction

Identical evidence producing different grades implies high variance in the *reasoning trajectory* of the model. 

**What actually reduces variance:**
1.  **Structured Output (JSON Schema):** This is the single biggest factor. Forcing the model to output `timing: "< 14 days"` instead of letting it write a paragraph prevents the model from getting distracted by its own generated prose.
2.  **Low Temperature (T=0.0):** Essential for clinical decision support.
3.  **Prompt Decomposition:** Do not ask for the answer in one go. If the model generates the `baseline_pathway` first, it conditions its subsequent tokens for the `actionable_plan` on that baseline, drastically reducing the chance it "forgets" the primary guideline mid-sentence.

**What merely *looks* stricter:**
*   Adding "Be precise, deterministic, and definitive" to the system prompt. LLMs ignore tone modifiers when faced with complex evidence. 
*   Using a generic "Critic" model to "check if the answer is good". 

---

## 5. The Unsafe-Advice Case: Catching the Blind Spots

In Case 3, the model recommended unsafe long-term triple therapy, and the internal Critic missed it. This happens because "General Critics" use the same pre-training weights and RLHF alignments as the generator—they are naturally sycophantic and hesitant to flag something as "unsafe" if it sounds plausible.

**How to catch this:**
You need a **Red Team / Adversarial Validator**, not a generic Critic.

1.  **Rule-Based Contraindication Matrix (Best for Drugs):** LLMs are bad at strict deterministic logic like drug interactions. If the structured output lists `[Aspirin, Clopidogrel, Rivaroxaban]`, a simple Python script checking against a known "High-Risk Combinations" table will catch this 100% of the time, whereas an LLM Critic might try to justify it.
2.  **The Adversarial LLM Persona:** If you must use an LLM, prompt it adversarially.
    *   *Bad Critic:* "Review this plan for accuracy and guideline adherence."
    *   *Good Critic:* "You are a Medical Malpractice Auditor. Your ONLY job is to find a reason why this plan could kill or severely harm the patient. Look specifically for dangerous drug combinations, missed bleeding risks, and inappropriate delays. If you find one, output FATAL_ERROR."

---

## 6. What to Measure: Decisiveness vs. Correctness

You must prove the model stopped evading without proving it started hallucinating.

**Metrics:**
1.  **The Deferral Rate (Decisiveness):** Percentage of outputs containing "MDT", "individualise", "consult local protocol", or "clinical judgement". This should drop dramatically.
2.  **Actionable Token Density (Decisiveness):** Percentage of `actionable_plan` fields that contain concrete timeframes (e.g., numbers like "14 days", "48 hours" rather than "urgent") and specific dosages. 
3.  **Interaction Gap Resolution (Correctness/Utility):** Create a golden dataset of 50 known "gap" cases (like ITP + Bypass). Measure how often the model synthesizes a combined plan vs. bailing out. 
4.  **Safety Violation Rate (Correctness):** Run the outputs through the Adversarial Validator/Contraindication Matrix. If decisiveness goes up but safety violations also go up, you are just rewarding confident hallucinations.

---

## Ranked Implementation Order

1.  **IMPLEMENT FIRST:** The Structured JSON Output Contract (`baseline`, `deviations`, `actionable_plan`, `what_not_to_do`). This solves 80% of the evasiveness and determinism issues instantly by forcing the generation trajectory. Set Temperature to 0.0.
2.  **IMPLEMENT SECOND:** The Deferral Boundary Regex. Ban "MDT" unless accompanied by a strict categorical justification. This breaks the model's favorite escape hatch.
3.  **IMPLEMENT THIRD:** The Rule-Based Contraindication Matrix for antithrombotics. Do not use an LLM for this; use a script that reads the `pharmacotherapy_regimen` field and flags triple therapy or missed bridging.
4.  **IMPLEMENT LAST (or never):** Massive guideline-specific completeness checklists. They are brittle. Rely on the structured output's `what_not_to_do` field to force the model to consider risks naturally.
