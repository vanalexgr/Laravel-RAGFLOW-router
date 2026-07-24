# Agentic Gate v2 — Timeline & jobs-to-be-done

Reference roadmap. Maps to `docs/AGENTIC_GATE_V2_PLAN.md` (§8 S0–S7) and the current state in
`docs/CODEX_PROGRESS.md`. Last updated 2026-07-24.

**Assumptions:** effort is in *focused engineering days* for **one full-time engineer**; cumulative
"week" markers assume that and no stalls on human/infra gates — adjust for real capacity. No hard
calendar dates until a start date + capacity are set.

**Legend:** 🛠️ engineering · 🧑‍⚖️ human decision (blocks) · 🖥️ infra dependency · ✅ done · 🔓 gates the next job

---

## Baseline — already done (Codex run 1, 2026-07-24)

- ✅ Eval harness `php artisan gate:eval` + 22 scenarios (AAA + 6 adversarial + 15 non-regression) with
  no-grade-drop, verbatim metric, external-judge≠SUT, stage-trace artifacts.
- ✅ Routing prep: `OrientRoutingPriorService` (14-guideline reference + unified prunes) + `gate:routing-proof`.
- ✅ Audited-snippet candidates extracted, flag OFF.
- ✅ Agents scaffolded (Orient/Pathway/Probe/Critic/Knowledge/Triage + tool + progress) — **not yet
  reworked or runnable** (see §10 of the plan). **The orchestration loop is NOT built.**

---

## Phase 0 — Unblock the LLM layer  ·  🔓 gates everything

| Job | Type | Depends on | Effort |
|---|---|---|---|
| J0.1 Authorize Composer target: `config.platform.php`→≥8.3, `require.php`→^8.3, reconcile locked framework 12.49→≥12.62 | 🧑‍⚖️ | — | decision |
| J0.2 De-risk spike: `composer require laravel/ai --dry-run` + confirm **prism v0.92 / vizra v0.0.42 coexistence** in a disposable checkout | 🛠️ | J0.1 | 1–2 h |
| J0.3 Install `laravel/ai`, publish `config/ai.php`, configure cloud provider (gpt-5-mini), smoke-test a structured call | 🛠️ | J0.2 clean | 0.5 d |
| J0.4 *Contingency:* if J0.2 conflicts → isolate the gate's LLM layer from prism/vizra | 🛠️ | J0.2 conflict | 1–3 d (unknown) |

**Risk fork:** J0.2 is the true unknown — clean install (~½ day) vs a dependency conflict (J0.4, open-ended).

---

## Phase 1 — MILESTONE A: the gate reasons on a case (dev harness, Hetzner CLI)

*Goal: `php artisan gate:probe2 "<AAA case>"` → watch triage → orient → ground (real retrieval) →
probe ⇄ critic loop → two-frame answer + stage trace.*

| Job | Type | Depends on | Effort |
|---|---|---|---|
| J1.1 Rework the 6 agents per plan §10 (merge Triage→Orient, deterministic orchestration, structured `evidence_status`, snippet-aware Critic, discrete tail) | 🛠️ | Phase 0 | 1–2 d |
| J1.2 Build `GateWorkflowService` + `gate:probe2` — the loop (bounce budgets, best-so-far, wall-clock deadline, `GateProgress`, `stage_trace`) | 🛠️ | J1.1 | 1–2 d |
| J1.3 Wire grounding to RAGFlow (`RetrieveEsvsSnippetsTool`→`RetrievalService`) incl. re-retrieval | 🛠️ | J1.1 | 0.5–1 d |
| J1.4 Run AAA + adversarial through `gate:probe2`; observe + iterate | 🛠️ | J1.2, J1.3 | 0.5 d |

**⇒ MILESTONE A ≈ 1 week of eng after Phase 0.** First time the gate is visible working (CLI, cloud model).

---

## Phase 2 — S0: answer-path migration on cloud (gated by eval)

| Job | Type | Depends on | Effort |
|---|---|---|---|
| J2.1 Clinician sign-off on the 4 audited snippets | 🧑‍⚖️ | — (can start now) | decision |
| J2.2 `AnswerAssembly`: PHP section skeletons (mode variants + gap taxonomy + canned strings + assets) + ONE cloud fill-call, behind `SYNTHESIS_OWNER` valve, on existing pipeline outputs (plan §11 D/E/G) | 🛠️ | Phase 0; J2.1 to finalize | 3–5 d |
| J2.3 Run `gate:eval` 15-case + gap-taxonomy + verbatim ≥98%; iterate to **no grade drop** | 🛠️ | J2.2 | 1–2 d |

**⇒ S0 checkpoint ≈ +1.5–2 weeks** (Milestone A not strictly required first; J2 can overlap J1).

---

## Phase 3 — S1: local-model swap  ·  🖥️ deferred until ISI hardware

| Job | Type | Depends on | Effort |
|---|---|---|---|
| J3.1 Provision ISI GPU host | 🖥️🧑‍⚖️ | ISI programme | external |
| J3.2 Run the deferred Ollama capability spike (`docs/spikes/…`, qwen2.5:14b) on real hardware — GO/NO-GO | 🛠️ | J3.1 | 0.5 d |
| J3.3 `SYNTHESIS_MODEL=local` swap; re-run 15-case (isolates model vs port) | 🛠️ | J3.2 GO, S0 | 1 d |

*Runs on the ISI timeline; everything else proceeds on cloud meanwhile.*

---

## Phase 4–8 — the rest of the migration to production (S2–S7, plan §8)

| Phase | Jobs | Type | Effort | Gate |
|---|---|---|---|---|
| S2 Guard + capabilities | deterministic pre-Orient guard (injection/meta/capabilities/out_of_scope) + canned responses | 🛠️ | 2–3 d | — |
| S3 State brain | chat_id state, idempotency, new-case detection, question lifecycle (Redis DB5) | 🛠️ + 🧑‍⚖️ | 3–5 d | 🧑‍⚖️ PHI-at-rest policy |
| S4 Orient shadow + routing proof | shadow routing on live traffic, log-replay + disagreement judging, unify prunes | 🛠️ | 3–5 d **+ shadow soak (days–weeks of traffic)** | — |
| S5 Tool-contract flip | one tool, drop `guideline_1/2/3` + `explain_app_capabilities`, push adapter | 🛠️ + 🧑‍⚖️ | 1–2 d | 🧑‍⚖️ one-tool UX; least reversible |
| S6 Deep loop in OpenWebUI | `GATE_OWNER=laravel`, full §9 suite + latency SLOs + progress streaming | 🛠️ | 3–5 d | — |
| **MILESTONE B: live in OpenWebUI** | *real user sees the gate* | — | — | after S6 |
| S7 Decommission | delete adapter cruft after N weeks stable | 🛠️ + 🧑‍⚖️ | 1 d | 🧑‍⚖️ define "N weeks" |

---

## Critical path & rough horizon (1 FT eng, cloud)

```
J0.1 decision → J0.2 spike → J0.3 install → [Milestone A ~1wk] → S0 (~+2wk)
   → S2 → S3 → S4 (+shadow soak) → S5 → S6 [Milestone B]
```

- **Milestone A (gate visibly reasoning, CLI):** ~1 week of eng **after the Phase-0 composer decision**
  (which could be same-day if J0.2 is clean).
- **S0 (answer path passing the eval gate on cloud):** ~3 weeks in.
- **Milestone B (live in OpenWebUI):** realistically **~8–12 weeks**, plus external gates — the S4 shadow
  soak and the human/infra decisions can extend it.

## Human/infra gates (each blocks its phase — resolve early, in parallel)

| Gate | Blocks | Recommendation |
|---|---|---|
| 🧑‍⚖️ Composer platform bump (J0.1) | **everything** | authorize ≥8.3 + framework ≥12.62 |
| 🧑‍⚖️ Clinician sign-off (audited snippets) | finalizing S0 + S6 audit | assign a named clinician now |
| 🧑‍⚖️ PHI-at-rest policy | S3 | decide allowlist vs on-prem-exempt |
| 🧑‍⚖️ One-tool UX confirm | S5 | recommend delete `explain_app_capabilities` |
| 🧑‍⚖️ Decommission "N weeks" | S7 | suggest 4 weeks, zero rollbacks |
| 🖥️ ISI GPU host | S1 (local) | on the ISI programme timeline |

## Parallelizable now (don't wait on the composer decision)
- Clinician sign-off (J2.1) and the plan §0 policy decisions.
- The de-risk spike J0.2 (1–2 h) — do this first; it tells us if Milestone A is "~4 days out" or "we have a dependency problem."
