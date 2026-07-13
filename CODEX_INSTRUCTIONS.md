# Codex Work Instructions — Laravel Bridge

## Context

This repo is a Laravel 12 API ("the bridge") that routes vascular-surgery clinical questions from OpenWebUI to ESVS guideline datasets in RAGFlow. The hot path is `POST /api/v1/vascular-consult` → `ToolController` → `RetrievalService` → `RAGFlowClient` (Guzzle) → a Python FastAPI proxy in `ragflow_service/app.py` (port 8000) → RAGFlow. Several pre-retrieval steps call Azure OpenAI via `Http::` (routing, normalization, planner, coverage assessment).

Run tests locally with:

```bash
composer install
cp -n .env.example .env && php artisan key:generate
php artisan test --testsuite=Unit        # offline-safe
php artisan test                         # Feature suite currently requires live Azure/RAGFlow creds (fixed by Task 13)
python3 -m py_compile ragflow_service/app.py   # syntax gate for the Python bridge
```

There is no PHP test coverage for the Python bridge; validate it with `py_compile` plus the specific curl checks given per task.

## Ground rules for Codex

- Execute one task at a time, in listed order. Respect dependencies.
- After each task, run its acceptance check before moving on.
- Do not touch files outside a task's declared scope.
- Tasks marked [HUMAN REVIEW] — implement on a branch, do not merge.
- Never weaken input validation, API-key auth, or PHI scrubbing while making a change.
- Preserve existing log lines that CLAUDE.md documents as monitored (`[PRE-RETRIEVAL TIMING]`, retrieval channel logs) unless a task explicitly says to change them.

## Task summary table

| ID  | Title                                                        | Severity | Effort | Depends on | Human review? |
|-----|--------------------------------------------------------------|----------|--------|------------|---------------|
| T1  | Patch vulnerable Composer dependencies                       | High     | S      | —          | Yes           |
| T2  | Remove/gate debug routes and unauthenticated MCP endpoints   | High     | S      | —          | Yes           |
| T3  | Harden Python bridge network exposure and secret check       | High     | S      | —          | Yes           |
| T4  | Stop logging raw (pre-scrub) clinical text in HTTP logs      | High     | M      | —          | Yes           |
| T5  | `AzureOpenAiLlmClient` silently drops the temperature param  | Medium   | S      | —          | Yes           |
| T6  | `Http::pool` connection failure crashes the fallback router  | High     | S      | —          | Yes           |
| T7  | Fix three PHI scrubber bugs                                  | Medium   | M      | —          | Yes           |
| T8  | Distinguish "upstream failure" from "no evidence"            | High     | L      | T6         | Yes           |
| T9  | Align Laravel ↔ bridge ↔ RAGFlow timeouts                    | High     | M      | —          | Yes           |
| T10 | Add bounded connect-retry to both HTTP hops                  | Medium   | M      | T9         | Yes           |
| T11 | Remove wasted duplicate LLM routing call on legacy path      | Medium   | S      | T6         | Yes           |
| T12 | DI/singleton refactor + per-request file-I/O caching         | Medium   | L      | T5–T11     | Yes           |
| T13 | Make the Feature test suite runnable offline                 | Medium   | M      | —          | No            |
| T14 | Small hygiene cleanups                                       | Low      | S      | T12        | No            |

Phases: **Phase 1 (security): T1–T4 · Phase 2 (correctness): T5–T8 · Phase 3 (bridge robustness): T9–T10 · Phase 4 (performance): T11–T12 · Phase 5 (maintainability): T13–T14.**

---

## Phase 1 — Security

### Task T1: Patch vulnerable Composer dependencies [HUMAN REVIEW]

**Files:** `composer.json`, `composer.lock`

**Problem:** The lockfile pins versions with published advisories (verified against Packagist's security-advisories API on 2026-07-13; `composer audit` will confirm):

- `laravel/framework v12.49.0` — Temporary Signed URL Path Confusion (fixed 12.61.1); CRLF injection in default email rule, CVE-2026-48019 (fixed 12.60.0).
- `guzzlehttp/guzzle 7.10.0` — CVE-2026-55767 (dot-only cookie domains), CVE-2026-55568 (silent HTTPS-proxy downgrade); fixed 7.12.1.
- `guzzlehttp/psr7 2.8.0` — CVE-2026-55766 (CRLF in start-line, fixed 2.12.1), CVE-2026-49214 / CVE-2026-48998 (fixed 2.10.2).
- `league/commonmark 2.8.0` — CVE-2026-33347, CVE-2026-30838 (fixed 2.8.2+).
- `symfony/http-kernel v7.4.5` — CVE-2026-45075 (fixed 7.4.12).
- `symfony/http-foundation v7.4.5` — CVE-2026-48736 (fixed 7.4.13).
- `symfony/mailer v7.4.4` — check CVE-2026-45068 applicability during audit.

**Implement:**
1. Run `composer audit` and record the exact advisory list.
2. Run `composer update laravel/framework guzzlehttp/guzzle guzzlehttp/psr7 league/commonmark "symfony/*" --with-all-dependencies`. Do not change version constraints in `composer.json` unless required to reach a patched release (all fixes above are within existing `^` constraints).
3. Re-run `composer audit` until it reports zero advisories (or only advisories with no released fix — document those in the PR description).

**Acceptance:**
```bash
composer audit                       # 0 actionable advisories
php artisan test --testsuite=Unit    # green
php artisan route:list               # boots without error
```

**If blocked:** If a patched release introduces a breaking change (framework minor bumps occasionally do), pin the highest non-breaking patched version, note the residual advisory in the PR, and continue.

---

### Task T2: Remove/gate debug routes and unauthenticated MCP endpoints [HUMAN REVIEW]

**Files:** `routes/web.php`, `app/Http/Controllers/McpController.php`, `bootstrap/app.php`

**Problem:**
- `routes/web.php:10-20` exposes `GET /debug-config` and `GET /debug-test` publicly in production.
- `routes/web.php:32-44` exposes `GET|HEAD /vascular` (SSE) and `POST /vascular` with **no authentication** and CSRF explicitly exempted (`bootstrap/app.php:17-20`).
- `McpController::stream()` (`app/Http/Controllers/McpController.php:20-26`) is `while (true) { …; sleep(15); }` with no `connection_aborted()` check and no time limit — each open connection pins a PHP-FPM worker indefinitely. A handful of idle connections is a trivial denial of service.
- `McpController::message()` is a stub that does nothing; the real MCP registration is `Mcp::local(...)` in `app/Providers/AppServiceProvider.php:25`, which serves MCP over stdio via artisan, not over these web routes.

**Implement:**
1. Grep the repo (`openwebui_tools/`, `docs/`, `ops/`, `nginx-deploy.conf`, `MASTER_PLAN.md`, `mcp_server_status.md`) for callers of `/vascular` over HTTP. The production adapter uses `/api/v1/vascular-consult`, so expect none active.
2. Delete the `/vascular` GET/HEAD/POST routes from `routes/web.php` and delete `app/Http/Controllers/McpController.php`. Remove the now-dead `validateCsrfTokens(except: ['/vascular', '/vascular/*'])` block from `bootstrap/app.php`.
3. Delete the `/debug-config` and `/debug-test` routes, or wrap them in `if (app()->environment('local')) { … }` if you find evidence they are actively used in ops docs.

**Acceptance:**
```bash
php artisan route:list    # no /vascular, no /debug-* (or only in local env)
php artisan test --testsuite=Unit
```

**If blocked:** If step 1 finds an active consumer of the SSE endpoint, do not delete it. Instead: apply `ValidateApiKey` middleware to both routes, and rewrite `stream()` to exit the loop on `connection_aborted()` and after a hard cap (e.g. 100 iterations ≈ 25 min). Flag the consumer in the PR description.

---

### Task T3: Harden Python bridge network exposure and secret check [HUMAN REVIEW]

**Files:** `ragflow_service/app.py`

**Problem:**
- `app.py:896-898` binds uvicorn to `0.0.0.0:8000`. The bridge fronts the RAGFlow API key; anyone who can reach port 8000 can query RAGFlow.
- The shared secret is optional: if `RAGFLOW_BRIDGE_SECRET` is unset (`app.py:39`), every `if SHARED_SECRET:` guard (e.g. `app.py:114-117`, `229-232`, `453-456`, `844-847`, `870-873`) is skipped and all endpoints are open.
- Secret comparison uses `!=` (non-constant-time) instead of `hmac.compare_digest`.
- The secret check + API-key check block is copy-pasted into all 6 handlers.

**Implement:**
1. Change the `__main__` block to `host = os.getenv("RAGFLOW_BRIDGE_HOST", "127.0.0.1")` and `port = int(os.getenv("RAGFLOW_BRIDGE_PORT", "8000"))`. Only the Laravel app on the same host consumes it (`config/ragflow.php` defaults to `http://localhost:8000`).
2. Add a module-level startup warning (`logger.warning`) when `SHARED_SECRET` is empty, and refuse to serve authenticated endpoints without it unless `RAGFLOW_BRIDGE_ALLOW_INSECURE=1` is set — i.e. return HTTP 503 with detail "bridge secret not configured".
3. Extract a single FastAPI dependency `verify_bridge_auth(request: Request)` that (a) enforces the rule from step 2, (b) compares secrets with `hmac.compare_digest(provided or "", SHARED_SECRET)`, (c) checks `RAGFLOW_API_KEY` is configured. Use it via `Depends(...)` on `/retrieve`, `/retrieval`, `/retrieve_multi`, `/retrieve_dual`, `/datasets`, `/datasets/{dataset_id}`. Leave `/health` and `/status` open.

**Acceptance:**
```bash
python3 -m py_compile ragflow_service/app.py
# If you can run the service locally:
RAGFLOW_BRIDGE_SECRET= python3 ragflow_service/app.py &   # then:
curl -s localhost:8000/health              # 200
curl -s -XPOST localhost:8000/retrieve_dual -d '{}' -H 'Content-Type: application/json'   # 503 (no secret configured)
```

**If blocked:** If the deployment relies on another host reaching the bridge (check `DOCKER_NETWORKING.md`, `docker-compose.yml`, systemd notes in `MAINTENANCE.md`), keep `0.0.0.0` but make the secret mandatory (step 2 without the escape hatch) and note the finding in the PR.

---

### Task T4: Stop logging raw (pre-scrub) clinical text in HTTP logs [HUMAN REVIEW]

**Files:** `app/Http/Middleware/HttpLogging.php`, `app/Http/Controllers/ToolController.php`, `config/logging.php`

**Problem:** PHI scrubbing (`PHIScrubberService`, invoked in `RetrievalService::retrieve()` at `app/Services/RetrievalService.php:31-33`) only protects **outbound** calls. Before that runs:
- `HttpLogging::logRequest()` (`app/Http/Middleware/HttpLogging.php:35-52`) writes the **full request body** (`question`, `history` — free-text patient cases, i.e. PHI) and the full JSON response body to `storage/logs/http-*.log` at debug level, for every request (middleware is appended globally in `bootstrap/app.php:15`).
- `ToolController::consult()` logs the raw question verbatim (`app/Http/Controllers/ToolController.php:57-64`), as do the guardrail/multilingual-retry log lines (`ToolController.php:116-120`, `320-341`, `352-357`).

**Implement:**
1. In `config/logging.php`, add a flag inside the `http` channel section area (or a top-level key): `'http_log_bodies' => env('HTTP_LOG_BODIES', false)`.
2. In `HttpLogging`, when the flag is false (default): replace `request_body` with metadata only — `['question_length' => …, 'history_count' => …, 'keys' => array_keys($body)]` — and replace `response.body` with `['status' => …, 'byte_length' => strlen($content)]`. Keep the existing full-body behavior behind the flag for local debugging.
3. In `ToolController`, change every log line that includes `'question' => $question` (raw request input) to a preview of the **scrubbed** text where available, or `mb_substr($question, 0, 0)`-style metadata: use `'question_length'` + `'question_hash' => substr(sha1($question), 0, 12)` so requests remain correlatable without storing PHI. Do not touch logs in `RetrievalService` — those already log the scrubbed question.

**Acceptance:**
```bash
php artisan test --testsuite=Unit
grep -n "request_body" app/Http/Middleware/HttpLogging.php   # gated by config flag
grep -n "'question' => \$question" app/Http/Controllers/ToolController.php   # no matches
```

**If blocked:** If the operator relies on raw-question HTTP logs for debugging (CLAUDE.md documents tailing retrieval logs, which are scrubbed, so this should not apply), keep raw logging behind `HTTP_LOG_BODIES=true` and default it off — that is exactly what step 2 does; do not invent a third mode.

---

## Phase 2 — Correctness

### Task T5: `AzureOpenAiLlmClient` silently drops the temperature parameter [HUMAN REVIEW]

**Files:** `app/Services/AzureOpenAiLlmClient.php`, `config/prism.php`, `tests/Unit/` (new test)

**Problem:** `complete(string $prompt, int $maxTokens = 150, float $temperature = 0)` (`app/Services/AzureOpenAiLlmClient.php:23`) accepts a temperature but never sends it — the payload at lines 38-50 contains only `messages` and `max_completion_tokens`. Callers explicitly rely on it: `PreRetrievalPlannerService::plan()` passes `config('ragflow.planner.temperature', 0.0)` (`app/Services/PreRetrievalPlannerService.php:24-28`), `ChangeDetectionService::detect()` passes `temperature: 0` (`app/Services/ChangeDetectionService.php:50`). All these calls silently run at the model's default temperature, which makes the "deterministic planner" non-deterministic.

**Implement:**
1. Add `'supports_temperature' => env('AZURE_OPENAI_SUPPORTS_TEMPERATURE', true)` under `providers.azure` in `config/prism.php`. (Some Azure reasoning deployments reject `temperature`; `gpt-5-chat`, the configured default, accepts it.)
2. In `complete()`, include `'temperature' => $temperature` in the request payload when that config flag is true.
3. Add a unit test using `Http::fake()` that asserts the outgoing request body contains `"temperature":0` when the flag is true, and omits it when false (`config()->set('prism.providers.azure.supports_temperature', false)`).

**Acceptance:**
```bash
php artisan test --testsuite=Unit
```

**If blocked:** If the fake-HTTP test can't easily assert the body, use `Http::fake()` + `Http::assertSent(fn ($request) => array_key_exists('temperature', $request->data()))`.

---

### Task T6: `Http::pool` connection failure crashes the fallback router [HUMAN REVIEW]

**Files:** `app/Services/GuidelineRouterService.php`, `tests/Unit/` (new test)

**Problem:** In `selectAndExpand()` (`app/Services/GuidelineRouterService.php:279-296`), the routing call runs inside `Http::pool(...)`. Laravel's pool does **not** throw on connection failure — it stores the `ConnectionException` object in the results array. `$responses['routing']->successful()` on that exception object raises `\Error: Call to undefined method ... ::successful()`, which the surrounding `catch (\Exception $e)` (line 316) does **not** catch (`\Error` is not an `\Exception`). The error propagates uncaught through `RetrievalService::retrieve()` (`app/Services/RetrievalService.php:269` and, via `routeWithContext`, line 155) and returns HTTP 500 to the client. Failure scenario: Azure OpenAI is unreachable → the merged planner already failed (it catches `\Throwable` and returns null) → the legacy fallback path is taken → it crashes too. The exact moment a resilient fallback is needed, it 500s.

Also fix while here: the always-false log condition at lines 249-252 — after `$routingQuery = $this->expandQuery($routingQuery)`, the comparison `$routingQuery !== ($expansionQuery ?? $routingQuery)` compares the variable with itself whenever `$expansionQuery` is null.

**Implement:**
1. After the pool call, guard: `$routing = $responses['routing'] ?? null; if ($routing instanceof \Illuminate\Http\Client\Response && $routing->successful()) { … }` — otherwise log a warning with the exception message (`$routing?->getMessage()` when it is a `Throwable`) and leave `$llmSelected = []` so the existing document/keyword fallbacks run.
2. Change the enclosing `catch (\Exception $e)` at line 316 to `catch (\Throwable $e)`. Do the same for the other LLM call sites in this file that only catch `\Exception` (lines 151, 509, 623, 761) — they guard the same production path.
3. Fix the log condition at 249-252: capture `$beforeExpansion = $routingQuery;` before the reassignment and compare against that.
4. Add a unit test: `Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('refused'))`, call `selectAndExpand('carotid stenosis management', 3)`, assert it returns an array with `routing_method` of `'llm'`/`'fallback'`/`'document_only'` and does not throw.

**Acceptance:**
```bash
php artisan test --testsuite=Unit
```

**If blocked:** If `Http::fake` doesn't propagate the exception into the pool result in this Laravel version, test the guard indirectly by faking a 500 response (assert graceful `[]` selection) and keep the `instanceof` guard — it is correct regardless of testability.

---

### Task T7: Fix three PHI scrubber bugs [HUMAN REVIEW]

**Files:** `app/Services/PHIScrubberService.php`, `tests/Unit/PHIScrubberTest.php`

**Problem (all in `app/Services/PHIScrubberService.php`):**
1. **Wrong counter key** — `scrubAgesOver90()` line 213 increments `$this->redactionCounts['ages']`, but the initialized key is `'ages_over_90'` (line 27). Every age redaction emits an "undefined array key" warning and the count is misattributed.
2. **Token-skip drops a legitimate word** — `scrubNames()` uses both `$skipNext = true` **and** `$i += 2` (lines 388-392). Trace `"John Smith presented with pain"`: split yields `[John][ ][Smith][ ][presented]…`; the name match appends `[NAME]`, jumps `$i` past `Smith`, the following whitespace is appended (whitespace check at line 360 precedes the `$skipNext` check), then `$skipNext` silently discards `presented`. Output: `"[NAME] with pain"` — a clinical word is destroyed.
3. **Unhandled null from `preg_replace_callback`** — every scrub method returns `preg_replace_callback(...)` output directly (e.g. lines 122-129, 301-304, 325-334). On a regex engine error (pathological input hitting backtrack limits) it returns `null`, which then flows into the next scrubber's `string $text` parameter → `TypeError` → 500 on the consult path.

**Implement:**
1. Line 213: change `'ages'` → `'ages_over_90'`.
2. In `scrubNames()`: delete the `$skipNext` mechanism entirely (both the flag and the `if ($skipNext)` block at lines 365-368). The `$i += 2` jump already advances past the last name; the subsequent `$i++` lands on the whitespace after it, which gets appended, and the next real word is preserved.
3. Add a small private helper `safeReplace(?string $result, string $fallback): string { return $result ?? $fallback; }` and wrap every `preg_replace_callback`/`preg_replace` result in the class so a null result falls back to the pre-replacement text (fail-open on scrubbing is wrong for PHI — so instead: log an error via `Log::channel('retrieval')->error(...)` and return the **original text for that one pattern** while continuing with the other scrubbers; document this choice in a comment stating regex failure must not 500 the request).
4. Extend `tests/Unit/PHIScrubberTest.php`: (a) `"John Smith presented with claudication"` scrubs to `"[NAME] presented with claudication"`; (b) an over-90 age string increments `redaction_counts['ages_over_90']` and `array_sum` matches `total_redactions`.

**Acceptance:**
```bash
php artisan test --testsuite=Unit --filter=PHIScrubber
```

**If blocked:** If existing tests encode the buggy "dropped word" behavior, update them — the current behavior corrupts clinical text and is documented as a known bug in CLAUDE.md ("Known Gaps / TODO").

---

### Task T8: Distinguish "upstream failure" from "no evidence" [HUMAN REVIEW]

**Files:** `ragflow_service/app.py`, `app/Services/RetrievalService.php`, `app/Http/Controllers/ToolController.php`

**Problem:** When RAGFlow is down or erroring, the bridge's per-dataset fetchers swallow exceptions and return empty chunk lists (`app.py:302-310` in `/retrieve_multi`, `app.py:551-553` narrative, `app.py:774-776` citations), and `/retrieve_dual` still responds `"status": 200`. Laravel then sees zero chunks and `ToolController::executeRetrieval()` (`app/Http/Controllers/ToolController.php:352-368`) returns the **out-of-scope guardrail message** — telling a clinician their in-scope question has "no relevant ESVS context" when in reality the retrieval backend failed. This is the worst possible failure mode for a clinical decision-support tool: a silent infrastructure failure is presented as an authoritative clinical answer.

**Implement:**
1. **Bridge:** in `/retrieve_dual`, collect per-branch error strings (the `except` blocks already produce `{"error": str(e)}` dicts — propagate them). Add to the response envelope: `"errors": {"narrative": [...], "citation": [...]}` and `"degraded": true` when any fetch failed. If **all** narrative fetches failed **and** the citation fetch failed, return `"status": 502` in the envelope (keep HTTP 200 so the existing Laravel client parsing continues to work — the envelope `status` field is what `RetrievalService` checks).
   Apply the same pattern to `/retrieve_multi`: if every dataset fetch failed, set envelope `"status": 502` and include `"errors"`.
2. **Laravel:** in `RetrievalService::retrieveDualChunks()` (`app/Services/RetrievalService.php:1845-1853`), the existing check `($response['status'] ?? 0) !== 200` will now catch total failure — improve its exception message to include `$response['message'] ?? ''` and the error list. Additionally, when `$response['degraded'] ?? false` is true but chunks exist, log a warning on the `retrieval` channel and pass `'degraded' => true` through the return array of `retrieve()`.
3. **Controller:** in `ToolController::executeRetrieval()`, before the zero-chunk guardrail at line 352, check whether the retrieval result carries `degraded === true` with zero chunks; and wrap the `$this->retrievalService->retrieve(...)` calls (lines 313, 326) in a try/catch for `\RuntimeException`. In both failure cases return a **503** JSON response via `jsonApiResponse(['error' => 'Guideline retrieval backend unavailable — please retry', 'retryable' => true], 503)` instead of the out-of-scope guidance. The out-of-scope guardrail must only fire when retrieval genuinely succeeded and found nothing.
4. Keep the response shape for genuine no-evidence cases unchanged (the OpenWebUI adapter depends on it).

**Acceptance:**
```bash
python3 -m py_compile ragflow_service/app.py
php artisan test --testsuite=Unit
# New feature test (add it): fake the RAGFlow facade/bridge returning ['status' => 502, ...]
# and assert POST /api/v1/vascular-consult returns 503 with 'retryable' => true,
# NOT a 200 with out-of-scope guidance.
```

**If blocked:** If the OpenWebUI adapter (`openwebui_tools/vascular_mcp_adapter.py`) cannot handle a 503 (check its response handling), return HTTP 200 with a distinct `guardrail.type = 'retrieval_backend_unavailable'` and message text that explicitly says the backend failed — the non-negotiable part is that infrastructure failure must not masquerade as "your question is out of scope".

---

## Phase 3 — Bridge robustness

### Task T9: Align Laravel ↔ bridge ↔ RAGFlow timeouts [HUMAN REVIEW]

**Files:** `config/ragflow.php`, `app/Services/RAGFlow/RAGFlowClient.php`, `ragflow_service/app.py`, `.env.example`

**Problem:** The Laravel Guzzle client timeout is `config('ragflow.request_timeout')` = **30 s** default (`config/ragflow.php:6`, consumed in `RAGFlowServiceProvider` and `RAGFlowClient::__construct` at `app/Services/RAGFlow/RAGFlowClient.php:39`). The Python bridge gives each upstream RAGFlow call **60 s** (`httpx.AsyncClient(timeout=60.0)` at `app.py:158`, `314`, `779`) and the citation path can fire a *second* 60 s retry (`app.py:747-767`). So under RAGFlow slowness, Laravel aborts at 30 s (client-facing 500) while the bridge keeps burning workers for up to ~120 s on a request nobody is waiting for. There is also no `connect_timeout` on the Guzzle client, so a black-holed bridge eats the full 30 s before failing.

**Implement:**
1. In `ragflow_service/app.py`, replace the hardcoded `60.0` timeouts with `UPSTREAM_TIMEOUT = float(os.getenv("RAGFLOW_UPSTREAM_TIMEOUT", "25"))` used by all three `httpx.AsyncClient(...)` constructions, and make the citation retry reuse the same client/timeout (it already does — it just needs the shorter budget).
2. In `RAGFlowClient::__construct`, add `'connect_timeout' => (int) config('ragflow.connect_timeout', 5)` to the Guzzle options, and add `'connect_timeout' => env('RAGFLOW_CONNECT_TIMEOUT', 5)` to `config/ragflow.php`.
3. Set the invariant **Laravel timeout > bridge worst case**: bridge worst case is `UPSTREAM_TIMEOUT × 2` (citation retry) ≈ 50 s, so raise the Laravel default: `'request_timeout' => env('RAGFLOW_REQUEST_TIMEOUT', 60)` in `config/ragflow.php:6`.
4. Document all three knobs in `.env.example` with one comment line stating the invariant (`RAGFLOW_REQUEST_TIMEOUT > 2 × RAGFLOW_UPSTREAM_TIMEOUT`).

**Acceptance:**
```bash
python3 -m py_compile ragflow_service/app.py
php artisan test --testsuite=Unit
grep -n "request_timeout\|connect_timeout" config/ragflow.php
grep -n "UPSTREAM_TIMEOUT" ragflow_service/app.py
```

**If blocked:** If production `.env` already sets `RAGFLOW_REQUEST_TIMEOUT` (CLAUDE.md suggests tuning happened around 60 s timeouts), only change defaults + docs; the deployed value wins and the human reviewer decides the rollout value.

---

### Task T10: Add bounded connect-retry to both HTTP hops [HUMAN REVIEW]

**Files:** `app/Services/RAGFlow/RAGFlowClient.php`, `ragflow_service/app.py`

**Problem:** Neither hop retries transient connection failures. Laravel→bridge: `RAGFlowClient::request()` (`app/Services/RAGFlow/RAGFlowClient.php:44-117`) wraps a single attempt; a bridge restart (systemd `laravel-api.service` / `ragflow-bridge.service` bounce) fails every in-flight consult. Bridge→RAGFlow: `httpx.AsyncClient` is constructed with no transport retries. Retrieval POSTs are read-only/idempotent, so connect-level retries are safe.

**Implement:**
1. **Laravel:** in `RAGFlowClient::__construct`, build the Guzzle client with a handler stack containing `GuzzleHttp\Middleware::retry()`: retry **only** `GuzzleHttp\Exception\ConnectException` (never read timeouts — the work may still complete upstream), max 2 retries, delay `100ms * attempt`. Log each retry to the `ragflow` channel.
2. **Bridge:** construct clients as `httpx.AsyncClient(timeout=UPSTREAM_TIMEOUT, transport=httpx.AsyncHTTPTransport(retries=1))` — httpx transport retries apply to connection establishment only, which is exactly the safe scope.

**Acceptance:**
```bash
python3 -m py_compile ragflow_service/app.py
php artisan test --testsuite=Unit
# Manual: stop the bridge, curl the consult endpoint, confirm the error appears after ~2 quick
# connect attempts (log lines in storage/logs/ragflow-*.log), not a hang.
```

**If blocked:** If the installed Guzzle version's retry middleware signature differs, implement the retry loop manually inside `request()` around the single `ConnectException` case — do not add a new dependency.

---

## Phase 4 — Performance

### Task T11: Remove wasted duplicate LLM routing call on legacy path [HUMAN REVIEW]

**Files:** `app/Services/RetrievalService.php`

**Problem:** On the legacy (non-planner) path, routing already happened via `routeWithContext()` → `selectAndExpand()` at `app/Services/RetrievalService.php:155` (one Azure OpenAI call, 10 s timeout). Then lines 266-273 call `$router->selectAndExpand($retrievalQuestion, 3, null, null)` **again**, discarding its `selected` result and using only `expanded` — which `selectAndExpand` computes with the regex-only `expandQuery()` (LLM expansion is disabled; see `GuidelineRouterService.php:277-291, 299-300, 361-364`). Net effect: one full wasted LLM routing round-trip (~1-2 s, the `expand_ms` figure in `[PRE-RETRIEVAL TIMING]`) per legacy-path request — including every request where the planner fails and falls back, and every explicitly-routed request (where line 151's routing was skipped but line 269 still fires the LLM).

**Implement:**
1. Replace lines 266-273's `selectAndExpand` call with the regex expansion directly:
   ```php
   $t0 = microtime(true);
   $expandedQuery = $router->expandQuery($retrievalQuestion);
   $preRetrievalTimings['expand_ms'] = (int) round((microtime(true) - $t0) * 1000);
   ```
   keeping the two subsequent `buildCitationQuery(...)` lines exactly as they are. (`expandQuery()` is already `public`.)
2. Reuse the `$router` instance created at line 152 when it exists instead of constructing a new one at line 267 (guard for the `requestedKeys` branch where it wasn't created).
3. Do not change the planner branch (lines 274-279).

**Acceptance:**
```bash
php artisan test --testsuite=Unit
php artisan test --filter=RetrievalServiceFocus 2>/dev/null || true   # needs live creds until T13; skip if offline
grep -n "selectAndExpand" app/Services/RetrievalService.php    # no remaining call sites
```

**If blocked:** If a Feature test asserts `expanded_query` contains LLM-added synonyms, it is asserting dead behavior (expansion is regex-only inside `selectAndExpand` too) — update the test, citing `GuidelineRouterService.php:290` ("Expansion call REMOVED").

---

### Task T12: DI/singleton refactor + per-request file-I/O caching [HUMAN REVIEW]

**Files:** `app/Services/RetrievalService.php`, `app/Http/Controllers/ToolController.php`, `app/Providers/AppServiceProvider.php`, `app/Providers/RAGFlowServiceProvider.php`, `app/Services/PHIScrubberService.php`, `app/Services/GuidelineAssetService.php`, `app/Services/GuidelineRouterService.php`, `app/Services/GraphRagService.php`, `app/Services/ClinicalInterpreterService.php`, `app/Services/ClinicalGateService.php`

**Problem:**
- `RetrievalService::retrieve()` constructs collaborators inline: `new PHIScrubberService` (line 31), `new GraphRagService` (37), `new ClinicalInterpreterService` (39), `new GuidelineRouterService` (85, 152, 219, 267 — up to four instances per request), `new TaxonomyExpanderService` (334), `new GapDetectionService` (373), `new ChunkSelectionService` (477), `new BridgeRerankService` (1807). `ToolController` does `new GapDetectionService()` (line 554) and `new ClinicalGateService()` (line 610). This defeats container substitution in tests and repeats setup cost.
- Per-request file I/O: `PHIScrubberService::loadNames()/loadMajorCities()` read + json-decode two JSON dictionaries on **every request** (lines 44-79); `GuidelineAssetService::loadManifest()` re-reads the asset manifest per request (lines 369-389).
- `env()` fallbacks in constructors of `GuidelineRouterService` (18-21), `GraphRagService` (18-21), `ClinicalInterpreterService` (18-21), `ClinicalGateService` (25-28): under `php artisan config:cache`, `env()` returns null — these fallbacks are dead code and a footgun inviting future `env()`-only config.
- `RAGFlowServiceProvider::register()` calls `mergeConfigFrom(config_path('ragflow.php'), 'ragflow')` (lines 12-15) — merging the app's own config file into itself is a no-op.

**Implement:**
1. Promote the stateless services to constructor-injected dependencies of `RetrievalService` (promoted readonly properties), and register them as singletons where safe: `PHIScrubberService`, `GraphRagService`, `ClinicalInterpreterService`, `TaxonomyExpanderService`, `GapDetectionService`, `ChunkSelectionService`, `BridgeRerankService`, `GuidelineRouterService` in `AppServiceProvider::register()`. **Caution:** `PHIScrubberService` keeps per-scrub state (`redactionCounts`) but `scrub()` calls `resetCounts()` first, so singleton is safe for the current single-threaded FPM model — add a one-line comment noting it is not safe under concurrent-request runtimes (Octane).
2. Inject `GapDetectionService` and `ClinicalGateService` via the `ToolController` constructor; delete the `new …()` calls at lines 554 and 610. Also delete the `new GapDetectionService()` inside `buildConsultOutput()` (line 554) — pass the injected instance down or make it a property.
3. Cache the file loads: in `PHIScrubberService`, make the name/city arrays `static` properties loaded once per process; in `GuidelineAssetService::loadManifest()`, memoize in a static property keyed by path + filemtime.
4. Remove the `?: env(...)` fallbacks from the four service constructors (keep the `config(...)` reads).
5. Delete the no-op `mergeConfigFrom` from `RAGFlowServiceProvider`.
6. No behavior changes: identical inputs must produce identical outputs. Run the full unit suite before and after; diff any golden outputs if `tests/golden_dataset.php` is wired into a runnable command (`php artisan` list shows `RunGoldenSuite`).

**Acceptance:**
```bash
php artisan test --testsuite=Unit
php artisan config:cache && php artisan config:clear    # boots cleanly with cached config
grep -rn "new GuidelineRouterService\|new PHIScrubberService\|new GapDetectionService\|new ClinicalGateService" app/   # no matches
```

**If blocked:** If a service turns out to be stateful across calls in a way `resetCounts()`-style reinit doesn't cover, bind it `scoped()` instead of `singleton()` and note why.

---

## Phase 5 — Maintainability

### Task T13: Make the Feature test suite runnable offline

**Files:** `tests/Feature/LeanRetrievalTest.php`, `tests/Feature/RetrievalServiceFocusTest.php`, `tests/Feature/ConfirmationGuidelineRefreshTest.php`, `phpunit.xml`, `tests/TestCase.php`

**Problem:** The Feature tests post real questions to `/api/v1/vascular-consult` with no `Http::fake()` and no facade fakes (verified: zero `Http::fake` occurrences under `tests/`), so they require live Azure OpenAI + RAGFlow credentials and network. `composer test` / CI cannot run them, which means the request path effectively has no automated regression coverage.

**Implement:**
1. Add a helper in `tests/TestCase.php` (or a trait) that fakes the two external surfaces: (a) `Http::fake()` with a catch-all Azure OpenAI response returning a valid routing/planner JSON body, and (b) `$this->swap('ragflow', $fakeClient)` / `RAGFlow::shouldReceive(...)` returning a canned `retrieve_dual` envelope (`status: 200`, a couple of narrative + citation chunks shaped like `ragflow_service/app.py`'s response — copy a realistic envelope from `curl_output.json` in the repo root).
2. Set a dummy `API_SECRET_KEY` via `phpunit.xml` `<env name="API_SECRET_KEY" value="test-key"/>` so `ValidateApiKey` passes deterministically.
3. Convert the existing Feature tests to use the fakes. Tests that genuinely exercise live quality (golden suite) belong to the `RunGoldenSuite` artisan command, not phpunit — if any Feature test is irreducibly live, move it to a `#[Group('integration')]` and exclude that group in `phpunit.xml` defaults.
4. Add one new Feature test for the T8 behavior (backend-unavailable → 503/`retryable`, not out-of-scope guidance).

**Acceptance:**
```bash
# With network access disabled (unset AZURE_OPENAI_*, RAGFLOW_* env vars):
php artisan test     # fully green, no skipped-due-to-network failures
```

**If blocked:** If faking the `ragflow` singleton is awkward because `RAGFlowServiceProvider` throws when `RAGFLOW_API_KEY` is empty (`app/Providers/RAGFlowServiceProvider.php:22-26`), set dummy `RAGFLOW_API_KEY`/`RAGFLOW_ENDPOINT` in `phpunit.xml` and fake at the Guzzle/HTTP layer instead.

---

### Task T14: Small hygiene cleanups

**Files:** `app/Http/Controllers/ToolController.php`, `app/Services/RAGFlow/DatasetResource.php`

**Problem / Implement (all low-risk, no behavior change):**
1. `ToolController::normalize()` comment (lines 624-626) claims "Full LLM-based normalisation happens inside RetrievalService via GuidelineRouterService" — after the planner rollout this happens via `PreRetrievalPlannerService` when enabled. Correct the comment.
2. `ToolController` hand-rolls identical CORS headers in `jsonApiResponse()` (591-597) and `clinicalGate()` (613-616); make `clinicalGate()` use `jsonApiResponse()`.
3. `DatasetResource::resolveDatasetName()` uses a `static` local cache (line 128) that never invalidates within a process; convert to an instance property for consistency with T12's caching conventions (or add a comment stating process-lifetime caching is intended).
4. `ToolController::validateGuideline()` (637-712) rebuilds the full name/keyword maps on every call; memoize the maps in a static/instance property.

**Acceptance:**
```bash
php artisan test --testsuite=Unit
vendor/bin/pint --dirty     # formatting clean
```

**If blocked:** Skip any sub-item that turns out to require behavior decisions; these are cosmetic.

---

## Audit notes for the reviewer (context, not tasks)

- `composer audit` could not be run in the audit environment (no PHP toolchain); T1's advisory list was compiled from Packagist's security-advisories API against `composer.lock` on 2026-07-13. Codex must re-verify with `composer audit` as T1 step 1.
- Known-good properties confirmed during audit (do not "fix"): API-key check uses `hash_equals` with a config (not env) read; proxy trust is pinned to `127.0.0.1`; history is sanitized (control-strip + truncate) before LLM prompt inclusion in `GuidelineRouterService::fuseContext()`; the bridge clamps `top_k`/`size` server-side; `PreRetrievalPlannerService` fails closed to the legacy chain.
- Deliberate non-tasks: the 490-line `RetrievalService::retrieve()` method wants decomposition, but a structural rewrite is high-risk relative to payoff and is intentionally excluded; T12 limits itself to DI mechanics.
