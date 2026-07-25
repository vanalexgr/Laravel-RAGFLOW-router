# Codex Run 8 — fix the retry, then ablate

**Do not start R7.3–R7.9.**

> **Revised after a trace audit.** An earlier draft of this brief claimed Run 7
> proved retrieval was saturated, and sent you straight to a model ablation.
> That reading was wrong. The chunk counts in `run7_fail_digest.md` **sum across
> retrieval trace entries** — they measure retrieval work performed, not evidence
> delivered to the Probe. The real figure was far smaller, and on the hardest
> cases it was zero.

`GatePathwayWorker` **assigned** the latest attempt's snippets instead of merging
them, so a retry that returned fewer — or none — discarded everything the first
attempt found. Across Run 7's 21 retry branches: **12 came back smaller, 4
returned literally zero.** F4 is the clearest case:

| Guideline | Attempt 1 | Attempt 2 |
|---|---|---|
| `abdominal_aortic_aneurysm` | 8 snippets, `partial` | **0**, `not_covered` |
| `clti` | 10, `not_covered` | **0**, `not_covered` |
| `antithrombotic_therapy` | 8, `partial` | **0**, `not_covered` |

F4 is the case the coverage audit recorded as "0 → 26 chunks, verdict did not
improve". It retrieved 26 and delivered **zero**, and the retry flipped two
guidelines off `partial`. An ablation run on this pipeline would have given a
reasoning Probe an empty evidence set on exactly the discriminating cases and
produced an uninterpretable null result.

Commit `315a39a` landed the ablation plumbing; a follow-up commit landed the
merge fix. Work the steps in order — **the ablation is now Step 4, not Step 1.**

## Context you need

- Probe is the clinical answer generator (`guideline_grounded_answer` +
  `interpretive_frame`), not just a question-asker — see `app/Ai/Gate/README.md:30`.
- Run 7's `effort=low` labels were **inert**: `GateModelOptions` only emitted
  `reasoning.effort` for reasoning-capable models, and every stage ran `gpt-4.1`.
  Effort is now configurable per stage via `gate-v2.stage_efforts.*`, with the
  agent constant as fallback.
- `GATE_V2_REASONING_MODEL_PREFIXES` gates which model names accept the effort
  option. **Check the model you select is covered by it.** If it is not, the
  effort is dropped silently and the run will read as a null result.

## Step 1 — verify the plumbing before spending a run

```bash
php vendor/bin/phpunit tests/Unit/GateEval/GateModelOptionsTest.php
```

These four tests were authored without a local PHP toolchain and have **never
been executed**. Fix them if they fail, do not delete them.

Then confirm the effort actually reaches the wire for your chosen Probe model —
log or dump the resolved `providerOptions()` once. A null result caused by a
dropped option is the single most likely way this run wastes its budget.

## Step 2 — capture adjudicable evidence

Set for eval runs only (not production):

```
GATE_V2_PERSIST_SNIPPET_DIGESTS=true
```

Confirm the artifact now contains `snippet_digests` with ranked text, similarity
and identity metadata per guideline. Without this the run cannot distinguish a
reasoning failure from an evidence-relevance failure, which is the whole point.

## Step 3 — re-measure with the merge fix, before touching any model

`mergeSnippets` makes a retry strictly additive: it can add and re-rank, never
remove. Re-run the 8 cases on the current models with only this change.

**If C2, F2 and F4 recover here, the model question was never the binding
constraint** and Step 4 should be re-scoped or dropped. Report that outcome
before spending the ablation budget.

Then answer the two questions the fix makes newly measurable:

1. **Does the retry ever change a verdict now that it cannot destroy evidence?**
   Attempt 2 cost 229s of staged wall-clock in Run 7, of which **201s came from
   branches that returned the same or fewer snippets**. If a strictly-additive
   retry still never moves a coverage verdict, delete it and take the time back.
   Note the Run 7 quality run used `GATE_V2_CONCURRENCY_DRIVER=sync`; under the
   default parallel driver the saving is one retrieve+pathway round off the
   *slowest* branch, not the summed total. Confirm against
   `run7_latency_20260725_220506.json`, which is the authoritative timing artifact.

2. **Were the retry-heavy turns the ones that blew the 60s child-process
   timeout?** Run 7's default concurrent run aborted at 24/32 turns. A branch
   doing two full retrieve+pathway rounds is much likelier to exceed a fixed 60s
   child budget. This is a hypothesis, not a finding — check whether the turns
   that died were retry-heavy. "Completion of all 32 turns under the default
   concurrency driver" is already on the release gate, so if retries are
   implicated, gating them clears a gate item as well as the latency.

## Step 4 — the ablation

Same 8 cases each time: **AAA T1, S2, S5, S6, F1, C2, F2, F4**. C2/F2/F4 are the
coverage-regression cases and are the discriminating ones — do not drop them.

| Arm | Probe | Critic | Purpose |
|---|---|---|---|
| A | current (`gpt-4.1`) | current (`gpt-4.1`) | baseline, re-measured on this commit |
| B | reasoning, effort `medium` | current | isolates the generator |
| C | current | reasoning, effort `high` | isolates the evaluator |

Leave Orient and Pathway on `gpt-4.1-mini` throughout — routing is already 100%
and changing it confounds the result.

Record per arm: grade, coverage verdict, and for every case whose verdict is
`not_covered`, whether the persisted top snippets actually contain a
recommendation that answers the question. That last column is the finding.

## Step 5 — report against a rule fixed in advance

- **C2/F2/F4 recovered at Step 3, before any model change** → the constraint was
  the destructive retry. Report and stop; Step 4 is not needed this run.
- **S6 and F2 recover in arm B** → the constraint was generator capability.
  Recommend a Probe/Critic model change, then resume the backlog at R7.3 only,
  and drop R7.4–R7.9 to P2 (they are formatting-class failures).
- **They do not recover, and the snippets do contain the answering
  recommendation** → the constraint is post-retrieval reasoning that a model
  swap does not fix; the deterministic decision-composition layer becomes P0.
- **They do not recover, and the snippets do not contain it** → it is evidence
  relevance after all; R7.3 becomes P0 and the model question is deferred.

State which branch fired. Do not stack a second fix inside this run — Run 7's
lesson is that stacked changes cannot be attributed.

## Out of scope

R7.3–R7.9, any prompt edits, any retrieval tuning, any production config change.
If arm B needs a prompt change to work, that is itself a finding — report it,
do not make it.
