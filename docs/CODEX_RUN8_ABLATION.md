# Codex Run 8 — model ablation before any further R7 items

**Do not start R7.3–R7.9.** Run 7 established that the retrieval lever is spent:
F4 went 0 → 26 snippets with the verdict unchanged at `not_covered`; C2 went
31 → 51 snippets and *regressed* to `not_covered`; F3 went 54 → 58 with no
change. R7.10, a prompt-level fix, recovered 1 of 4 targeted cases. Every
further prompt fix is being tuned against a generator that may itself be the
constraint. This run settles that question before more work is stacked on it.

Commit `315a39a` already landed the plumbing. Your job is to run the experiment.

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

## Step 3 — the ablation

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

## Step 4 — report against a rule fixed in advance

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
