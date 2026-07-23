# Benchmark — AAA evolving-context (the gate v2 must-beat)

Source: a ChatGPT critique of the live app (shared 2026-07-23) that exposed the two failure modes v2
exists to fix. This is the authoritative transcript + expectations for the `aaa_evolving_context`
eval scenario. Correct source throughout = **ESVS 2024 Abdominal Aorto-Iliac Artery Aneurysm** guideline
(Wanhainen 2024, EJVES 67:192–331); guideline key `abdominal_aortic_aneurysm`.

## Transcript (3 turns, cumulative state)

**Turn 1 (initial):**
> A 74-year-old man has an asymptomatic 5.8 cm abdominal aortic aneurysm discovered on ultrasound.
> He is an active smoker with hypertension and dyslipidaemia. What management would you recommend?

**Turn 2 (follow-up 1):**
> CTA now shows that this is a juxtarenal aneurysm with an inadequate infrarenal neck for standard EVAR.
> His eGFR is 28 mL/min/1.73 m². How does this change your recommendation?

**Turn 3 (follow-up 2):**
> He also has severe COPD, reduced functional capacity, and significant frailty, but remains independent
> in basic daily activities. Should he still undergo repair, and how would you choose between open repair,
> FEVAR, and conservative management?

## What it tests

Cumulative patient-state accumulation across turns, and stable routing to the AAA guideline. The app
must **rebuild the cumulative patient model before retrieval + synthesis** each turn.

## Observed failure (baseline — what v2 must beat)

ChatGPT scores: T1 6/10 (reasonable but overconfident/unfocused); **T2 1/10** (failed to incorporate the
new anatomy + renal function — kept calling the aneurysm "infrarenal" after T2 said juxtarenal);
T3 4/10 (recognised frailty, lost earlier context). Two failures:
1. **State loss** — cumulative patient state not rebuilt before retrieval/synthesis.
2. **Router drift** — T3 retrieved from the **Descending Thoracic Aorta** guideline instead of AAA.

## Expected behavior (eval expectations, cumulative `must_include_facts`)

| Turn | mode | same_case | guideline_keys | must_include (cumulative) | must_not_include | evidence_status.coverage |
|---|---|---|---|---|---|---|
| T1 | case_new | (n/a) | [`abdominal_aortic_aneurysm`] | asymptomatic 5.8 cm AAA; exceeds ~5.5 cm men repair threshold; elective repair / fast-track referral; fitness assessment; smoking-cessation + BMT | — | covered |
| T2 | case_followup_substantive | true | [`abdominal_aortic_aneurysm`] | **juxtarenal**; inadequate infrarenal neck → **standard EVAR unsuitable**; FEVAR / open options; **eGFR 28** contrast-nephropathy caution | **infrarenal** (stale, superseded) | covered / partial_principles |
| T3 | case_followup_substantive | true | [`abdominal_aortic_aneurysm`] **(HARD: must NOT route thoracic)** | frailty/COPD ↑ open-repair risk; FEVAR less physiological insult; conservative/surveillance if unfit; shared decision weighing all three | thoracic-aorta content | covered / partial_principles |

Hard bars for the launch gate: **T2 must reflect juxtarenal + eGFR 28** (state completeness);
**T3 must route AAA, not thoracic** (routing validity).
