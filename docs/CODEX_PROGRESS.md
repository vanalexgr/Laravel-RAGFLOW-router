# Codex unattended progress — Agentic Gate v2

## 2026-07-24 — Item 1: eval harness + scenarios

Implemented:

- Added 22 schema-validated scenarios: the authoritative 3-turn AAA benchmark, six adversarial
  scenarios, and all 15 binding non-regression cases with per-case baseline grades.
- Added `gate:eval` with pluggable stub/HTTP subjects, stub/external-cloud judges, deterministic
  checks, no-grade-drop enforcement, verbatim scoring, and replay artifacts containing stage traces.
- Added an explicit guard preventing the judge identity from matching the SUT identity.
- Registered application command discovery and added repository/command tests.

Files:

- `eval/scenarios/*.json`
- `app/GateEval/**`
- `app/Console/Commands/GateEvalCommand.php`
- `config/gate-eval.php`
- `bootstrap/app.php`
- `tests/Unit/GateEval/ScenarioRepositoryTest.php`
- `tests/Feature/GateEvalCommandTest.php`

Verification (disposable Hetzner checkout at `/tmp/codex-gate-v2`, production app untouched):

```text
JSON scenario count: 22
PHP syntax: no errors in GateEval implementation and tests

php artisan gate:eval --sut=stub --judge=stub
Scenarios 22 | Turns 32 | PASS 28 | MINOR 3 | FAIL 1
Routing 100.0% | No grade drop YES | Verbatim 100.0%
Artifact: gate-eval/runs/20260723_225446_967114.json
```

Environment note: the production vendor tree omits development packages, so `php artisan test` is
not available there. The end-to-end Artisan command and syntax checks ran successfully; PHPUnit will
be installed only in the disposable checkout when dependency work begins.

Blockers: none.

## 2026-07-24 — Item 2: install `laravel/ai` on cloud

Result: **CONFLICT — skipped per unattended autonomy rule.**

The required first dry-run was executed in the disposable Hetzner checkout:

```text
composer require laravel/ai --dry-run --no-interaction
Cannot use laravel/ai v0.10.1: requires php ^8.3.
Composer platform is overridden to 8.2.30; actual runtime is PHP 8.5.4.
DRY_RUN_EXIT=1
```

`composer.json` contains both `"require": {"php": "^8.2"}` and
`"config": {"platform": {"php": "8.2.30"}}`. The backlog explicitly calls for bumping the package
requirement but does not authorize changing the platform-emulation pin after a failed dry-run.
No dependency files, provider config, or production files were changed. Prism/Vizra coexistence was
not reached and therefore remains unverified.

A later disposable `composer install` exposed a second conflict: committed `composer.lock` resolves
Laravel **v12.49.0**, while the live vendor tree reports **v12.63.0**. The plan's statement that the
lock was already 12.63 is false; satisfying the SDK's Illuminate ≥12.62 dependency may require a
framework lock update, not merely adding a package.

Files: `docs/CODEX_PROGRESS.md`.

Blocker: decide/authorize the Composer platform target (minimum safe proposal: `8.3.x`) and rerun the
dry-run before installing. Items whose acceptance criteria require live `laravel/ai` calls cannot be
completed until this is resolved.

## 2026-07-24 — Item 3: agent rework

Result: **BLOCKED BY ITEM 2 — skipped.**

The acceptance criterion requires every agent to produce schema-valid structured output through the
cloud provider. `laravel/ai` is not installable under the current Composer platform pin, so reworking
the scaffolds now would create unexercised code and violate the backlog's verify-before-commit rule.
No agent files were changed.

Blocker: resolve item 2, then rework and run the agents as one coherent tested unit.

## 2026-07-24 — Item 4: S0 AnswerAssembly

Result: **BLOCKED BY ITEM 2 — skipped.**

S0 requires a schema-constrained cloud fill call through `laravel/ai`; the SDK conflict prevents the
required end-to-end execution and 15-case scorecard. A safe default valve is established in
`config/gate-v2.php` as `SYNTHESIS_OWNER=adapter`, so no incomplete Laravel synthesis path can become
active. No adapter or production path was changed.

Blockers:

- Resolve item 2 and complete item 3.
- ⛔ HUMAN: clinician sign-off is still required before candidate clinical assertions can be enabled.

## 2026-07-24 — Item 5: audited snippet library candidate

Implemented:

- Extracted four existing adapter assertions into `eval/audited_snippets.md`; no assertion was
  invented or promoted to guideline evidence.
- Marked every item `UNVERIFIED — clinician sign-off required` with its adapter source lines.
- Added `AuditedSnippetLibrary` and a flag defaulting OFF:
  `GATE_V2_AUDITED_SNIPPETS_ENABLED=false`.
- Added `TODO(human)` markers in config, service, and candidate file.

Files:

- `eval/audited_snippets.md`
- `app/Ai/Gate/AuditedSnippetLibrary.php`
- `config/gate-v2.php`
- `tests/Unit/GateEval/AuditedSnippetLibraryTest.php`

Verification (disposable Hetzner checkout):

```text
php -l app/Ai/Gate/AuditedSnippetLibrary.php
No syntax errors detected
php -l config/gate-v2.php
No syntax errors detected

Runtime smoke:
flag default: false
library with flag OFF: []
candidate count with temporary in-process flag ON: 4
```

⛔ HUMAN blocker: a named clinician must verify sources/content and approve each record before the
flag may be enabled. Safe placeholder remains OFF.

## 2026-07-24 — Item 6: routing preparation (F + P; no contract flip)

Implemented:

- Added `OrientRoutingPriorService` with the canonical 14-guideline reference migrated from the
  adapter, anatomy/acuity selection rules, and deterministic adapter-style turn signals.
- Unified the two named Laravel routing concerns into this one Orient-side layer:
  antithrombotic pruning/companion selection and the disabling-stroke carotid boost.
- Enforced the locked maximum of two candidates and the CLTI-over-claudication pathway rule.
- Added `gate:routing-proof` for JSONL live-route replay and full eval-scenario replay, broken down by
  turn class with verbose disagreement output.
- Added a representative eight-row replay fixture and focused unit coverage.
- Did **not** change `consult_vascular_guidelines`, the adapter docstring, or any production contract.

Files:

- `app/Ai/Gate/Routing/OrientRoutingPriorService.php`
- `app/Console/Commands/GateRoutingProofCommand.php`
- `eval/routing/sample_log.jsonl`
- `tests/Unit/GateEval/OrientRoutingPriorServiceTest.php`

Verification (disposable Hetzner checkout):

```text
php artisan gate:routing-proof eval/routing/sample_log.jsonl -v
knowledge 1/1; case_new 7/7
Overall: 8/8 (100.0%)

php artisan gate:routing-proof --scenarios -v
case_new 22/22
case_followup_substantive 7/7
gate_reply 1/1
knowledge 2/2
Overall: 32/32 (100.0%)
```

Blockers: none for preparation. Live shadow disagreement judging remains an S4 activity and requires
real production-shaped logs plus the cloud Orient agent after item 2 is resolved.

## 2026-07-24 — Item 7: consolidation and unattended summary

Plan/status updates:

- Updated plan §10 with implementation status and §11 concern F as prepared but not flipped.
- Replaced stale §12 next steps with the cloud-development state, concrete Composer prerequisite,
  deferred Ollama gate, and outstanding human decisions.

### Done

- Binding eval foundation: 22 scenarios / 32 turns; stub end-to-end scorecard; external judge boundary;
  per-case no-grade-drop; verbatim metric; stage-trace artifacts.
- Default-OFF audited-snippet candidate extraction with four source-tagged, unverified records.
- Routing preparation/proof harness: 8/8 sample replay and 32/32 eval replay.
- Four small review commits; no production deployment, adapter push, tool-contract flip, or main-branch
  change.

### Engineering-blocked

- `laravel/ai` installation: Composer emulates PHP 8.2.30 although Hetzner runs PHP 8.5.4; SDK requires
  PHP ^8.3. Prism/Vizra coexistence remains untested because resolution stopped at the platform gate.
- Agent rework and S0 AnswerAssembly: correctly skipped because their required cloud structured-output
  runs cannot be performed without the SDK.

### ⛔ HUMAN-blocked / recommended next decisions

1. Authorize the Composer `config.platform.php` target (recommended minimum compatible family: 8.3)
   and reconcile committed Laravel 12.49 vs live 12.63 so the dry-run can proceed; this is an
   engineering prerequisite, not a clinical decision.
2. Assign clinician sign-off for all four candidates in `eval/audited_snippets.md`; keep the flag OFF
   until signed.
3. Resolve plan §0 decisions: one tool vs two, S7 stability interval, PHI-at-rest policy, and clinician
   audit owner/rate/cadence.

### Recommended next engineering sequence after platform authorization

1. Repeat the dependency dry-run and verify Prism/Vizra coexistence; install/publish cloud config.
2. Rework and cloud-probe the agents per §10.
3. Implement S0 behind `SYNTHESIS_OWNER=adapter|laravel`, keep the adapter default, then run the real
   external-judge 15-case/gap-taxonomy checkpoint.

No independently actionable backlog item remains after the dependency conflict and human-gated
snippet activation.

### Final verification

Focused tests ran in the disposable Hetzner checkout using the committed lock state:

```text
Tests: 5 passed, 4 deprecated (13 assertions)
Deprecations originate during application boot under the committed dependency set.

gate:eval stub scorecard:
22 scenarios | 32 turns | PASS 28 | MINOR 3 | FAIL 1
Routing 100.0% | no grade drop YES | verbatim 100.0%

routing proof:
sample log 8/8 (100.0%)
eval scenarios 32/32 (100.0%)
```

Cleanup:

- Removed the disposable Hetzner checkout `/tmp/codex-gate-v2`; production was never modified.
- Preserved the unrelated `CLAUDE.md` edit that predated this run in stash
  `pre-existing CLAUDE.md edit before gate-v2 unattended run`, allowing the requested clean branch
  without committing another owner's change.

## 2026-07-24 — Phase 0 / J0.2: Laravel AI dependency de-risk and cloud smoke

Work was performed in disposable Hetzner checkouts. `/opt/cg/laravel/app` was read only for its
existing environment variables; production source and data were not changed.

### Dependency resolution

Applied the authorized target only in `/tmp/codex-gate-v2` first:

```text
require.php:              ^8.3
laravel/framework:        ^12.62
config.platform.php:      8.3.0
laravel/ai:               ^0.10.1
```

`composer require laravel/ai:^0.10.1 --dry-run --with-all-dependencies` resolved cleanly:

```text
laravel/ai                v0.10.1
laravel/framework         v12.49.0 -> v12.64.0
prism-php/prism           v0.92.0 (retained)
vizra/vizra-adk           0.0.42 (retained)
Package operations        5 installs, 50 updates, 0 removals
```

The real disposable install produced the same four key versions. `config/ai.php` was published with
the SDK's `ai-config` tag and defaults to the `openai` cloud driver. Gate-specific defaults are
`GATE_V2_PROVIDER=openai` and `GATE_V2_MODEL=gpt-5-mini`.

Composer audit reports four advisories already present in the resolved dependency set:

```text
psy/psysh v0.12.18        CVE-2026-25129 (medium)
symfony/yaml v7.4.1       CVE-2026-45304, CVE-2026-45305, CVE-2026-45133 (low)
```

No unrelated package upgrade was attempted silently; these should be handled as a separate
dependency-maintenance change.

Files:

- `composer.json`
- `composer.lock`
- `config/ai.php`
- `config/gate-v2.php`
- `app/Ai/Gate/StructuredSmokeAgent.php`
- `app/Console/Commands/GateAiSmokeCommand.php`

### Verification

The structured-output cloud fill call passed:

```json
{
    "ok": true,
    "provider": "openai",
    "model": "gpt-5-mini",
    "latency_ms": 3741,
    "response": {
        "status": "ready",
        "items": ["structured", "cloud"]
    }
}
```

Formatting and Composer validation:

```text
Pint: 4 files PASS
composer validate --strict --no-check-publish: valid
```

Full-suite result after the SDK install:

```text
Tests: 6 failed, 84 passed (244 assertions)
```

An independent disposable checkout of the unmodified pre-install commit produced the identical
`6 failed, 84 passed (244 assertions)` result. The failures are therefore baseline/environment
failures, not a Laravel AI or framework-upgrade regression:

- one `ChangeDetectionServiceTest` prompt expectation;
- two `PreRetrievalServiceTest` fallback expectations;
- three `LeanRetrievalTest` requests where the inherited live RAG endpoint is unreachable and
  `GuidelineRouterService` calls `successful()` on a `ConnectionException`.

Phase 0 conclusion: **CLEAN / GO**. Laravel AI structured output is operational against the existing
cloud provider, with no test-grade drop from the dependency change.

## 2026-07-24 — Milestone A / J1.1: agent contracts and deterministic boundaries

Reworked the scaffold to match plan §10 before workflow wiring:

- removed the standalone `TriageAgent`; `OrientAgent` now owns the full live turn taxonomy,
  same/new-case decision, delta-merged patient model, changed fields, provenance, question lifecycle,
  response mode, and at-most-two guideline candidates;
- removed `HasTools` from `PathwayAgent` and `KnowledgeAnswerAgent`; retrieval attempts are now owned
  by deterministic PHP, while the agent only judges supplied snippets and proposes a better query;
- expanded pathway coverage with `retrieval_uncertain`, covered components, and interaction-gap
  signals;
- changed Probe and Knowledge output to the structured evidence-status object from plan §7;
- constrained Probe to patient model/current question/snippets and made confidence logging-only;
- made Critic explicitly snippet-digest-aware and added the never-re-ask invariant;
- added a state-aware deterministic pre-Orient guard (prompt injection is always blocked);
- added one Laravel-owned evidence-status computation and a discrete deterministic tail with
  declined/answered-question suppression, fixed non-ESVS banner, and dose lint.

Files:

- `app/Ai/Gate/OrientAgent.php`
- `app/Ai/Gate/PathwayAgent.php`
- `app/Ai/Gate/ProbeAgent.php`
- `app/Ai/Gate/CriticAgent.php`
- `app/Ai/Gate/KnowledgeAnswerAgent.php`
- `app/Ai/Gate/Guard/PreOrientGuardService.php`
- `app/Ai/Gate/EvidenceStatusService.php`
- `app/Ai/Gate/GateDecisionTail.php`
- `tests/Unit/GateEval/{GateAgentSchema,PreOrientGuardService,EvidenceStatusService,GateDecisionTail}Test.php`

Verification in the disposable Hetzner checkout:

```text
Pint: 12 changed files PASS
Gate agent schemas + deterministic services:
20 tests passed (33 assertions)
```

The broad `app/Ai/Gate` style scan also found three pre-existing formatting issues in untouched
files; they were not mixed into this review unit. Blockers: none.
