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
