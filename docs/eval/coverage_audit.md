# Run 6 / Run 7 coverage audit

Date: 2026-07-26  
Scope: every scenario/turn whose committed Run 6 or Run 7 artifact reports
`evidence_status.coverage` as `not_covered` or `retrieval_uncertain`.

Sources:

- `docs/eval/run6_real_external_20260725_120653.json`
- `docs/eval/run7_real_external_20260725_220026.json`

There are **10 adjudication rows**. No artifact row used `retrieval_uncertain`; all entries entered the
audit through a `not_covered` verdict in at least one run.

## Important artifact limitation

The committed artifacts preserve the exact core question, all `queries_tried`, the Pathway assessment,
and retrieval-stage snippet counts, but **do not persist the raw retrieved snippet text or ranking**.
For every audited `not_covered` result, `output.pathways[*].pathways` is empty, so there is not even a
model-extracted `guideline_basis` excerpt to substitute safely. The “retrieval evidence” column below
therefore reports the snippet observations preserved in `stage_trace`; it does not fabricate top
snippets. Clinician adjudication of the actual top text requires a replay or a future artifact that
persists `snippet_digests`.

Counts are the sum of `snippet_count` across retrieval attempts/iterations, not unique chunks.
“Run 7 found a Run 6 miss” is asserted only for a deterministic zero-to-nonzero change. Other count
increases cannot establish that without the missing identities/text.

## Review rows

| Scenario / turn | Core question | Queries tried | Verdict and retrieval evidence | Run 7 vs Run 6 |
|---|---|---|---|---|
| `aaa_evolving_context:3` | Severe COPD, reduced functional capacity, and frailty: repair or no repair, and open repair vs FEVAR vs conservative management? | R6: `abdominal_aortic_aneurysm` ×2 — decision-at-issue/patient-model query, then a focused juxtarenal AAA fitness and modality query. R7: same key ×1 — English clinical question + accumulated patient context, concepts, and aorta anchors. | R6 `not_covered`, 12 snippet observations. R7 `covered`, 20. Raw top snippets absent. | Coverage improved and more evidence was returned, but a specific newly found item is **not determinable** from the artifacts. |
| `adversarial_case_switch_chimera:2` | “She is functionally independent and the event was non-disabling” in the switched carotid case. | R6: `carotid_vertebral` ×1 — decision-at-issue + partial patient model + Critic issue. R7: same key ×2 — English carotid/stroke question with merged case context, then a focused eligibility/timing retry. | R6 `not_covered`, **0** snippets. R7 `interaction_gap`, 11. Raw top snippets absent. | **Yes: Run 7 retrieved evidence where Run 6 retrieved none.** |
| `adversarial_duplicate_delivery:1` | Duplicate-tagged CTA showing a 5.9 cm juxtarenal AAA. | R6: `abdominal_aortic_aneurysm` ×2 — raw decision-at-issue query, then “Management of 5.9 cm juxtarenal abdominal aortic aneurysm according to ESVS guidelines.” R7: same key ×1 — English clinical question with patient context, AAA concepts, and aorta anchor. | R6 `not_covered`, 1 snippet observation. R7 `covered`, 20. Raw top snippets absent. | Strong retrieval/coverage increase, but a specific Run 6 miss is **not determinable**. |
| `adversarial_duplicate_delivery:2` | The identical duplicate-tagged 5.9 cm juxtarenal AAA message. | R6: `abdominal_aortic_aneurysm` ×2 — decision-at-issue with merged lesion/imaging state, then a size/anatomy/fitness retry. R7: same key ×1 — English clinical question with merged state and aorta anchor. | R6 `not_covered`, **0** snippets. R7 `covered`, 10. Raw top snippets absent. | **Yes: Run 7 retrieved evidence where Run 6 retrieved none.** |
| `adversarial_retrieval_trap:1` | Infrarenal abdominal aortic mural thrombus with distal embolisation: which ESVS pathway should ground assessment? | R6: `abdominal_aortic_aneurysm` ×2 — decision-at-issue, then an ESVS mural-thrombus management retry. R7: same key ×2 — English clinical question with thrombus/embolisation concepts and aorta/limb/thrombus anchors, then a focused pathway retry. | R6 `not_covered`, 10 snippet observations. R7 `partial_principles`, 18. Raw top snippets absent. | Coverage improved, but a specific newly found item is **not determinable**. |
| `batch_c2_brachial_vein_compression:1` | Tumour compressing the brachial vein, symptomatic swelling, no thrombosis: anticoagulate? | R6: `venous_thrombosis` ×1 and `antithrombotic_therapy` ×1 — decision-at-issue queries. R7: both keys ×2 — English no-thrombosis/compression query plus focused anticoagulation retries. | R6 `interaction_gap`, 31 snippet observations. R7 `not_covered`, 51. Raw top snippets absent. | **Regressed to absence despite more retrieval.** This points to classification/reasoning or evidence relevance, not starvation. |
| `batch_c3_carotid_near_occlusion_criteria:1` | Diagnostic criteria for carotid near-occlusion. | R6: `carotid_vertebral` ×2 — decision-at-issue, then “Definition and diagnostic criteria of carotid near-occlusion.” R7: same key ×2 — English criteria/imaging query plus a focused ESVS diagnostic-criteria retry. | R6 `not_covered`, 6 snippet observations. R7 `not_covered`, 7. Raw top snippets absent. | No material coverage correction is demonstrable. **Priority clinician review:** repeated absence verdict despite nonzero retrieval. |
| `batch_f2_clti_itp_after_bypass:1` | Post-operative management after bypass for CLTI in a patient with immune thrombocytopenia, including antithrombotic therapy. | R6: `clti` ×1 and `antithrombotic_therapy` ×1 — decision-at-issue queries. R7: both keys ×2 — English CLTI/ITP/post-bypass query plus guideline-specific postoperative antithrombotic retries. | R6 `interaction_gap`, 20 snippet observations. R7 `not_covered`, 11. Raw top snippets absent. | **Regressed to absence.** Both runs retrieved evidence, so a Run 7 retrieval miss is not established. |
| `batch_f3_aaa_clti_sequencing:1` | Stable asymptomatic 5.8 cm infrarenal AAA plus Rutherford 5 CLTI: aneurysm repair or limb revascularisation first? | R6: `abdominal_aortic_aneurysm` ×2 and `clti` ×2 — decision-at-issue queries plus sequencing retries. R7: both keys ×2 — English combined-case query with aorta/limb anchors plus priority-of-treatment retries. | R6 `not_covered`, 54 snippet observations. R7 `not_covered`, 58. Raw top snippets absent. | Persistent absence verdict with abundant retrieval; likely interaction coverage/reasoning, but raw evidence is required for adjudication. |
| `batch_f4_aaa_clti_sepsis_anticoagulation:1` | Stable asymptomatic 6.5 cm AAA, Rutherford 6 CLTI with wet gangrene/sepsis, and rivaroxaban for AF: treatment priority and perioperative antithrombotics. | R6: `abdominal_aortic_aneurysm`, `clti`, and `antithrombotic_therapy` ×2 each — decision-at-issue plus focused sequencing/antithrombotic retries. R7: the same keys ×2 each — English combined-case query with aorta/limb/thrombus anchors plus guideline-specific retries. | R6 `not_covered`, **0** snippets. R7 `not_covered`, 26. Raw top snippets absent. | **Yes: Run 7 retrieved evidence where Run 6 retrieved none, but the final absence verdict did not improve.** High-priority false-absence review. |

## Carotid-web control finding

`batch_s4_symptomatic_carotid_web:1` is not one of the 10 rows because neither committed full-run
artifact labels it `not_covered` or `retrieval_uncertain` (Run 6: `partial_principles`; Run 7:
`interaction_gap`). Both artifacts preserve a Pathway basis containing the carotid-web recommendation:
CEA or CAS may be considered after detailed neurovascular work-up finds no other stroke cause
(Class IIb, Level C). Thus the corrected baseline diagnosis is supported, but these two full-run
artifacts do **not** show a Run-7-only retrieval discovery; Run 6 had already surfaced the recommendation.

## Clinician adjudication priorities

1. Review the three deterministic zero-to-nonzero cases: case-switch T2, duplicate-delivery T2, and F4.
2. Review C3 and F3, where `not_covered` persisted despite repeated nonzero retrieval.
3. Review C2 and F2, where Run 7 moved from an interaction/partial state to `not_covered`.
4. For the next binding eval artifact, persist bounded, ranked `snippet_digests` (bucket, identity
   metadata, similarity, and clean text) so coverage claims can be audited without replay.
