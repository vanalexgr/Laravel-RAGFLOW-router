# Agentic Clarification Gate — Prototype & Design Handover

**Status:** Research prototype. Lives on branch `claude/zealous-gates-f55caa`. **Not wired into the production request path.**
**Audience:** ISI engineering team taking this forward.
**Goal of this document:** explain *why* this exists, *what* the prototype does today, *how* to run it, and *how* to productionise it.

---

## 1. Why this exists

The clarification gate is the single highest-leverage step in the pipeline. Before any evidence is retrieved, it decides **what to ask the clinician**. Ask the wrong thing (or nothing) and every downstream stage — retrieval, reranking, synthesis — works from an incomplete case.

### The current production gate is a closed whitelist

The live gate is `app/Services/PreRetrievalService.php`. Its "SOFT WARN" rules ask a clarification question only when one of ~6 **hardcoded critical parameters** is missing:

- symptom status (symptomatic/asymptomatic)
- haemodynamic stability
- time from neurological event
- rupture vs intact
- aneurysm diameter near threshold
- ABI / toe pressure

This works, but it has a structural ceiling: **it can only ask what is on the list.** It cannot reason about a case it has never been templated for. Real vascular decisions turn on a long tail of case-specific factors (plaque morphology, contralateral occlusion, cardiac fitness, hemispheric dominance, prior interventions, current antithrombotic therapy…) that no fixed list captures.

### The motivating failure

Case: *"65 yo, 65% right carotid stenosis, aphasia, left-handed."*

The current gate assumed the stenosis was **symptomatic** and asked three generic timing questions. But aphasia is usually a **left**-hemisphere (language) function. In a left-handed patient, language dominance may be atypical. So the decisive question is: *does the aphasia actually localise to the right hemisphere?* If yes → the right carotid is symptomatic → carotid intervention. If no → the right carotid is an **incidental, asymptomatic** finding → medical therapy. The whole management pathway flips on a question the whitelist cannot generate.

**The prototype's thesis:** replace *"check my list"* with *"reason like a senior vascular surgeon."*

---

## 2. The architecture: orient → probe → proceed

```
ORIENT   Build a structured patient model from the case.
         Enumerate the LIVE guideline decision pathways this case could follow.
             │
             ▼
PROBE    For each pathway, identify the unknowns that discriminate between them.
         Rank those unknowns by branch impact (value of information).
         Ask ONLY the 1–2 highest-impact questions a consultant would ask first.
             │
             ▼
PROCEED  Always able to answer now, with stated assumptions + a calibrated confidence.
         Open the gate only when a HIGH-impact unknown exists AND confidence < 0.70.
```

The clinical intuition being encoded: **a junior asks everything; a consultant asks the one question whose answer changes the plan.** That "which question moves the decision" judgment is *value-of-information* ranking — exactly what a fixed whitelist cannot do.

### Decision rule

The gate asks a question only when **both** hold:
1. There is at least one unknown with `branch_impact = high` (it would flip the first-line decision between pathways), **and**
2. The model's calibrated `confidence` that its provisional answer would not change is **below 0.70**.

Otherwise it proceeds with an answer and explicit assumptions. It never refuses to opine.

---

## 3. What the prototype does today

The intelligence is one reasoning LLM call that returns a structured JSON trace. Key fields:

| Field | Meaning |
|---|---|
| `patient_model` | structured slots: lesion, symptom_status, timing, fitness, imaging, comorbidities, medications, prior_interventions, measurements |
| `differential` | the candidate clinical framings |
| `decision_pathways` | each live pathway + the guideline rule that makes it apply + its discriminating variables |
| `unknowns` | missing facts, each tagged `branch_impact` = high/medium/low and `currently_known` |
| `questions` | the top 1–2 highest-impact questions, phrased as a consultant would ask |
| `confidence` | calibrated probability the provisional answer would not change |
| `provisional_answer` + `assumptions` | the proceed path — always populated |

### Two deliberate design decisions

1. **It gets its own budget.** The gate makes a dedicated Azure call with a **45s timeout and 1400 tokens**, *not* the 10s-capped shared `LlmClient`. Rationale: this is the most valuable call in the pipeline, so it is allowed to think. (The first test actually failed against the 10s shared client — evidence the step needs its own budget.)

2. **It has a grounding seam.** `AgenticGateService::probe()` accepts a `$guidelineContext` parameter. Empty today (the model reasons from its own ESVS knowledge). In production this is where retrieved recommendation snippets are injected so pathways are grounded in the *actual* guideline — see §5.

### Files

| File | Role |
|---|---|
| `app/Services/AgenticGate/AgenticGateService.php` | the orient→probe→proceed service |
| `app/Console/Commands/GateProbeCommand.php` | side-by-side comparison harness |

---

## 4. How to run it

There is a CLI harness that runs the **current gate** and the **agentic gate** on the same case, side by side:

```bash
php artisan gate:probe "65 yo, 65% right carotid stenosis, aphasia, left handed"
php artisan gate:probe "<case>" --history="prior turn" --history="another turn"
php artisan gate:probe "<case>" --json          # dump the raw agentic JSON
```

Requires a configured Azure OpenAI deployment (same config the rest of the app uses: `config/prism.php` → `prism.providers.azure.*`). Run it where those credentials exist (the deployed VM), not on a bare dev box.

### Observed result on the carotid case

| | Current gate | Agentic gate |
|---|---|---|
| Assumption | Assumed "symptomatic" | **Questioned** it |
| Questions | 3 generic timing questions | Hemispheric-**concordance** question first |
| Extra output | none | patient model + 3 decision pathways + ranked unknowns + provisional answer + confidence 0.52 |
| Latency | ~6 s | ~14 s |

The agentic gate surfaced the lateralisation issue (right carotid possibly incidental/asymptomatic) that the whitelist structurally could not.

---

## 5. The key next step: guideline-grounding (the main bet)

Today the decision pathways come from the model's parametric memory. In production they should come from the **actual ESVS recommendation nodes**, injected via `$guidelineContext`:

```
ORIENT → cheap routing + light retrieval → real recommendation snippets
       → inject into $guidelineContext
PROBE  → "what does ESVS Rec X literally require in order to fire?"
```

The questions then derive from the guideline's **own decision thresholds** (e.g. the carotid recommendations branch on *<14 days* and *symptomatic 50–99%*), not from a hand-maintained list and not from model memory. Two payoffs:

- **Self-updating** — when a guideline changes, the questions change automatically.
- **Grounded** — far less hallucination; every pathway traces to a retrieved recommendation.

Run the orientation retrieval concurrently with the reasoning pass so latency stays flat. The routing logic already exists (`PreRetrievalPlannerService` / `GuidelineRouterService`) and can be reused for the orient step.

---

## 6. Supporting pillars (full vision)

- **Persistent patient model** — accumulate the `patient_model` slots across turns as an explicit, auditable state object instead of re-deriving from raw text each turn. Naturally prevents re-asking answered questions.
- **Bounded iteration** — allow up to two probing rounds, but always keep a proceed-with-assumptions answer available.
- **Calibrated confidence** — open the gate only when the expected probability of *flipping the recommendation* is high, not for nice-to-know facts.
- **Consider a reasoning-tuned model** — the current call uses the standard chat deployment. A reasoning-configured model with a hidden reasoning pass is worth evaluating specifically for this step.

---

## 7. Suggested roadmap

| Phase | Description | State |
|---|---|---|
| 1 | Reasoning core (orient→probe→proceed, single call) | ✅ prototype (this branch) |
| 2 | **Guideline-grounding** via `$guidelineContext` + orientation retrieval | ← recommended next |
| 3 | Persistent patient-model state across turns | planned |
| 4 | **Shadow mode** — run alongside the current gate on real chats, log both, do not change behaviour | planned |
| 5 | Cutover if question quality wins on agreement metrics | planned |

This sequencing mirrors the merged-planner rollout already used in this codebase: build → shadow against the incumbent → cut over only on proven agreement. `PreRetrievalService` stays as the fallback throughout.

---

## 8. How to wire it in when ready

The prototype is intentionally standalone. To integrate:

1. Resolve `AgenticGateService` with the guideline key map (see `GateProbeCommand::handle()` for the construction pattern).
2. In the OpenWebUI adapter's pre-retrieval step (currently calls `/api/v1/pre-retrieval` → `PreRetrievalService`), add a parallel/shadow call to the agentic gate.
3. Render `questions` into the existing "❓ To Sharpen" checkpoint block, and carry `patient_model` forward as same-case state.
4. Keep `PreRetrievalService` as the fallback path (LLM failure → safe defaults), exactly as today.

---

## 9. Caveats

- Prototype only; no test coverage yet, no shadow logging, not in the request path.
- Latency is roughly 2× the current gate (~14 s vs ~6 s) — acceptable for a pre-retrieval reasoning step but must be measured under load, and mitigated by running orientation retrieval concurrently.
- Grounding (§5) is the difference between "reasons well from memory" and "reasons from the actual guideline." Until Phase 2 lands, treat the pathways as model-knowledge, not guideline-verified.
