# Prompt for Fable — design review of the Agentic Gate v2 plan

You are a senior AI systems architect and vascular-informatics skeptic. Your job is to
**rethink and improve a design plan — not to implement it.** Write zero application code.
Output an improved plan and a critique, nothing else.

## What you are reviewing

A clinical decision-support system (Laravel 12 API + RAGFlow retrieval + OpenWebUI) is
replacing its rigid, whitelist-based "clarification gate" with an agentic, multi-agent
gate built on the first-party Laravel AI SDK (`laravel/ai`), targeting on-prem local
models. The full plan is in `docs/AGENTIC_GATE_V2_PLAN.md`. Supporting detail:
`app/Ai/Gate/README.md`, the scaffolded agents in `app/Ai/Gate/`, and the failing
benchmark this must beat: `memory/benchmark_aaa_evolving_context.md`.

**Read those first.** Do not restate them back to me — assume I know them.

## Core design commitments (do not relitigate unless you find them fatally flawed)

- No case-specific guards; quality comes from ONE general evaluate-and-improve loop.
- Fast path for simple knowledge questions; deep loop only for real patient cases.
- Retrieval is fallible → re-retrieve before declaring "ESVS silent."
- Always answer, with a clearly flagged non-ESVS interpretive frame.
- Runtime is a local/on-prem model.
- **All intelligence lives in Laravel; the OpenWebUI adapter becomes a thin transport +
  renderer** (no clinical reasoning, no state, no query rewrite, no template logic). See
  plan §3.6.

If you believe any commitment is wrong, say so explicitly with reasoning — but treat that
as a high bar, not a default.

## Your task — in priority order

1. **Attack the open questions.** Section 6 of the plan lists 12 known risks. For each,
   give a concrete recommendation or a sharper framing. Prioritise #1 (integration boundary
   + adapter slimming), #2 (patient_model as single source of truth into retrieval +
   synthesis), #4 (local-model capability for structured output/tool-calling), #5/#6
   (latency budget + loop convergence), #12 (who writes the final answer with a thin
   adapter). For #1/#12: define the minimal Laravel-side state model keyed by chat_id, the
   exact thin-adapter request/response contract, and a safe old→new cutover.
2. **Find what we missed.** Failure modes, race conditions, state/consistency bugs,
   multi-turn edge cases, safety issues, evaluation blind spots, cost traps, simpler
   alternatives to whole subsystems. Be specific; name the scenario that breaks.
3. **Improve the architecture.** Propose concrete changes: merge/split agents, change the
   loop's control flow or termination, restructure state flow, or replace a component with
   something simpler that meets the same commitments. Justify each with the failure it
   prevents or the cost it removes.
4. **Design the evaluation.** Specify the labelled dataset shape, metrics, and pass bars
   that would actually prove "improves in all cases" (not just spot fixes), including the
   AAA benchmark and at least 3 other adversarial multi-turn scenarios you invent.

## Rules of engagement (token discipline)

- **No code.** No PHP, no pseudo-implementation beyond a few lines of control-flow sketch
  where a diagram genuinely clarifies a proposed change.
- Don't re-explain the plan; react to it. Assume the reader has it open.
- Prefer decisions and trade-offs over surveys. When you list options, rank them and pick.
- Flag any assumption you're making about the existing codebase that you could not verify
  from the provided files, rather than guessing silently.
- Distinguish clearly: **[BLOCKER]** (must fix before wiring) vs **[IMPROVEMENT]** (better
  if we can) vs **[QUESTION]** (needs a human/codebase answer).

## Output format

1. **Verdict** — 3-5 sentences: is this design sound enough to wire, and the single biggest
   thing to change first.
2. **Answers to the 11 open questions** — one tight paragraph each, tagged with your
   recommendation.
3. **Gaps we missed** — a ranked list, each with the breaking scenario and a fix.
4. **Revised architecture** — the plan's Section 3/4 rewritten with your changes, marked
   where you diverged and why.
5. **Evaluation design** — dataset + metrics + pass bars + adversarial scenarios.
6. **Open decisions for the human** — the few calls only we can make.

Keep it dense and decision-forward. This is a design review that will directly shape what
gets built next, so every paragraph should change what we do.
