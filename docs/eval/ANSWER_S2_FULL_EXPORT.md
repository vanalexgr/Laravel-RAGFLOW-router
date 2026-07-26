# Full export — S2: antithrombotic therapy after vein below-knee bypass

Live run through the current gate v2 code with Cohere reranking restored.
**Unjudged, single run.** This stage is measurably nondeterministic, so a rerun may differ.

Scenario: `batch_s2_post_vein_bypass_antithrombotics`

## Question as asked

> What is the recommended antithrombotic therapy after lower limb revascularization for peripheral arterial disease? Clarifications: vein BK bypass, rest pain pre-op, no high bleeding risk.

## Gate state

- mode: knowledge | iterations: 0 | escalate: False
- routed guidelines: clti, antithrombotic_therapy
- evidence coverage: **interaction_gap**
- lint violations: none
- unknowns flagged: none
- clarifying questions raised: 0

### Patient model as extracted by Orient

```json
{
  "lesion": "lower limb peripheral arterial disease with rest pain",
  "prior_interventions": [
    "vein below-knee bypass"
  ],
  "symptom_status": "rest pain preoperative",
  "timing": "postoperative",
  "comorbidities": [],
  "medications": [],
  "imaging": "unknown",
  "fitness": "unknown",
  "demographics": "unknown",
  "other_findings": [
    "no high bleeding risk"
  ]
}
```

### Queries actually issued

**clti**

1. `Clinical question: What is the recommended antithrombotic therapy after lower limb revascularization using a vein below-knee bypass in a patient with peripheral arterial disease and preoperative rest pain who has no high bleeding risk?
Patient context: Lesion: lower limb peripheral arterial disease with rest pain. Other findings: no high bleeding risk. Symptoms: rest pain preoperative. Timing: postoperative. Prior interventions: vein below-knee bypass.
Clinical concepts: antithrombotic therapy, postoperative, below-knee vein bypass, peripheral arterial disease, critical limb-threatening ischemia, rest pain, antiplatelet therapy, anticoagulation, bleeding risk assessment, bypass graft type, postoperative management, lower limb revascularization, vein BK bypass, no high bleeding risk
Anatomical anchors: graft, limb, antithrombotic`
2. `recommended antithrombotic therapy after lower limb vein below-knee bypass for peripheral arterial disease with critical limb-threatening ischemia and preoperative rest pain, in patients without high bleeding risk`

**antithrombotic_therapy**

1. `Clinical question: What is the recommended antithrombotic therapy after lower limb revascularization using a vein below-knee bypass in a patient with peripheral arterial disease and preoperative rest pain who has no high bleeding risk?
Patient context: Lesion: lower limb peripheral arterial disease with rest pain. Other findings: no high bleeding risk. Symptoms: rest pain preoperative. Timing: postoperative. Prior interventions: vein below-knee bypass.
Clinical concepts: antithrombotic therapy, postoperative, below-knee vein bypass, peripheral arterial disease, critical limb-threatening ischemia, rest pain, antiplatelet therapy, anticoagulation, bleeding risk assessment, bypass graft type, postoperative management, lower limb revascularization, vein BK bypass, no high bleeding risk
Anatomical anchors: graft, limb, antithrombotic`

### Retrieval per branch

| guideline | attempt | citations | avail | narrative avail | cit query chars | top_k |
|---|---:|---:|---:|---:|---:|---:|
| clti | 1 | 7 | 7 | 4 | 95 | 12 |
| clti | 2 | 7 | 7 | 2 | 100 | 24 |
| antithrombotic_therapy | 1 | 8 | 8 | 4 | 95 | 12 |

---

## ANSWER

### ESVS-grounded frame

From the retrieved ESVS text: 
- Patients undergoing infrainguinal bypass with autologous vein who are not at high risk of bleeding may be considered for vitamin K antagonists to improve graft patency (Recommendation 40, Class Iib, Level A). 
- If a vitamin K antagonist is used to improve vein graft patency, aim for an INR of 2.0–3.0 with a target of 2.5 (Recommendation 41, Class IIa, Level C). 
- Single antiplatelet therapy is recommended as standard long-term medical therapy and should be continued in patients after vein bypass (narrative statements). 
- As an alternative for infrainguinal endarterectomy or bypass (autologous vein or prosthetic) in patients who are not at high bleeding risk, consider aspirin (75–100 mg daily) combined with rivaroxaban 2.5 mg twice daily to reduce secondary cardiovascular and major adverse limb events (Recommendation 38, Class IIa, Level B).

### Interpretive frame (explicitly flagged non-ESVS)

Non-ESVS interpretation (clinical reasoning beyond the retrieved guideline text):
For a patient who had a below‑knee autologous vein bypass and is not at high bleeding risk, the guideline-supported options are: 
- Consider postoperative oral anticoagulation with a VKA aimed at INR 2.0–3.0 (target ≈2.5) to improve vein graft patency; or 
- Consider antithrombotic therapy with low‑dose rivaroxaban plus aspirin to reduce cardiovascular and limb events. 
In practice, many teams continue at least single antiplatelet therapy long term; selection between VKA versus aspirin+rivaroxaban should be individualized based on graft risk (eg, conduit quality, runoff), patient adherence, need for monitoring, and bleeding risk. If anticoagulation with a VKA is chosen, arrange INR monitoring and graft surveillance. Discuss benefits and bleeding risks with the patient.

### As delivered to the clinician

## ESVS-grounded answer

From the retrieved ESVS text: 
- Patients undergoing infrainguinal bypass with autologous vein who are not at high risk of bleeding may be considered for vitamin K antagonists to improve graft patency (Recommendation 40, Class Iib, Level A). 
- If a vitamin K antagonist is used to improve vein graft patency, aim for an INR of 2.0–3.0 with a target of 2.5 (Recommendation 41, Class IIa, Level C). 
- Single antiplatelet therapy is recommended as standard long-term medical therapy and should be continued in patients after vein bypass (narrative statements). 
- As an alternative for infrainguinal endarterectomy or bypass (autologous vein or prosthetic) in patients who are not at high bleeding risk, consider aspirin (75–100 mg daily) combined with rivaroxaban 2.5 mg twice daily to reduce secondary cardiovascular and major adverse limb events (Recommendation 38, Class IIa, Level B).

## Interpretation

Non-ESVS interpretation (clinical reasoning beyond the retrieved guideline text):
For a patient who had a below‑knee autologous vein bypass and is not at high bleeding risk, the guideline-supported options are: 
- Consider postoperative oral anticoagulation with a VKA aimed at INR 2.0–3.0 (target ≈2.5) to improve vein graft patency; or 
- Consider antithrombotic therapy with low‑dose rivaroxaban plus aspirin to reduce cardiovascular and limb events. 
In practice, many teams continue at least single antiplatelet therapy long term; selection between VKA versus aspirin+rivaroxaban should be individualized based on graft risk (eg, conduit quality, runoff), patient adherence, need for monitoring, and bleeding risk. If anticoagulation with a VKA is chosen, arrange INR monitoring and graft surveillance. Discuss benefits and bleeding risks with the patient.

---

## CITATIONS — every snippet the answering stage could see

`bucket: citation` = a verbatim recommendation row. `bucket: narrative` = guideline prose.
Similarity is RAGFlow's composite score x100, NOT a percentage, and is only comparable within a bucket.

### clti — 6 snippets (4 from the recommendations dataset)

**rank 1** | bucket `citation` | similarity 50.1 | rec `6.35` | class `2` | level `C`

source: `Global Vascular Guidelines on CLTI Management`

> [Recommendation 6.35 | Class 2 | Level C | clti]
> Consider endovascular revascularization for high-risk patients with advanced limb threat (eg, WIf I stage 4) and moderate ischemia (eg, WIf I ischemia grade 1) if the wound progresses or fails to reduce in size by 50% within 4 weeks despite appropriate infection control, wound care, and offloading, when technically feasible.

**rank 2** | bucket `citation` | similarity 49.4 | rec `6.11` | class `1` | level `C`

source: `Global Vascular Guidelines on CLTI Management`

> [Recommendation 6.11 | Class 1 | Level C | clti]
> Offer revascularization to all average-risk patients with advanced limb-threatening conditions (eg, WIf I stage 4) and significant perfusion deficits (eg, WIf I ischemia grades 2 and 3).

**rank 3** | bucket `citation` | similarity 48.6 | rec `6.36` | class `2` | level `C`

source: `Global Vascular Guidelines on CLTI Management`

> [Recommendation 6.36 | Class 2 | Level C | clti]
> Consider endovascular revascularization for high-risk patients with intermediate limb threat (eg, WIf I stages 2 and 3) and moderate ischemia (eg, WIf I ischemia grade 1) if the wound progresses or fails to reduce in size by 50% within 4 weeks despite appropriate infection control, wound care, and offloading, when technically feasible.

**rank 4** | bucket `citation` | similarity 47.9 | rec `6.34` | class `2` | level `C`

source: `Global Vascular Guidelines on CLTI Management`

> [Recommendation 6.34 | Class 2 | Level C | clti]
> Consider endovascular revascularization for high-risk patients with intermediate limb threat (eg, WIf Istages 2 and 3) and significant perfusion deficits (eg, WIf I ischemia grades 2 and 3).

**rank 5** | bucket `narrative` | similarity 48.3 | rec `None` | class `None` | level `None`

source: `Chronic Limb-Threatening Ischemia`

> Recommendation 6.32 | Unchanged
> In average-risk CLTI patients with infrainguinal disease, base decisions of endovascular intervention vs open surgical bypass on the severity of limb threat (eg, WIf I), the anatomic pattern of disease (eg, GLASS), and the availability of autologous vein.
> Class | Level | References
> 1 (Strong) | C (Low) | Almasri et al.

**rank 6** | bucket `narrative` | similarity 44.6 | rec `None` | class `None` | level `None`

source: `Chronic Limb-Threatening Ischemia`

> ...tive DUS was performed at 1 month, 4 months, and 7 months and then twice yearly. Multivariate analysis showed that low graft flow (midgraft velocity 300 cm/s in the inflow iliac artery and a midgraft velocity

### antithrombotic_therapy — 6 snippets (6 from the recommendations dataset)

**rank 1** | bucket `citation` | similarity 42.3 | rec `30` | class `I` | level `C`

source: `Antithrombotic Therapy for Vascular Diseases`

> [Recommendation 30 | Class I | Level C | antithrombotic_therapy]
> Patients with acute limb ischaemia planned for expedited revascularisation are recommended to have immediate intravenous unfractionated heparin to reduce the risk of thrombus propagation.

**rank 2** | bucket `citation` | similarity 18.2 | rec `41` | class `Iia` | level `C`

source: `Antithrombotic Therapy for Vascular Diseases`

> [Recommendation 41 | Class Iia | Level C | antithrombotic_therapy]
> Patients taking a vitamin K antagonist to improve patency of an infrainguinal vein bypass graft should have an international normalised ratio of 2.0 – 3.0 with a target of 2.5.

**rank 3** | bucket `citation` | similarity 16.8 | rec `43` | class `Iib` | level `C`

source: `Antithrombotic Therapy for Vascular Diseases`

> [Recommendation 43 | Class Iib | Level C | antithrombotic_therapy]
> Patients at high risk of bleeding undergoing infrainguinal bypass using an autologous vein or prosthetic conduit for lower extremity arterial disease may be considered for single antiplatelet therapy to improve graft patency.

**rank 4** | bucket `citation` | similarity 16.3 | rec `40` | class `Iib` | level `A`

source: `Antithrombotic Therapy for Vascular Diseases`

> [Recommendation 40 | Class Iib | Level A | antithrombotic_therapy]
> Patients undergoing infrainguinal bypass with autologous vein for lower extremity arterial disease who are not at high risk of bleeding may be considered for vitamin K antagonists to improve graft patency.

**rank 5** | bucket `citation` | similarity 16.1 | rec `38` | class `Ila` | level `B`

source: `Antithrombotic Therapy for Vascular Diseases`

> [Recommendation 38 | Class Ila | Level B | antithrombotic_therapy]
> Patients undergoing infrainguinal endarterectomy or bypass using autologous vein or prosthetic conduit for lower extremity arterial disease who are not at high risk of bleeding should be considered for aspirin (75 – 100 mg once daily) in combination with rivaroxaban (2.5 mg twice daily) to reduce the risk of secondary cardiovascular and major adverse limb events.

**rank 6** | bucket `citation` | similarity 15.9 | rec `39` | class `III` | level `C`

source: `Antithrombotic Therapy for Vascular Diseases`

> [Recommendation 39 | Class III | Level C | antithrombotic_therapy]
> If clopidogrel (75 mg) is added in exceptional circumstances to aspirin (75 – 100 mg once daily) in combination with rivaroxaban (2.5 mg twice daily) for patients undergoing infrainguinal bypass surgery using autologous vein or prosthetic conduit for lower extremity arterial disease who are not at high risk of bleeding, it is not recommended for longer than 30 days as the bleeding risk is likely to outweigh the benefit.

---

## Per-guideline pathway assessments

```json
[
  {
    "guideline_key": "clti",
    "relevant": true,
    "coverage": "partial",
    "better_query": "antithrombotic therapy recommendation after lower limb vein below-knee bypass in patients with critical limb-threatening ischemia and preoperative rest pain without high bleeding risk",
    "covered_components": [
      "antithrombotic therapy",
      "vein bypass graft",
      "critical limb-threatening ischemia",
      "rest pain",
      "bleeding risk"
    ],
    "interaction_gap": true,
    "pathways": [
      {
        "pathway": "Single antiplatelet therapy",
        "guideline_basis": "Limited evidence; recommended standard for long-term PAD management after vein bypass grafting for CLTI",
        "discriminating_variables": [
          "type of graft (vein)",
          "CLTI status",
          "no high bleeding risk"
        ]
      },
      {
        "pathway": "Consider warfarin therapy",
        "guideline_basis": "May be considered in patients with high-risk vein grafts (eg, spliced vein conduit, poor runoff) who are not at increased bleeding risk",
        "discriminating_variables": [
          "high-risk vein graft features",
          "no high bleeding risk"
        ]
      }
    ],
    "queries_tried": [
      "Clinical question: What is the recommended antithrombotic therapy after lower limb revascularization using a vein below-knee bypass in a patient with peripheral arterial disease and preoperative rest pain who has no high bleeding risk?\nPatient context: Lesion: lower limb peripheral arterial disease with rest pain. Other findings: no high bleeding risk. Symptoms: rest pain preoperative. Timing: postoperative. Prior interventions: vein below-knee bypass.\nClinical concepts: antithrombotic therapy, postoperative, below-knee vein bypass, peripheral arterial disease, critical limb-threatening ischemia, rest pain, antiplatelet therapy, anticoagulation, bleeding risk assessment, bypass graft type, postoperative management, lower limb revascularization, vein BK bypass, no high bleeding risk\nAnatomical anchors: graft, limb, antithrombotic",
      "recommended antithrombotic therapy after lower limb vein below-knee bypass for peripheral arterial disease with critical limb-threatening ischemia and preoperative rest pain, in patients without high bleeding risk"
    ]
  },
  {
    "relevant": true,
    "coverage": "covered",
    "better_query": "Antithrombotic therapy recommendations after infrainguinal bypass using autologous vein graft in peripheral arterial disease patients without high bleeding risk, postoperative context, including adjunct antiplatelet or anticoagulant strategies.",
    "guideline_key": "antithrombotic_therapy",
    "pathways": [
      {
        "pathway": "Vitamin K antagonist therapy",
        "guideline_basis": "Recommendation 41 (Class IIa, Level C) targets INR 2.0 - 3.0 to improve patency of infrainguinal vein bypass graft",
        "discriminating_variables": [
          "infrainguinal vein bypass graft",
          "use of vitamin K antagonist",
          "target INR 2.0-3.0"
        ]
      },
      {
        "pathway": "Single antiplatelet therapy",
        "guideline_basis": "Recommendation 43 (Class IIb, Level C) considered for patients at high bleeding risk undergoing infrainguinal bypass with autologous vein or prosthetic conduit",
        "discriminating_variables": [
          "high bleeding risk",
          "infrainguinal bypass",
          "autologous vein or prosthetic conduit"
        ]
      },
      {
        "pathway": "Vitamin K antagonists for patients without high bleeding risk",
        "guideline_basis": "Recommendation 40 (Class IIb, Level A) vitamin K antagonists considered for patients undergoing infrainguinal bypass with autologous vein graft not at high bleeding risk",
        "discriminating_variables": [
          "infrainguinal bypass with autologous vein",
          "no high bleeding risk"
        ]
      },
      {
        "pathway": "Aspirin plus rivaroxaban combination therapy",
        "guideline_basis": "Recommendation 38 (Class IIa, Level B) aspirin 75-100 mg once daily combined with rivaroxaban 2.5 mg twice daily recommended for patients undergoing infrainguinal bypass using autologous vein or prosthetic conduit not at high bleeding risk to reduce cardiovascular and limb events",
        "discriminating_variables": [
          "infrainguinal bypass",
          "autologous vein or prosthetic conduit",
          "no high bleeding risk",
          "use of aspirin and rivaroxaban"
        ]
      },
      {
        "pathway": "Avoid prolonged triple therapy including clopidogrel",
        "guideline_basis": "Recommendation 39 (Class III, Level C) adding clopidogrel to aspirin and rivaroxaban not recommended beyond 30 days due to bleeding risk",
        "discriminating_variables": [
          "infrainguinal bypass",
          "aspirin plus rivaroxaban therapy",
          "use of clopidogrel beyond 30 days",
          "bleeding risk"
        ]
      }
    ],
    "interaction_gap": false,
    "queries_tried": [
      "Clinical question: What is the recommended antithrombotic therapy after lower limb revascularization using a vein below-knee bypass in a patient with peripheral arterial disease and preoperative rest pain who has no high bleeding risk?\nPatient context: Lesion: lower limb peripheral arterial disease with rest pain. Other findings: no high bleeding risk. Symptoms: rest pain preoperative. Timing: postoperative. Prior interventions: vein below-knee bypass.\nClinical concepts: antithrombotic therapy, postoperative, below-knee vein bypass, peripheral arterial disease, critical limb-threatening ischemia, rest pain, antiplatelet therapy, anticoagulation, bleeding risk assessment, bypass graft type, postoperative management, lower limb revascularization, vein BK bypass, no high bleeding risk\nAnatomical anchors: graft, limb, antithrombotic"
    ]
  }
]
```