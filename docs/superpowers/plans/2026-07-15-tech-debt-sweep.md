# Tech-Debt Sweep — Sprint Close-Out Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Clear the residual tech debt left by the seven-brief core-thinning/hardening program (v0.0.27 + v0.0.28) in one comprehensive, low-risk sweep.

**Architecture:** Six independent work-groups, each landing as its own commit on `chore/tech-debt-sweep`. Five groups are deletion / prompt-string / test-move / additive-test work; two (A4 Router type, E2 API error shape) touch contracts and are handled with extra care. Sequencing constraint: the behavioral fixes (Group 5 / Tier E) land **before** the tests that assert them (Group 4 / Tier D), so commit order is **G1 → G2 → G3 → G5 → G4 → G6** (still six commits, one per group).

**Tech Stack:** PHP 8.4 (strict types, `final` by default), Pest (tests), PHPStan L8 (`composer analyse`), Composer. Source-of-truth self-model map is `config/source.json`, parsed by `src/Toolkit/CoquiSourceToolkit.php`.

## Global Constraints

- Work ONLY in the worktree `/home/carmelo/Projects/CoquiBot/Core/coqui-tech-debt-sweep` on branch `chore/tech-debt-sweep`. Never touch the primary checkout at `/home/carmelo/Projects/CoquiBot/Core/coqui`.
- Every PHP file keeps `declare(strict_types=1);`, `final` by default, 4-space indent, one class per file, constructor injection.
- `composer test` (Pest) must stay green; `composer analyse` (PHPStan L8) must stay clean — no new errors.
- `config/source.json` MUST remain valid JSON after every edit. Validate with `php -r 'json_decode(file_get_contents("config/source.json"), true, 512, JSON_THROW_ON_ERROR); echo "OK\n";'` after each source.json change.
- Six groups = six separate commits. The spec **and this plan** live on `main` (committed there before the build), so the branch `chore/tech-debt-sweep` contains **exactly the six work-group commits** and nothing else — do not add a docs/plan commit to the branch. Push `chore/tech-debt-sweep` at the end. Do NOT merge, do NOT open a PR, do NOT run `git checkout` in the primary checkout.
- Prefer `rg` over `grep` for searches. Class-name occurrence counts are candidate evidence only; `composer analyse` is the authority for whether a removed import/symbol was actually used (it errors on an undefined symbol).
- `composer regen-docs` if any doc-index input changed (docs/*.md headings) — but do NOT commit `config/documentation.json` (generated + git-ignored).
- Out of scope: the `coqui-toolkit-webhooks` repo; the pre-existing `CredentialHandler` missing-test gap; any refactor beyond the listed items; no loop/question semantic changes beyond E1/E3.

## Pre-flight Baseline (run once, before Task 1)

- [ ] **Step P1: Confirm branch + clean tree**

Run: `cd /home/carmelo/Projects/CoquiBot/Core/coqui-tech-debt-sweep && git status && git rev-parse --abbrev-ref HEAD`
Expected: branch `chore/tech-debt-sweep`, clean working tree.

- [ ] **Step P2: Capture green baseline**

Run: `composer test 2>&1 | tail -15`
Expected: all green. Record the "Tests: N passed (M assertions)" line — this is the baseline the final count is compared against.

- [ ] **Step P3: Capture clean analyse baseline**

Run: `composer analyse 2>&1 | tail -15`
Expected: `[OK] No errors`. This is the PHPStan baseline; Task 1 (A4) must not regress it.

---

## Task 1 — Group 1: Agent-facing correctness (Tier A)

**Files:**
- Modify: `config/roles/plan.md:30`
- Modify: `src/Toolkit/WebToolkit.php:60,327`
- Modify: `config/roles/coder.md:18`
- Modify: `src/Api/Router.php:39,63`

**Interfaces:**
- Produces: corrected `addRoute`/`addPublicRoute` docblock type `callable(ServerRequestInterface, string ...): Response`. No runtime signature change — coqui handlers already take `($req, string $x)`, so this is a docblock-only type correction that PHPStan reads.

- [ ] **Step 1: Fix `config/roles/plan.md:30` — dead `start_background_task` guidance**

Current line 30:
```
- For large tasks, use `start_background_task(role: "explorer")` to investigate subsystems in parallel.
```
Replace with (the surviving goal-driven loop mechanism; see `docs/CONFIGURATION.md:335`):
```
- For large tasks, use `loop_start` with a goal to drive multi-stage investigation of subsystems.
```

- [ ] **Step 2: Fix `src/Toolkit/WebToolkit.php:60` — stale `task_status` guideline**

Current line 60 (`$downloadLine`):
```php
            ? "\n        - Use http_download to queue file downloads into the workspace downloads directory, then monitor them with task_status."
```
Replace the trailing clause so it no longer references the removed `task_status` tool:
```php
            ? "\n        - Use http_download to queue file downloads into the workspace downloads directory."
```

- [ ] **Step 3: Fix `src/Toolkit/WebToolkit.php:327` — stale runtime `message`**

Current line 327:
```php
            'message' => 'Background download queued. Use task_status to monitor progress.',
```
Replace with:
```php
            'message' => 'Background download queued.',
```

- [ ] **Step 4: Fix `config/roles/coder.md:18` — removed `packagist` tool**

Current line 18:
```
- **Search before building.** Check Packagist (`packagist`) for existing solutions before writing from scratch.
```
Replace (drop the back-ticked removed tool name; `composer` still survives via shell, mentioned line 20):
```
- **Search before building.** Check packagist.org for existing solutions before writing from scratch.
```

- [ ] **Step 5: Fix `src/Api/Router.php:39` and `:63` — handler param type**

At `src/Api/Router.php:39` (docblock of `addRoute`), change:
```php
     * @param callable(ServerRequestInterface, array<string, string>): Response $handler
```
to:
```php
     * @param callable(ServerRequestInterface, string ...): Response $handler
```
At `src/Api/Router.php:63` (docblock of `addPublicRoute`), make the identical change (same old line → same new line).

Rationale: `doDispatch` (`Router.php:206-211`) builds `$params` then invokes `($route['handler'])($req, ...$params)` — each captured path segment is spread as an **individual named string argument**, not a single array. The `string ...` variadic type is the truthful contract.

- [ ] **Step 6: Verify PHPStan stays clean (A4 care-item)**

Run: `composer analyse 2>&1 | tail -15`
Expected: `[OK] No errors` — identical to the P3 baseline. Confirm no new errors introduced by the type change (coqui's own handlers all take `($req, string $x)` and are compatible; `string ...` accepts zero-param routes and multi-param routes alike).

> Scope note: "analyse stays clean" is the proof available **within coqui**. The intended downstream payoff — the webhooks mod dropping its `@phpstan-ignore argument.type` — can only be verified in that separate repo against a released coqui tag, and is explicitly out of scope here. Do not attempt to prove the downstream removal from this repo.

- [ ] **Step 7: Verify no lingering dead-tool references introduced**

Run: `grep -rn "task_status\|start_background_task" config/roles/plan.md src/Toolkit/WebToolkit.php`
Expected: no matches.

- [ ] **Step 8: Run full test suite**

Run: `composer test 2>&1 | tail -15`
Expected: green, same count as P2 baseline (no test touches these strings).

- [ ] **Step 9: Commit**

```bash
git add config/roles/plan.md src/Toolkit/WebToolkit.php config/roles/coder.md src/Api/Router.php
git commit -m "fix(tech-debt): correct agent-facing prompts and Router handler type

- plan.md: replace removed start_background_task guidance with loop_start
- WebToolkit: drop stale task_status monitoring instruction (guideline + runtime message)
- coder.md: drop removed packagist tool reference
- Router: type route handlers as callable(ServerRequestInterface, string ...): Response
  to match doDispatch's ...\$params spread (unblocks mod @phpstan-ignore removal)"
```

---

## Task 2 — Group 2: Self-model map (`config/source.json`) — fix errors + mark selective

**Files:**
- Modify: `config/source.json` (self-description, `layers` object, delete 2 entries, fix 1 layer, add 15 entries)

**Interfaces:**
- Consumes: nothing.
- Produces: a valid, self-consistent source map. Note: Task 3 also edits `source.json` (removing dead-code entries); this task lands first, so line numbers below are pre-Task-3.

> **CRITICAL:** After EVERY edit in this task, run the JSON-validity check (Step 9) before moving on. `CoquiSourceToolkit` parses the whole file; a single trailing comma breaks the agent's self-navigation.

- [ ] **Step 1: Rewrite the self-description (B4) — no longer "every core source file"**

At `config/source.json:2`, change:
```json
    "description": "Coqui source map — describes every core source file so the agent can understand its own codebase.",
```
to:
```json
    "description": "Coqui source map — a selective, load-bearing map of the core source so the agent can navigate its own codebase. Intentionally excludes fine-grained subtrees (src/Renderer/Ansi/*, Stub* test doubles) and minor support/observer/React-provider files; not exhaustive.",
```

- [ ] **Step 2: Add the two missing layer keys (B3)**

In the top-level `layers` object (`config/source.json:4-17`, currently keys `agent, command, config, contract, exception, tool, toolkit, observer, storage, support, prompt, question`), add two entries. Insert after the `command` line (keep JSON valid; match the existing description style of sibling layers):
```json
        "api": "HTTP API request handlers and API-server support (routing, middleware, endpoint handlers).",
        "provider": "LLM provider adapters and provider-selection support.",
```

- [ ] **Step 3: Delete the dead `SummarizeHandler` entry (B1)**

Delete the entire entry object at `config/source.json:2076-2084` (path `src/Api/Handler/SummarizeHandler.php`, fqcn `...SummarizeHandler`, layer `command`, advertising the dead `POST /sessions/{id}/summarize` route). The file was deleted with the backstory subsystem; the live path `GET /sessions/{id}/summary` → `SessionHandler::summary` is already mapped. Remove the object and its trailing/leading comma cleanly. Do NOT re-point.

- [ ] **Step 4: Delete the duplicate/fabricated `ScheduleManager` entry (B2)**

Delete the entire entry object at `config/source.json:3135-3145` (layer `agent`, methods `checkCompletedTasks`/`trigger`/`setOnNotify` — none exist on `src/Api/ScheduleManager.php`, whose only public methods are `tick()`/`reconcile()`). KEEP the correct entry at `:2872-2880` (layer `api`, `tick()`/`reconcile()`).

- [ ] **Step 5: Fix `BudgetHandler` layer (B3)**

At the `BudgetHandler` entry (`config/source.json:2085-2093`, path `src/Api/Handler/BudgetHandler.php`), change `"layer": "command"` to `"layer": "api"`.

- [ ] **Step 6: Add the 9 load-bearing class/handler entries (B4)**

Add these entries near their logical siblings (Config entries near other `src/Config/*` entries, Command near `src/Command/*`, Api/Handler near other `src/Api/Handler/*`). Use the standard entry shape `{path, fqcn, layer, description, methods}`.

**Populate `methods` with 1–2 representative public methods for these orchestration/config/handler classes** — they are the ones an agent navigates *to*, so empty methods waste the entry. Read each class and copy its actual signatures into a short `"methods"` array (e.g. BootManager's `boot()`, ConfigManager's `load()`/`save()`, AgentRunnerFactory's `create()`, TurnRunCommand's `execute()`, each handler's route methods). The JSON below shows `"methods": []` as a placeholder — replace it with real representative signatures (do NOT invent; read the file). Only the pure value objects in Step 7 stay empty.

```json
        {
            "path": "src/Config/BootManager.php",
            "fqcn": "CoquiBot\\Coqui\\Config\\BootManager",
            "layer": "config",
            "description": "Handles the Coqui boot sequence: config loading, workspace init, credential resolution, storage, roles, toolkit discovery, and memory. Extracted from RunCommand.",
            "methods": []
        },
        {
            "path": "src/Config/ConfigManager.php",
            "fqcn": "CoquiBot\\Coqui\\Config\\ConfigManager",
            "layer": "config",
            "description": "Single source of truth for openclaw.json — resolves path, loads, saves; seeds from project root/DefaultsLoader on first boot.",
            "methods": []
        },
        {
            "path": "src/Config/ConfigValidator.php",
            "fqcn": "CoquiBot\\Coqui\\Config\\ConfigValidator",
            "layer": "config",
            "description": "Validates openclaw.json data; returns a list of human-readable error strings (empty list = valid).",
            "methods": []
        },
        {
            "path": "src/Config/ConfigGuard.php",
            "fqcn": "CoquiBot\\Coqui\\Config\\ConfigGuard",
            "layer": "config",
            "description": "Security guardrails for agent-driven config edits; denies sensitive sections (blacklist, shell allowlist, API keys, workspace path, mounts).",
            "methods": []
        },
        {
            "path": "src/Command/AgentRunnerFactory.php",
            "fqcn": "CoquiBot\\Coqui\\Command\\AgentRunnerFactory",
            "layer": "command",
            "description": "Builds an AgentRunner and its dependencies from a booted BootManager. Static factory shared by REPL, API, and turn/task commands.",
            "methods": []
        },
        {
            "path": "src/Command/TurnRunCommand.php",
            "fqcn": "CoquiBot\\Coqui\\Command\\TurnRunCommand",
            "layer": "command",
            "description": "Runs a single interactive agent turn in an isolated child process (spawned via proc_open) and persists events for SSE streaming.",
            "methods": []
        },
        {
            "path": "src/Api/Handler/ProjectHandler.php",
            "fqcn": "CoquiBot\\Coqui\\Api\\Handler\\ProjectHandler",
            "layer": "api",
            "description": "Project discovery/management API endpoints: list, create, get, patch, delete, archive, activate.",
            "methods": []
        },
        {
            "path": "src/Api/Handler/SessionProjectHandler.php",
            "fqcn": "CoquiBot\\Coqui\\Api\\Handler\\SessionProjectHandler",
            "layer": "api",
            "description": "Session-scoped active-project endpoints: GET/PATCH /sessions/{id}/project.",
            "methods": []
        },
        {
            "path": "src/Api/Handler/ToolkitHandler.php",
            "fqcn": "CoquiBot\\Coqui\\Api\\Handler\\ToolkitHandler",
            "layer": "api",
            "description": "Toolkit visibility management endpoints: list toolkits and set visibility.",
            "methods": []
        }
```

> Note: `src/Repl/Handler/ProjectHandler.php` is already mapped (`:541`); the entry above is the distinct **API** handler. Keep both.

- [ ] **Step 7: Add the 6 Contract value-object entries (B4)**

Add near the other `src/Contract/*` entries, layer `contract`:
```json
        {
            "path": "src/Contract/BackgroundTaskSummary.php",
            "fqcn": "CoquiBot\\Coqui\\Contract\\BackgroundTaskSummary",
            "layer": "contract",
            "description": "Immutable snapshot of active background tasks for footer rendering (separates agent vs tool tasks).",
            "methods": []
        },
        {
            "path": "src/Contract/CodeReviewResult.php",
            "fqcn": "CoquiBot\\Coqui\\Contract\\CodeReviewResult",
            "layer": "contract",
            "description": "Immutable result of a code review cycle returned by CodeReviewCycle::run() (output, verdict, usage).",
            "methods": []
        },
        {
            "path": "src/Contract/DeferredWorkQueue.php",
            "fqcn": "CoquiBot\\Coqui\\Contract\\DeferredWorkQueue",
            "layer": "contract",
            "description": "Holds cheap in-process closures to run after the stats summary renders.",
            "methods": []
        },
        {
            "path": "src/Contract/LoopParameterDefinition.php",
            "fqcn": "CoquiBot\\Coqui\\Contract\\LoopParameterDefinition",
            "layer": "contract",
            "description": "Declares a template parameter for a loop definition ({{variable}} substitution).",
            "methods": []
        },
        {
            "path": "src/Contract/ReviewVerdict.php",
            "fqcn": "CoquiBot\\Coqui\\Contract\\ReviewVerdict",
            "layer": "contract",
            "description": "Enum verdict from an automated code review pass; parses a structured marker from reviewer text.",
            "methods": []
        },
        {
            "path": "src/Contract/ToolkitVisibility.php",
            "fqcn": "CoquiBot\\Coqui\\Contract\\ToolkitVisibility",
            "layer": "contract",
            "description": "Three-tier visibility enum (Enabled/Stub/Disabled) for toolkits and tools, with ALWAYS_ENABLED / CANNOT_DISABLE protection tiers.",
            "methods": []
        }
```

> If the existing sibling entries always populate `methods`, keep `"methods": []` (empty is valid) rather than inventing signatures — these are additive stubs to fix the "every file" claim, not full API docs. If the implementer prefers, a single representative method line per class is acceptable, but empty is fine and lower-risk.

- [ ] **Step 8: Validate JSON**

Run: `php -r 'json_decode(file_get_contents("config/source.json"), true, 512, JSON_THROW_ON_ERROR); echo "OK\n";'`
Expected: `OK`.

- [ ] **Step 9: Verify the map loads via `CoquiSourceToolkit`**

Run: `php -r 'require "vendor/autoload.php"; $r = new ReflectionClass(\CoquiBot\Coqui\Toolkit\CoquiSourceToolkit::class); echo $r->getFileName(), "\n";' && grep -n "source.json\|json_decode\|file_get_contents" src/Toolkit/CoquiSourceToolkit.php | head`
Then confirm parse path is exercised by the existing toolkit test if present:
Run: `composer test -- --filter=CoquiSource 2>&1 | tail -15`
Expected: green (or "no tests" — if no dedicated test, the JSON-validity check in Step 8 is the gate).

- [ ] **Step 10: Confirm deletions + additions landed**

Run: `grep -c "SummarizeHandler" config/source.json` → expect `0`.
Run: `grep -c "checkCompletedTasks" config/source.json` → expect `0`.
Run: `grep -c "BootManager\|ToolkitVisibility\|SessionProjectHandler" config/source.json` → expect `>=3`.
Run: `grep -A1 '"BudgetHandler"' config/source.json` — sanity check; then `grep -n '"layer": "api"' config/source.json | wc -l` should have increased.

- [ ] **Step 11: Commit**

```bash
git add config/source.json
git commit -m "fix(tech-debt): correct and mark source.json selective

- delete dead SummarizeHandler entry (file removed with backstory)
- delete duplicate/fabricated ScheduleManager entry (fake methods)
- add missing api + provider layer keys; set BudgetHandler layer to api
- add 15 load-bearing entries (BootManager, ConfigManager/Validator/Guard,
  AgentRunnerFactory, TurnRunCommand, 3 API handlers, 6 Contract value objects)
- rewrite self-description: selective/load-bearing, not every-file"
```

---

## Task 3 — Group 3: Dead-code removal (Tier C)

**Files:**
- Delete: `src/Renderer/Ansi/SoftBreakRenderer.php`
- Delete: `src/Api/AgentFiberExecutor.php`
- Delete: `src/Renderer/NullRenderer.php` (verified unreferenced)
- Delete: `src/Renderer/Sparkline.php` + `tests/Unit/Renderer/SparklineTest.php`
- Delete: `src/Utility/SecretMasker.php` (C4 decision: REMOVE — see rationale below)
- Modify: `src/Storage/LoopStore.php:574-577` (docblock)
- Modify: `config/source.json` (remove entries for removed classes)
- Modify: 15 source files (remove 23 unused `use` imports)

**Interfaces:**
- Consumes: Task 2's already-edited `source.json` (line numbers shifted; re-grep, do not trust old line numbers).
- Produces: no symbol removed here is referenced anywhere post-removal.

**C4 SecretMasker decision — REMOVE (rationale, to be restated in the commit body + final report):** `SecretMasker::mask()` is called from no production code. Remove it as an unused, misleading security helper — but **do not** justify removal with "it's local SQLite so it's safe." That is too strong: `SessionStorage::getAuditLog()` (`src/Storage/SessionStorage.php:1791`) is a public read path and local persistence is still a secret-retention boundary. The honest, correct rationale:
- `SecretMasker::mask()` redacts a **single known substring**, but the audit boundary `SessionStorage::logAudit()` (`:1756`) receives **arbitrary nested argument structures** (tool args serialized as JSON).
- No current caller supplies masking metadata, and there is no schema of which keys are sensitive.
- Wiring this single-string helper at `logAudit()` would therefore be **incomplete and give false confidence** — worse than not masking, because it would look handled.
- Real redaction needs a recursive, schema-aware system (sensitive-key matching) plus documented audit-retention semantics. That is a **separate design, out of scope** for this sweep.
- The four `error_log` sites log only exception message + file/line (no secrets); the credentials API **hides** values entirely (`CredentialHandler`) rather than masking; the only live masking is *display* sanitization (`SetupWizard.php:573`, `ConfigTool` config-show), inline and out of scope.

Conclusion: remove the unused helper now, and a **separate security follow-up** is tracked to decide whether audit arguments require structured redaction (do NOT resolve that here). **The implementer/reviewer must re-confirm this investigation before deleting** (Step 6).

- [ ] **Step 1: Remove `SoftBreakRenderer` (C1)**

Run: `git rm src/Renderer/Ansi/SoftBreakRenderer.php`
It is the only CommonMark renderer never registered in `AnsiRendererExtension.php:48-70`; its `AbstractStringContainer` import is unused and goes with the file. Confirm not registered:
Run: `grep -n "SoftBreak" src/Renderer/Ansi/AnsiRendererExtension.php` → expect no match.

- [ ] **Step 2: Remove `AgentFiberExecutor` (C2)**

Run: `git rm src/Api/AgentFiberExecutor.php`
Documented v1 fiber placeholder; real execution is out-of-process (`turn:run`/`task:run`), no `Fiber::suspend` path exists.

- [ ] **Step 3: Remove `NullRenderer` (C5)**

Verify unreferenced first:
Run: `grep -rn "NullRenderer" src tests config bin scripts | grep -v "source.json"` → expect no match (no `new NullRenderer`, no match arm/config).
Then: `git rm src/Renderer/NullRenderer.php`

- [ ] **Step 4: Remove `Sparkline` + its test, keep the live data feed (C6)**

Run: `git rm src/Renderer/Sparkline.php tests/Unit/Renderer/SparklineTest.php`
The renderer's `render()` is called only from its own test; the live feed `LoopStore::getIterationTimings()` (consumed by `LoopHandler` REST at `LoopHandler.php:417`) stays. Update its now-stale docblock at `src/Storage/LoopStore.php:574-577`, changing:
```php
    /**
     * Get timing data for each iteration of a loop (for sparkline visualization).
     *
     * @return list<array{iteration: int, duration_seconds: float, stage_count: int, completed_stages: int}>
     */
```
to:
```php
    /**
     * Get timing data for each iteration of a loop (for API timing consumers).
     *
     * @return list<array{iteration: int, duration_seconds: float, stage_count: int, completed_stages: int}>
     */
```

- [ ] **Step 5: Remove the 23 unused `use` imports (C3)**

Remove exactly these `use` lines (each confirmed to appear exactly once in its file = unused). In `src/Agent/OrchestratorAgent.php` remove the imports for: `ModelDefinition`, `CancellationTokenInterface`, `PendingInputProviderInterface`, `TickCallbackInterface`, `ToolExecutionPolicyInterface`, `CredentialResolverInterface`, `ConfigManager`, `ToolkitDiscovery`, `MemoryEntry`.
Remove one import each from:
- `src/Repl/AgentTurnExecutor.php` — `TimerInterface`
- `src/Repl/ReplCommandCatalog.php` — `ToolkitCommandHandler`
- `src/Memory/ConversationSummarizer.php` — `MemoryEntry`
- `src/Agent/CodeReviewCycle.php` — `ProviderInterface`
- `src/Tool/CoquiToolkitsTool.php` — `BoolParameter`
- `src/Provider/FallbackProvider.php` — `ModelDefinition`
- `src/Toolkit/SkillToolkit.php` — `SkillValidationException`
- `src/Toolkit/ScheduleToolkit.php` — `EnumParameter`
- `src/Repl/Handler/ScheduleHandler.php` — `BootManager`
- `src/Command/DoctorCommand.php` — `BootManager`
- `src/Command/TaskRunCommand.php` — `NotificationStore`
- `src/Mcp/McpServerManager.php` — `Parameter`
- `src/Storage/SessionStorage.php` — `Clock`
- `src/Config/SkillParser.php` — `SkillValidationException`

For each, before deleting confirm it is truly single-occurrence — a use in a PHPDoc (`@param Foo`), an attribute (`#[Foo]`), or code bumps the count above 1, so `1` means the symbol appears only on the `use` line:
Run per file, e.g.: `rg -c "\bModelDefinition\b" src/Agent/OrchestratorAgent.php` → expect `1`. Only remove when count is `1`.

> **The real safety net is PHPStan, not the count.** Vanilla PHPStan does not flag *unused* imports, but it DOES flag a *wrongly-removed* one: if a removed import was actually in use, `composer analyse` (Step 10) errors on the now-undefined symbol — restore that import if so. Treat the `rg` count as the candidate finder and the post-removal `analyse` as the authority.

> Note: the spec header said "24" but the enumerated list is 23 symbols across 15 files (9 in OrchestratorAgent + 14 others). Remove all 23; do not hunt for a 24th.

- [ ] **Step 6: C4 — re-confirm SecretMasker investigation, then remove**

Re-verify the decision inputs:
Run: `rg -n "SecretMasker" src tests config bin scripts | rg -v "source.json"` → expect only `src/Utility/SecretMasker.php` itself.
Run: `rg -n "SecretMasker|::mask\(" src` → confirm no production consumer.
Read the audit write path (`rg -n "function logAudit" src/Storage/SessionStorage.php`, then read the method) and confirm it stores raw nested args and that no `error_log` site passes a secret. The removal is justified by the reframed rationale above (single-string masker vs arbitrary nested audit args ⇒ wiring would be incomplete/false-confidence, and real redaction is a separate design), **not** by "local storage is safe." If that holds, remove:
Run: `git rm src/Utility/SecretMasker.php`

> If the re-confirmation surfaces a caller that DOES pass masking metadata or a single well-defined secret field that this helper could correctly mask, STOP and escalate: wire `SecretMasker` there + add a test instead of removing, and record the reversal in the commit body + report. (Not expected — the audit args are unstructured.)

- [ ] **Step 7: Remove source.json entries for every removed class**

Re-grep (line numbers shifted after Task 2) and delete each entry object found:
Run: `grep -n "NullRenderer\|AgentFiberExecutor\|SecretMasker\|SoftBreakRenderer\|Sparkline" config/source.json`
Delete the full `{...}` entry object for each match (path + fqcn + layer + description + methods), preserving JSON comma structure. `SoftBreakRenderer`/`Sparkline` may not be present (Ansi/* excluded, Renderer minor files) — remove only those that exist.

- [ ] **Step 8: Validate source.json**

Run: `php -r 'json_decode(file_get_contents("config/source.json"), true, 512, JSON_THROW_ON_ERROR); echo "OK\n";'`
Expected: `OK`.

- [ ] **Step 9: Grep every removed symbol across the tree (Definition-of-Done gate)**

Run (includes canonical `docs/` — a removed class named in user-facing docs is also debt):
```bash
for s in SoftBreakRenderer AgentFiberExecutor NullRenderer Sparkline SecretMasker; do
  echo "== $s =="; rg -n "$s" src tests config bin scripts docs | rg -v "docs/superpowers"; done
```
Expected: no matches. Historical mentions under `docs/superpowers` (specs/plans) are excluded and fine. **If a canonical doc (e.g. `docs/*.md`) references a removed class — check `NullRenderer` and `Sparkline` specifically — update or remove that reference in this commit.**

- [ ] **Step 10: PHPStan + full suite**

Run: `composer analyse 2>&1 | tail -15` → expect `[OK] No errors` (removing unused imports and dead files cannot add errors; this catches any miscounted import).
Run: `composer test 2>&1 | tail -15` → expect green; assertion count drops by the SparklineTest assertions (record the delta).

- [ ] **Step 11: Commit**

```bash
git add -A
git commit -m "chore(tech-debt): remove verified dead code

- remove SoftBreakRenderer (never registered), AgentFiberExecutor (v1 placeholder),
  NullRenderer (no consumer), Sparkline + its test (render never called in prod)
- remove SecretMasker: mask() called nowhere; it redacts a single substring while
  logAudit() takes arbitrary nested args, so wiring it would be incomplete/false
  confidence. Real audit redaction is a separate schema-aware design, tracked as a
  security follow-up. (No PR is opened; this body + the final report are the record.)
- remove 23 unused use imports across 15 files
- keep LoopStore::getIterationTimings() live feed; fix its docblock
- drop source.json entries for all removed classes"
```

---

## Task 4 — Group 5: Structured-questions behavioral fixes (Tier E)

> Lands BEFORE Task 5 (the tests that assert E1/E3). E2 changes an API response shape — its test + docs update land in THIS commit.

**Files:**
- Modify: `src/Repl/AgentTurnExecutor.php` (executeGroupTurn — E1)
- Modify: `src/Api/ApiErrorCode.php` (add cases — E2)
- Modify: `src/Api/Handler/QuestionHandler.php` (normalize error envelopes — E2)
- Modify: `src/Api/LoopQuestionAnswerReopener.php` (add dispatch block — E3)
- Modify: `tests/Integration/Api/QuestionHandlerTest.php` (assert new shape — E2)
- Modify: `docs/API.md` (question-endpoint error table — E2)

**Interfaces:**
- Produces (consumed by Task 5 tests):
  - E1: group actors receive an `InteractiveQuestionResponder($io, new QuestionPersistence($storage), $sessionId)` via `questionResponder:` on each `runSegment(...)`.
  - E2: `GET/POST .../questions[...]` 404/409/422 responses use the `{error, code}` envelope; new `ApiErrorCode::QUESTION_NOT_FOUND` (→404) and `ApiErrorCode::QUESTION_INVALID_ANSWER` (→422); already-answered reuses `CONFLICT` (→409).
  - E3: `LoopQuestionAnswerReopener::reopen` writes a `dispatch` metadata block `['status'=>'pending','message'=>…,'iteration_id'=>…,'stage_index'=>0,'updated_at'=>Clock::nowUtc()]`.

### E2 — normalize QuestionHandler error envelopes

- [ ] **Step 1: Add the two ApiErrorCode cases + status mappings**

**`ApiErrorCode` is a string-BACKED enum** (`enum ApiErrorCode: string`) — every case MUST declare a lowercase snake_case backing value matching the sibling convention (`SESSION_NOT_FOUND = 'session_not_found'`). A valueless `case QUESTION_NOT_FOUND;` is a fatal error. In `src/Api/ApiErrorCode.php`, add two cases (near the other domain-specific `*_NOT_FOUND` cases):
```php
    case QUESTION_NOT_FOUND = 'question_not_found';
    case QUESTION_INVALID_ANSWER = 'question_invalid_answer';
```
In the `httpStatus()` `match` (`ApiErrorCode.php:60-73`), add `QUESTION_NOT_FOUND` to the `=> 404` arm (alongside `TURN_NOT_FOUND` etc.) and add a `QUESTION_INVALID_ANSWER => 422` arm (there is currently no 422 mapping — this preserves the existing 422 status while adding the machine-readable `code`). Confirm each new case is covered by the match (PHPStan L8 will flag an unhandled enum case, which is the safety net).

> **Serialization fact (do not get this wrong):** `toPayload()` emits `'code' => $this->value` — the **lowercase backing value** (`'question_not_found'`, `'question_invalid_answer'`, `'conflict'`), NOT the case name. Every `code` assertion below uses the lowercase value.

> Design note: the current 422 (invalid answer) must stay **422**, not become 400. Reusing `VALIDATION_ERROR` would silently change the status to 400 — hence the dedicated `QUESTION_INVALID_ANSWER => 422` case.

- [ ] **Step 2: Route QuestionHandler error responses through `Router::errorResponse`**

In `src/Api/Handler/QuestionHandler.php`, replace the four bare `Router::jsonResponse([...], status)` error returns:
- `:65` `Router::jsonResponse(['error' => 'Question not found'], 404)` → `Router::errorResponse(ApiErrorCode::QUESTION_NOT_FOUND, 'Question not found')`
- `:68` `Router::jsonResponse(['error' => 'Question already answered'], 409)` → `Router::errorResponse(ApiErrorCode::CONFLICT, 'Question already answered')`
- `:75` `Router::jsonResponse(['error' => 'Answer is not valid for this question'], 422)` → `Router::errorResponse(ApiErrorCode::QUESTION_INVALID_ANSWER, 'Answer is not valid for this question')`
- `:79` `Router::jsonResponse(['error' => 'Question could not be answered'], 409)` → `Router::errorResponse(ApiErrorCode::CONFLICT, 'Question could not be answered')`

Add `use CoquiBot\Coqui\Api\ApiErrorCode;` if not already imported. Confirm `Router::errorResponse` yields `{error, code}` and the same HTTP statuses (404/409/422/409) as before.

- [ ] **Step 3: Update the integration test to assert the new shape (E2 care-item)**

In `tests/Integration/Api/QuestionHandlerTest.php`, the two 404 tests (`:96`, `:111`) currently assert only `->getStatusCode()->toBe(404)`. Strengthen them to also assert the envelope:
```php
    $body = json_decode((string) $response->getBody(), true);
    expect($response->getStatusCode())->toBe(404);
    expect($body)->toHaveKey('code');
    expect($body['code'])->toBe('question_not_found');
```
Do the same for the 409 test (code `'conflict'`, status 409) and the 422 test (code `'question_invalid_answer'`, status 422). The `code` is the enum's **lowercase backing value** (`toPayload` emits `$this->value`), not the case name — assert the lowercase strings exactly as declared in Step 1.

- [ ] **Step 4: Update `docs/API.md` question-endpoint error table (E2 care-item)**

In `docs/API.md`, the question error-response table (`:3017-3023`, under `#### POST /api/v1/sessions/{id}/questions/{questionId}/answer`) has only `Status | Meaning` columns. Add a `Code` column so it matches the global error-code convention:

| Status | Code | Meaning |
| --- | --- | --- |
| `404` | `QUESTION_NOT_FOUND` | Question not found in this session. |
| `409` | `CONFLICT` | Question already answered (no longer pending). |
| `422` | `QUESTION_INVALID_ANSWER` | Answer is not valid for this question (fails `QuestionResponse::isValidFor`). |

Also check the `GET .../questions` listing endpoint section for any bare-404 mention and align it.

### E1 — wire ask_user into REPL group turns

- [ ] **Step 5: Construct + pass a responder per group actor**

In `src/Repl/AgentTurnExecutor.php::executeGroupTurn` (`:184-235`), the per-actor `agentRunner->runSegment(...)` call (`:220-231`) passes no `questionResponder:`. Mirror the single-path wiring (`:120-124`, which builds `new InteractiveQuestionResponder($io, new QuestionPersistence($this->storage), $sessionId)`).

**`$io` is NOT in scope in `executeGroupTurn` — this is the fiddly part.** `executeGroupTurn(string $prompt, string $sessionId, array $session, ToolExecutionPolicyInterface $executionPolicy)` takes no `$io`, and the `executeActor` closure (`:212-217`) captures only `$executionPolicy, $sessionId, $role, $sessionRole`. So:
1. Add a `SymfonyStyle $io` parameter to `executeGroupTurn` and pass `$io` at the call site (`:115`, inside the `if ($groupEnabled …)` branch where `$io` IS in scope).
2. Add `$io` to the `executeActor` closure's `use (…)` list.
3. Inside the closure (or once, captured), build `new InteractiveQuestionResponder($io, new QuestionPersistence($this->storage), $sessionId)` and pass it as `questionResponder:` on the `runSegment(...)` call.

Keep the change minimal — no new deps, no semantic change beyond threading `$io` and attaching the responder. Confirm the exact `SymfonyStyle` type/import used for `$io` in this file before adding the parameter.

`runSegment` accepts `?QuestionResponderInterface $questionResponder = null` (verified: `AgentRunner.php:223`, mirroring `run()`), so no `AgentRunner` change is needed.

### E3 — dispatch metadata on answer-driven reopen

- [ ] **Step 6: Add the informational `dispatch` block**

In `src/Api/LoopQuestionAnswerReopener.php::reopen` (`:42-50`, the `updateLoopMetadata` call), add a `dispatch` key mirroring the siblings (`LoopHandler::retryIteration:908-918`, `skipStage:798-814`). The metadata array currently sets `escalation`, `rework_attempts`, `pending_answer`; add:
```php
            'dispatch' => [
                'status' => 'pending',
                'message' => 'Operator answer reopened the loop. The loop manager will dispatch stage 0 on the next tick.',
                'iteration_id' => $iterationId,
                'stage_index' => 0,
                'updated_at' => Clock::nowUtc(),
            ],
```
Use the iteration id already in scope within `reopen` (the same value passed to `resetStagesForIteration`/`resetIterationForRetry`). Confirm `Clock` is imported (the method already calls `Clock::nowUtc()` for `pending_answer.at`, so it is).

- [ ] **Step 7: Run the E2 integration test + full suite**

Run: `composer test -- --filter=QuestionHandler 2>&1 | tail -20` → expect green with the new shape assertions.
Run: `composer test 2>&1 | tail -15` → expect green overall.
Run: `composer analyse 2>&1 | tail -15` → expect `[OK] No errors` (the new enum cases must be handled in every `match` over `ApiErrorCode`; PHPStan enforces this).

- [ ] **Step 8: Regenerate the docs index (docs/API.md changed)**

Run: `composer regen-docs`
Do NOT stage `config/documentation.json` (git-ignored). Confirm: `git status --porcelain config/documentation.json` shows it ignored/untracked.

- [ ] **Step 9: Commit**

```bash
git add src/Repl/AgentTurnExecutor.php src/Api/ApiErrorCode.php src/Api/Handler/QuestionHandler.php src/Api/LoopQuestionAnswerReopener.php tests/Integration/Api/QuestionHandlerTest.php docs/API.md
git commit -m "fix(questions): group ask_user wiring, normalized error envelopes, reopen dispatch

- E1: pass InteractiveQuestionResponder per actor in executeGroupTurn (group
  turns previously had no responder)
- E2: route QuestionHandler 404/409/422 through Router::errorResponse envelope;
  add QUESTION_NOT_FOUND (404) and QUESTION_INVALID_ANSWER (422) codes; update
  integration test + docs/API.md error table
- E3: emit informational dispatch metadata on answer-driven loop reopen"
```

---

## Task 5 — Group 4: Tests — placement + coverage (Tier D)

> Lands AFTER Task 4 so D4 can assert E1/E3. Placement moves use `git mv`.

**Files:**
- Move: 4 test files (D1/D2)
- Delete: `tests/Unit/Integration/` subtree (D2)
- Modify: `src/Api/Middleware/RateLimitMiddleware.php` (constructor guard — Step 6a)
- Create: `tests/Unit/Api/Middleware/CorsMiddlewareTest.php`, `tests/Unit/Api/Middleware/RateLimitMiddlewareTest.php` (D3)
- Create: `tests/Unit/Config/SourceMapIntegrityTest.php` (structural guard — Step 6c)
- Create: `tests/Unit/Api/Handler/QuestionHandlerTest.php` (unit; D4)
- Create: `tests/Unit/Api/LoopQuestionAnswerReopenerTest.php` (D4)
- Replace: `tests/Unit/Agent/QuestionResponderWiringTest.php` (D4)

**Interfaces:**
- Consumes: Task 4's E1 (group responder), E2 (error codes), E3 (dispatch block).

### D1 + D2 — placement

- [ ] **Step 1: Move the four misplaced test files**

Run:
```bash
git mv tests/Unit/Api/AuthMiddlewareTest.php tests/Unit/Api/Middleware/AuthMiddlewareTest.php
git mv tests/Unit/ProgressBarTest.php tests/Unit/Renderer/ProgressBarTest.php
git mv tests/Unit/ContextUsageSnapshotTest.php tests/Unit/Contract/ContextUsageSnapshotTest.php
git mv tests/Unit/Integration/ProfileSwitchingTest.php tests/Integration/ProfileSwitchingTest.php
```

- [ ] **Step 2: Delete the now-empty one-off subtree**

Run: `rmdir tests/Unit/Integration 2>/dev/null; ls tests/Unit/Integration 2>&1 || echo "removed"`
Expected: `removed` (the subtree held exactly one file, now moved).

- [ ] **Step 3: Fix any namespace/path assumptions in the moved files**

The suite is Pest (functional style, no per-file namespace typically), but check each moved file for a hard-coded relative `require`/path or a `namespace` line that no longer matches its directory. If Pest config keys tests by directory only, no edit is needed.
Run: `composer test -- --filter='ProfileSwitching|AuthMiddleware|ProgressBar|ContextUsageSnapshot' 2>&1 | tail -20`
Expected: green (moved tests still discovered and passing).

### D3 — middleware coverage

- [ ] **Step 4: Write the failing `CorsMiddlewareTest`**

Create `tests/Unit/Api/Middleware/CorsMiddlewareTest.php` modeled on `ContentTypeMiddlewareTest.php` (same imports, PSR-7 request construction, `$next` closure). Cover:
- default (`['*']`) → response carries `Access-Control-Allow-Origin: *` and the standard Allow-Methods/Allow-Headers/Max-Age headers;
- allowlisted origin echoed + `Vary: Origin` added;
- non-allowlisted origin → not echoed;
- `OPTIONS` preflight → `204`, `$next` NOT invoked (assert via a flag the closure sets).

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Middleware\CorsMiddleware;
// ...mirror the request/Response helpers used by ContentTypeMiddlewareTest.php...

it('emits wildcard CORS headers by default', function (): void {
    $mw = new CorsMiddleware();
    $called = false;
    $next = function ($req) use (&$called) { $called = true; return /* 200 Response */; };
    $response = $mw($request /* GET */, $next);
    expect($response->getHeaderLine('Access-Control-Allow-Origin'))->toBe('*');
    expect($called)->toBeTrue();
});

it('returns 204 for OPTIONS preflight without calling next', function (): void {
    $mw = new CorsMiddleware();
    $called = false;
    $next = function ($req) use (&$called) { $called = true; return /* Response */; };
    $response = $mw($optionsRequest, $next);
    expect($response->getStatusCode())->toBe(204);
    expect($called)->toBeFalse();
});
```
Fill the request/Response construction from the sibling test's exact helpers (read `ContentTypeMiddlewareTest.php` first and copy its style verbatim — do not invent a PSR-7 factory).

- [ ] **Step 5: Run it — expect PASS (middleware already exists)**

Run: `composer test -- --filter=CorsMiddleware 2>&1 | tail -20`
Expected: PASS (this is characterization coverage of existing behavior, not TDD-of-new-code). **If a case fails, do NOT reflexively rewrite the test to match the code.** Characterization compares three things: (a) the current source behavior, (b) the documented API/CORS contract (`docs/API.md`), and (c) security intent (e.g. a wildcard origin must not be combined with `Access-Control-Allow-Credentials: true`; arbitrary origins must not be reflected). If (a) diverges from (b)/(c), **STOP and classify the discrepancy** — surface it as a finding rather than encoding accidental (possibly insecure) behavior as the contract. Only align the test to the code when the behavior is clearly correct-and-intended.

- [ ] **Step 6a: Add constructor validation to `RateLimitMiddleware` (latent div-by-zero)**

`RateLimitMiddleware` currently divides by `$this->windowSeconds` (`:96`) and `$this->maxRequests` (`:53`) with NO validation — `windowSeconds = 0` or `maxRequests = 0` throws `DivisionByZeroError` at request time. Add a guard to `src/Api/Middleware/RateLimitMiddleware.php::__construct`:
```php
        if ($maxRequests < 1 || $windowSeconds < 1) {
            throw new \InvalidArgumentException('maxRequests and windowSeconds must be >= 1.');
        }
```
This is a small in-scope hardening tied to the new coverage; keep it to the guard only.

- [ ] **Step 6b: Write + run `RateLimitMiddlewareTest`**

Create `tests/Unit/Api/Middleware/RateLimitMiddlewareTest.php`. Cover:
- under-limit requests pass and carry `X-RateLimit-Limit` / `X-RateLimit-Remaining`;
- exceeding the bucket → `429` with `Retry-After` and the `RATE_LIMITED` envelope;
- `EXEMPT_PATHS` (`/api/v1/health`) bypasses limiting even when the bucket would be empty;
- `OPTIONS` bypasses;
- the new constructor guard: `new RateLimitMiddleware(0, 60)` and `new RateLimitMiddleware(2, 0)` each throw `InvalidArgumentException`.

**Determinism note:** the bucket refills continuously off `microtime(true)` (`:84,96`). To keep the limit-exceeded case deterministic, use a **long window** (e.g. `new RateLimitMiddleware(2, 3600)`) so per-request refill is negligible, drive `$max + 1` requests from the same simulated IP via `X-Forwarded-For`, and assert only stable properties (status `429`, presence of `Retry-After`/`X-RateLimit-*`) — avoid asserting exact remaining-token counts that a sub-millisecond refill could shift. Do NOT inject a clock (that constructor change is out of scope for this sweep).

> Security note to record (follow-up, not a code change here): `resolveClientIp` trusts the first `X-Forwarded-For` entry unconditionally (`:141-145`); this is safe only behind a trusted proxy — otherwise a client can spoof the header to evade per-IP limits. Flag as a hardening follow-up.

Run: `composer test -- --filter=RateLimitMiddleware 2>&1 | tail -20` → expect PASS.

- [ ] **Step 6c: Add a `config/source.json` structural-integrity test (prevents this debt class from recurring)**

Create `tests/Unit/Config/SourceMapIntegrityTest.php` (model fixture setup on the existing `tests/Unit/Config/` tests). Load `config/source.json` and assert:
- every entry's `path` exists on disk (`is_file(__DIR__ . '/../../../' . $path)`) — catches dead entries like the `SummarizeHandler` one this sweep removed;
- every `layer` referenced by an entry is declared in the top-level `layers` object — catches the undefined `api`/`provider` layers;
- all `path` values are unique — catches the duplicate `ScheduleManager` entry;
- the JSON parses (redundant with the shell check, but locks it into CI).

This test is the durable guard: it would have failed on all three B-series defects. Run: `composer test -- --filter=SourceMapIntegrity 2>&1 | tail -20` → expect PASS against the Task-2/Task-3-corrected map.

### D4 — coverage of the #6 surface (asserts Task 4)

- [ ] **Step 7: Add `QuestionHandler` unit tests (post-E2)**

Create `tests/Unit/Api/Handler/QuestionHandlerTest.php` covering the normalized envelopes from E2 (lowercase backing values):
- unknown question → 404 with `{error, code: 'question_not_found'}`;
- already-answered → 409 with `code: 'conflict'`;
- invalid answer → 422 with `code: 'question_invalid_answer'`.
Construct the handler with a real or in-memory `SessionStorage` + `QuestionPersistence` following the pattern in the existing `tests/Integration/Api/QuestionHandlerTest.php` (read it for the fixture setup; the unit test exercises the handler **directly** rather than through the router). If direct construction needs too much fixture, keep these as focused handler-level tests that seed one question row and assert each branch.

> Avoid duplicating the integration test verbatim: the integration test (Task 4 Step 3) already asserts the status+code envelope end-to-end through the router. This unit test should exercise the handler's **branch logic directly** (e.g. session-vs-question 404 distinction, the `isValidFor` 422 path, the atomic-answer 409 path) — not re-assert the identical status/code triples the integration test owns. Keep the two layers complementary, not redundant.
Run: `composer test -- --filter=QuestionHandler 2>&1 | tail -20` → expect PASS (integration + new unit).

- [ ] **Step 8: Add `LoopQuestionAnswerReopener` unit test (post-E3)**

Create `tests/Unit/Api/LoopQuestionAnswerReopenerTest.php`. Seed a loop with an escalated pending question, call `reopen($loopId, $question, $answer)`, then assert on the resulting loop metadata:
- reset sequence ran (`escalation` cleared to `null`, `rework_attempts` reset to `0`, `pending_answer` recorded, loop status `running`);
- the new `dispatch` block is present with `status=pending`, `stage_index=0`, the correct `iteration_id`, an `updated_at`, and the answer-driven `message`.
Read `LoopStore`'s test fixtures (or an existing `Loop` test under `tests/Integration/Loop/`) for how to seed a loop cheaply.
Run: `composer test -- --filter=LoopQuestionAnswerReopener 2>&1 | tail -20` → expect PASS.

- [ ] **Step 9: Replace the misnamed `QuestionResponderWiringTest` (post-E1)**

Overwrite `tests/Unit/Agent/QuestionResponderWiringTest.php` (currently 22 lines asserting only tool count) with tests that verify responder **attachment** across the paths. The three responders that actually exist (verify before asserting): `InteractiveQuestionResponder` (REPL), `SuspendingQuestionResponder` (API / `turn:run`, wired at `TurnRunCommand.php:155`), `PolicyQuestionResponder` (loops/bg). **There is no `ApiQuestionResponder`** — the API path uses `SuspendingQuestionResponder`.

**Concrete seam (define it, don't hand-wave):** subclass or fake `AgentRunner` with an override of `run(...)` / `runSegment(...)` that **captures the `questionResponder:` argument** into a public property instead of executing a turn, inject it into `AgentTurnExecutor`, then drive each path and assert the captured responder:
- single-session path: the captured `questionResponder` is a non-null `InteractiveQuestionResponder`;
- group path (E1): each `runSegment` call captured a non-null `InteractiveQuestionResponder` (this is the test that guards the E1 fix — it must fail against pre-E1 code);
- API path: assert `TurnRunCommand` (or the API turn entry) constructs a `SuspendingQuestionResponder` — a focused construction/attachment assertion, not a live turn.

Do not assert tool count (drop the old assertion); the point is to test wiring. If a live-model-free seam into one path proves impractical, cover that path with a construction-level assertion and note the limitation in the test.
Run: `composer test -- --filter=QuestionResponderWiring 2>&1 | tail -20` → expect PASS.

- [ ] **Step 10: Full suite + analyse**

Run: `composer test 2>&1 | tail -15` → expect green; assertion count risen by D3/D4 additions (record final numbers).
Run: `composer analyse 2>&1 | tail -15` → expect `[OK] No errors`.

- [ ] **Step 11: Commit**

```bash
git add -A
git commit -m "test(tech-debt): fix test placement and close #6 coverage gaps

- move AuthMiddleware/ProgressBar/ContextUsageSnapshot/ProfileSwitching tests
  to their correct trees; delete one-off tests/Unit/Integration subtree
- add CorsMiddleware + RateLimitMiddleware unit tests (was zero direct coverage)
- add QuestionHandler (normalized 404/409/422) and LoopQuestionAnswerReopener
  (reset + dispatch) unit tests
- replace tool-count-only QuestionResponderWiringTest with real
  responder-attachment tests across single/group/API paths"
```

---

## Task 6 — Group 6: Extras

**Files:**
- Modify: `composer.json:37`

- [ ] **Step 1: Reword the `ext-zip` suggest entry**

Verify zip has no other core use first:
Run: `grep -rn "ZipArchive\|ext-zip" src` → expect no `ZipArchive` in `src` (only the composer.json line).
Since zip has no surviving core use (backstory extraction moved to the mod), remove the now-orphaned `suggest` line at `composer.json:37`:
```json
    "ext-zip": "Required for optional .odt/.ods/.odp/.xlsx/.pptx backstory extraction",
```
Delete the entire line and fix the trailing comma on the preceding `suggest` entry so `composer.json` stays valid JSON. (If the review prefers keeping the line, the fallback is rewording to drop only the "backstory extraction" phrase — but removal is correct since there is no other core zip use.)

> Note (out of scope, mod-side): the `ext-zip` install hint properly belongs in the `coqui-toolkit-backstory` mod's own docs, since that is where the extraction feature now lives. Not part of this sweep — flag for the mod's docs.

- [ ] **Step 2: Validate composer.json**

Run: `composer validate --no-check-publish 2>&1 | tail -5`
Expected: valid (no JSON error).

- [ ] **Step 3: Commit**

```bash
git add composer.json
git commit -m "chore(tech-debt): drop orphaned ext-zip suggest (backstory moved to mod)"
```

---

## Final Verification (after all six commits)

- [ ] **Step F1: Full green suite + record counts**

Run: `composer test 2>&1 | tail -5`
Record the final "Tests: N passed (M assertions)" line for the report. Compare to P2 baseline: expect net-new tests (D3/D4) minus removed SparklineTest.

- [ ] **Step F2: PHPStan clean**

Run: `composer analyse 2>&1 | tail -5`
Expected: `[OK] No errors`. Explicitly confirm A4 (Router type) added no new errors.

- [ ] **Step F3: source.json valid + loads**

Run: `php -r 'json_decode(file_get_contents("config/source.json"), true, 512, JSON_THROW_ON_ERROR); echo "OK\n";'`
Run: `composer test -- --filter=CoquiSource 2>&1 | tail -5` (if such a test exists).

- [ ] **Step F4: dead symbols gone**

Run:
```bash
for s in SoftBreakRenderer AgentFiberExecutor Sparkline NullRenderer SecretMasker; do
  echo "== $s =="; grep -rn "$s" src tests config bin scripts | grep -v "docs/superpowers"; done
```
Expected: no matches.

- [ ] **Step F5: six commits present**

Run: `git log --oneline main..chore/tech-debt-sweep`
Expected: exactly six commits (G1, G2, G3, G5, G4, G6 order).

- [ ] **Step F6: push (no PR, no merge)**

Run: `git push -u origin chore/tech-debt-sweep`
Do NOT open a PR, do NOT merge, do NOT touch the primary checkout.

- [ ] **Step F7: report**

Report: final test + assertion counts, PHPStan result, the six commit hashes, and — prominently — the **SecretMasker decision (REMOVE) with its rationale** (audit trail is intentionally faithful; logs carry no secrets; credential API hides values; only display-sanitization masks, and that is out of scope + already inline).

---

## Self-Review (author checklist — completed)

**Spec coverage:** A1–A4 → Task 1. B1–B4 → Task 2. C1–C6 → Task 3 (C4 = remove, investigated). D1–D4 → Task 5. E1–E3 → Task 4. Group 6 → Task 6. Docs (API.md, source.json, role files) → folded into their owning tasks. Sequencing (E before D, E2 test+docs same commit) → honored by Task 4 preceding Task 5.

**Discrepancies surfaced for reviewer:**
1. Spec header says "24 unused imports"; the enumerated list is **23** (9 + 14). Plan removes the 23 enumerated. → Task 3 Step 5 note.
2. E2's 422 branch: reusing `VALIDATION_ERROR` would change status 422→400. Plan adds a dedicated `QUESTION_INVALID_ANSWER => 422` code to preserve the status while normalizing the shape. → Task 4 Step 1 design note.
3. `QuestionHandler` has **four** bare error returns (404, 409, 422, 409); plan normalizes all four for consistency (spec says "404/409/422"). → Task 4 Step 2.
4. C4 SecretMasker: investigation complete, decision = REMOVE; plan keeps a re-confirmation gate (Task 3 Step 6) so the SDD implementer/reviewer re-validates before deleting.

**Type consistency:** `InteractiveQuestionResponder($io, QuestionPersistence, $sessionId)` used identically in E1 (Task 4) and its test (Task 5). `ApiErrorCode::QUESTION_NOT_FOUND`/`QUESTION_INVALID_ANSWER` defined in Task 4 Step 1 (string-backed, lowercase values), asserted by exact lowercase `code` string in Task 4 Step 3 and Task 5 Step 7. `dispatch` block keys (`status/message/iteration_id/stage_index/updated_at`) identical in E3 (Task 4 Step 6) and its assertion (Task 5 Step 8).

## Review Amendments (applied 2026-07-15, post external review)

Corrections and additions folded in after an independent review; all verified against the worktree code:

1. **E2 enum serialization (must-fix).** `ApiErrorCode` is string-BACKED — the new cases declare lowercase values (`'question_not_found'`, `'question_invalid_answer'`); `toPayload` emits `$this->value`, so every `code` assertion uses the lowercase value, not the case name. (Task 4 Steps 1/3; Task 5 Step 7.)
2. **E1 `$io` threading (must-fix).** `executeGroupTurn` does not receive `$io`; the plan now threads it through the method signature (call site `:115`) and the `executeActor` closure `use(…)`. (Task 4 Step 5.)
3. **Nonexistent responder (must-fix).** The API path uses `SuspendingQuestionResponder`, not `ApiQuestionResponder`; the wiring test names the three real responders and defines a concrete capture seam. (Task 5 Step 9.)
4. **No "see PR" (must-fix).** No PR is opened — SecretMasker rationale now lives in the commit body + final report. (Task 3 Step 11.)
5. **Plan on `main` (must-fix).** Spec + plan committed to `main`; branch = exactly six work-group commits. (Global Constraints.)
6. **source.json integrity test (new, high value).** `tests/Unit/Config/SourceMapIntegrityTest.php` asserts path-exists / layer-declared / paths-unique — a durable guard against the exact B-series debt. (Task 5 Step 6c.)
7. **RateLimit hardening (new).** Constructor guard against `<= 0` (latent div-by-zero) + deterministic long-window test + X-Forwarded-For trust caveat noted. (Task 5 Steps 6a/6b.)
8. **SecretMasker rationale reframed.** Dropped "local ⇒ safe" (a public `getAuditLog()` read path exists); justified by single-string-masker-vs-nested-args ⇒ false confidence; separate audit-redaction security follow-up tracked. (Task 3 C4 block.)
9. **CORS characterization discipline.** On behavior-vs-contract/security divergence, STOP and classify — do not encode accidental behavior as the contract. (Task 5 Step 5.)
10. **Representative methods** for the load-bearing source-map entries (BootManager/ConfigManager/AgentRunnerFactory/handlers); value objects stay empty. (Task 2 Step 6.)
11. **Minor:** removal grep widened to canonical `docs/`; `rg`-over-`grep` + PHPStan-as-import-authority clarified; A4 downstream-benefit scoped as unverifiable here; ext-zip hint flagged for the mod's docs.
