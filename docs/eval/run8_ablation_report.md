# Run 8 retry preservation and model ablation

Date: 2026-07-26  
Cases: AAA T1, S2, S5, S6, F1, C2, F2, F4  
Execution: detached disposable Hetzner checkout, real HTTP `GateWorkflowService`, external GPT-5 judge

No R7.3–R7.9 work, prompt edit, retrieval-query tuning, production configuration/default change, or
adapter change was made.

## Step 1 — model-option plumbing

The previously unexecuted model-option tests passed:

```text
GateModelOptionsTest: 5 tests, 5 assertions, PASS
```

The brief originally said four tests, but the committed file contains five. Resolved options were
dumped under the exact run environments:

```text
Probe o3-mini, medium: {"reasoning":{"effort":"medium"}}
Critic o3-mini, high:  {"reasoning":{"effort":"high"}}
```

`o3-mini` matches the configured `o3` reasoning prefix, so effort was not silently dropped.

The retry-preservation workflow tests initially exposed two stale test-only issues: an anonymous
retrieval mock lacked the current optional citation-query argument, and a truncation assertion
required exactly 1,200 characters although the contract is a maximum. Both tests were corrected
without changing production behavior. Final combined result:

```text
GateModelOptionsTest + GateWorkflowServiceTest:
15 tests, 28 assertions, PASS
```

## Step 2 — evidence persistence

`GATE_V2_PERSIST_SNIPPET_DIGESTS=true` was set only on eval server processes. Every complete artifact
contains ranked digest text, similarity, source, signal ratio, and identity metadata. The pre-merge
baseline contains 64 digest entries across 8/8 cases.

## Step 3 — destructive-retry merge fix

`GatePathwayWorker` now merges, de-duplicates, similarity-ranks, and bounds evidence across attempts.
An empty or weaker retry can no longer erase first-pass evidence.

### Before vs after merge

| Case | Pre-merge grade / coverage / digests | Merge grade / coverage / digests | Result |
|---|---|---|---|
| AAA T1 | FAIL / `covered` / 6 | MINOR / `covered` / 6 | grade improved |
| C2 | MINOR / `not_covered` / 12 | MINOR / `interaction_gap` / 12 | false absence corrected |
| F1 | PASS / `interaction_gap` / 9 | PASS / `interaction_gap` / 12 | stable |
| F2 | FAIL / `not_covered` / 9 | MINOR / `interaction_gap` / 12 | grade and false absence recovered |
| F4 | MINOR / `not_covered` / 2 | MINOR / `not_covered` / 7 | more evidence retained; verdict unchanged |
| S2 | FAIL / `interaction_gap` / 3 | FAIL / `interaction_gap` / 12 | evidence retained; grade unchanged |
| S5 | MINOR / `interaction_gap` / 11 | MINOR / `interaction_gap` / 12 | stable |
| S6 | FAIL / `interaction_gap` / 12 | MINOR / `interaction_gap` / 12 | grade improved |

Merge-fix scorecard:

```text
8 scenarios | 8 turns | PASS 1 | MINOR 6 | FAIL 1
Routing 100.0% | verbatim 100.0%
```

C2 and F2 recovered before a model change; F4 did not. The revised Step 3 stop condition required all
three, so the ablation proceeded.

### Retry value and timeout hypothesis

Attempt 2 consumed 129,501 ms of summed retrieve+Pathway stage time in the eight-case synchronous run:

| Case | Attempt-2 retrieve + Pathway ms |
|---|---:|
| C2 | 18,083 |
| F1 | 20,859 |
| F2 | 20,367 |
| F4 | 46,182 |
| S6 | 24,010 |

Retry did change branch verdicts. The useful positive transition was F2/CLTI
`not_covered → partial`; several other assessors moved toward `not_covered`, but their first-pass
evidence is now retained. Therefore “retry never moves coverage” is false and unconditional deletion
is not justified by this sample.

F4 was both the heaviest retry turn (46.2 seconds for attempt 2 alone under the staged synchronous
measurement) and the turn where Run 7’s default process driver aborted. This supports the
retry-heavy-timeout hypothesis, but does not prove causality without a default-driver replay.

Artifacts:

- `docs/eval/run8_premerge_arm_a_external_20260725_230021.json`
- `docs/eval/run8_arm_a_merge_external_20260725_233343.json`

## Step 4 — model ablation on the merge-fixed pipeline

Orient and Pathway remained `gpt-4.1-mini`. Arm B changed only Probe to `o3-mini`, medium effort.
Arm C changed only Critic to `o3-mini`, high effort.

| Case | Arm A grade / coverage | Arm B grade / coverage | Arm C |
|---|---|---|---|
| AAA T1 | MINOR / `covered` | MINOR / `covered` | unavailable |
| C2 | MINOR / `interaction_gap` | FAIL / `not_covered` | unavailable |
| F1 | PASS / `interaction_gap` | MINOR / `not_covered` | unavailable |
| F2 | MINOR / `interaction_gap` | FAIL / `not_covered` | unavailable |
| F4 | MINOR / `not_covered` | FAIL / `not_covered` | unavailable |
| S2 | FAIL / `interaction_gap` | FAIL / `interaction_gap` | unavailable |
| S5 | MINOR / `interaction_gap` | MINOR / `interaction_gap` | unavailable |
| S6 | MINOR / `interaction_gap` | FAIL / `interaction_gap` | unavailable |

Arm B scorecard:

```text
8 scenarios | 8 turns | PASS 0 | MINOR 3 | FAIL 5
Routing 100.0% | verbatim 100.0%
```

Arm B made quality worse. S6 and F2 did not recover.

For every `not_covered` Arm B case, the persisted top snippets do not answer the core question:

| Case | Answering recommendation present? | Evidence finding |
|---|---|---|
| C2 | No | thrombotic/arterial conditions, not tumour compression without thrombosis |
| F1 | No | generic bypass/antithrombotic evidence, not APS bridging |
| F2 | No | generic CLTI/access prose, not ITP-specific post-bypass management |
| F4 | No | one retained generic CLTI recommendation; no sepsis/AAA/DOAC sequencing guidance |

S6 was `interaction_gap`, not `not_covered`, but its 12 snippets likewise contain antiplatelet/CEA
recommendations and no perioperative apixaban stop/restart/no-bridging recommendation.

Arm C was executed, but its first high-effort Critic produced no scored candidate under the committed
1,600-token structured-output budget:

```text
No candidate completed Critic scoring before the deadline.
```

The runner writes an artifact only after all selected turns, so no Arm C scorecard exists. Increasing
the token budget would be a second fix and was not done.

Artifact:

- `docs/eval/run8_arm_b_merge_external_20260725_234613.json`

## Step 5 — pre-committed decision branch

**Branch 3 fired: S6 and F2 did not recover in Arm B, and the persisted snippets do not contain the
missing answering recommendations.**

Conclusion: the binding constraint remains evidence relevance. Per the fixed rule, **R7.3 becomes P0**
and the model question is deferred. Arm B provides negative evidence against a Probe upgrade under
the tested configuration; Arm C is operationally non-adjudicable and does not alter the branch.

No second fix was stacked.
