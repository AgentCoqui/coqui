# Tech-Debt Sweep — Sprint Close-Out Design

**Date:** 2026-07-15
**Status:** Approved (scope + judgment calls decided 2026-07-15)
**Branch (proposed):** `chore/tech-debt-sweep`

## Context

The core-thinning / hardening program shipped seven briefs across `v0.0.27` and `v0.0.28` (artifacts files-only, memory-on-promotion, loop hardening, identity/backstory consolidation, public API routes, structured questions). This sweep closes the sprint by clearing the residual tech debt those changes left behind.

The debt was enumerated by four independent read-only audits (stale references, self-model accuracy, dead code, test conventions/coverage) plus verification spot-checks. **The thinning itself was clean** — Channels and the fullscreen TUI leave zero dangling references, and `source.json`'s recent additions are well-maintained. The remaining debt is concentrated and low-risk.

## Goal

One comprehensive, low-risk sweep that:
1. Removes agent-facing incorrectness (prompts pointing at deleted tools; a wrong handler type that forces `@phpstan-ignore` in every mod).
2. Corrects the self-model map (`config/source.json`) the agent reads to navigate itself.
3. Removes verified dead code.
4. Fixes test placement and closes coverage gaps around newly-added code.
5. Resolves the three structured-questions (`#6`) behavioral deferrals.

**Non-goals:** no refactoring beyond what these items require; no API surface changes except the E2 404-shape normalization; no changes to loop/question *semantics* beyond E1/E3.

## Scope — Six Work Groups

### Group 1 — Agent-facing correctness (Tier A)

Highest value: these mislead the *running* agent or ship a wrong type.

- **A1** `config/roles/plan.md:30` — replace `start_background_task(role: "explorer")` guidance with the surviving mechanism (`loop_start` goal-driven; see `docs/CONFIGURATION.md:335`).
- **A2** `src/Toolkit/WebToolkit.php:60` (guideline string) and `:327` (runtime JSON `message`) — remove/replace the `task_status` monitoring instruction (removed tool). The `http_download` action itself still works; only the monitor instruction is stale.
- **A3** `config/roles/coder.md:18` — drop the removed `packagist` tool reference (the back-ticked tool name). Unlike `composer`, no shell survivor exists; reword to plain "packagist.org" or remove.
- **A4** `src/Api/Router.php:39` and `:63` — the `addRoute`/`addPublicRoute` docblock types the handler's 2nd param as `array<string, string>`, but `doDispatch` (`Router.php:206-211`) builds `$params` and spreads it with `...$params`, passing each captured path segment as an **individual named string argument**. Correct the type to `callable(ServerRequestInterface, string ...): Response`.
  - **Care:** must remain PHPStan L8-clean and must not break coqui's own handlers, which all take `($req, string $x)` and are already compatible. Verify with `composer analyse`.
  - **Downstream (out of scope, user-side):** once released, the webhooks mod's `@phpstan-ignore argument.type` at `coqui-toolkit-webhooks/src/WebhookHandler.php:41` can be removed. That removal happens in the mod repo when it bumps to the new coqui tag; it is **not** part of this sweep.

### Group 2 — Self-model map (`config/source.json`) — "fix errors + mark selective"

The map is consumed by `src/Toolkit/CoquiSourceToolkit.php` and presented to the agent. Keep JSON valid after every edit.

- **B1** Delete the dead entry for `src/Api/Handler/SummarizeHandler.php` (`source.json:2077`) — the file was deleted with the backstory subsystem, and it advertises a dead `POST /sessions/{id}/summarize` route. The live path is `GET /sessions/{id}/summary` → `SessionHandler::summary` (`src/Command/ApiCommand.php:596`), which is already mapped. Delete; do not re-point.
- **B2** Delete the duplicate `ScheduleManager` entry at `source.json:3135` (layer `agent`, methods `checkCompletedTasks/trigger/setOnNotify` that do not exist). Keep the correct entry at `:2872` (layer `api`, `tick()/reconcile()`).
- **B3** Add the two in-use-but-undefined layers to the top-level `layers` object: `api` (7 files) and `provider` (1 file). Reconcile the handler layering inconsistency (`BudgetHandler` and the now-deleted `SummarizeHandler` carry `command` while sibling API handlers carry `api`) — set `BudgetHandler` to `api`.
- **B4** Add entries for the load-bearing omissions and **document the map as selective**:
  - Add: `BootManager`, `ConfigManager`, `ConfigValidator`, `ConfigGuard`, `AgentRunnerFactory`, `TurnRunCommand`, `ProjectHandler`, `SessionProjectHandler`, `ToolkitHandler`, and the Contract value objects (`BackgroundTaskSummary`, `CodeReviewResult`, `DeferredWorkQueue`, `LoopParameterDefinition`, `ReviewVerdict`, `ToolkitVisibility`).
  - Update the map's self-description so it no longer claims to describe "every core source file" — state that it is a **selective, load-bearing** map, and note that fine-grained subtrees (`src/Renderer/Ansi/*`, `Stub*` test doubles) are intentionally excluded.
  - The remaining ~40 gaps (React providers, observers, minor renderers/support) are acceptable under "selective" and need not all be added.

### Group 3 — Dead-code removal (Tier C)

All verified unreferenced (grep across `src tests config bin scripts prompts docs`), discovery-aware.

- **C1** Remove `src/Renderer/Ansi/SoftBreakRenderer.php` (the only CommonMark renderer never registered in `AnsiRendererExtension.php:48-70`) and its unused `AbstractStringContainer` import.
- **C2** Remove `src/Api/AgentFiberExecutor.php` — **decided: remove.** Documented v1 fiber placeholder; referenced only in `source.json` (also remove that entry). The real execution model is out-of-process (`turn:run`/`task:run`); no `Fiber::suspend` path exists.
- **C3** Remove the 24 unused `use` imports (all confirmed single-occurrence):
  - `src/Agent/OrchestratorAgent.php` (9: `ModelDefinition`, `CancellationTokenInterface`, `PendingInputProviderInterface`, `TickCallbackInterface`, `ToolExecutionPolicyInterface`, `CredentialResolverInterface`, `ConfigManager`, `ToolkitDiscovery`, `MemoryEntry`)
  - one each in: `Repl/AgentTurnExecutor` (`TimerInterface`), `Repl/ReplCommandCatalog` (`ToolkitCommandHandler`), `Memory/ConversationSummarizer` (`MemoryEntry`), `Agent/CodeReviewCycle` (`ProviderInterface`), `Tool/CoquiToolkitsTool` (`BoolParameter`), `Provider/FallbackProvider` (`ModelDefinition`), `Toolkit/SkillToolkit` (`SkillValidationException`), `Toolkit/ScheduleToolkit` (`EnumParameter`), `Repl/Handler/ScheduleHandler` (`BootManager`), `Command/DoctorCommand` (`BootManager`), `Command/TaskRunCommand` (`NotificationStore`), `Mcp/McpServerManager` (`Parameter`), `Storage/SessionStorage` (`Clock`), `Config/SkillParser` (`SkillValidationException`).
- **C4** `src/Utility/SecretMasker.php` — **decided: investigate, then decide.** The static `mask()` is called nowhere and there is no secret-masking/redaction wiring in audit or log output. The implementer must: (a) determine whether audit-record or log-write paths (`SessionStorage` audit, `error_log` call sites) *should* be masking secrets; (b) if a genuine gap exists, wire `SecretMasker` at the appropriate boundary and add a test; (c) otherwise remove the class (and its `source.json` entry). **Document the decision and its rationale in the PR.** Default to removal only if (a) finds no boundary that warrants masking.
- **C5** `src/Renderer/NullRenderer.php` — verify no consumer selects it (no match arm/config maps to it). If it is genuinely unreferenced and not a documented public null-object, remove it; if there is any external/documented reliance, keep and note why.
- **C6** Remove `src/Renderer/Sparkline.php` (renderer `render()` never called in production; only its own test). Keep the live data feed `LoopStore::getIterationTimings()` (consumed by `LoopHandler` REST) and update its docblock so it no longer references the removed terminal sparkline. Remove `tests/Unit/Renderer/SparklineTest.php` with it.

### Group 4 — Tests: placement + coverage (Tier D)

- **D1** `git mv tests/Unit/Api/AuthMiddlewareTest.php tests/Unit/Api/Middleware/` (matches `ContentTypeMiddlewareTest`/`RequestSizeMiddlewareTest`).
- **D2** Reconcile the other placement drift: `git mv tests/Unit/ProgressBarTest.php tests/Unit/Renderer/`; `git mv tests/Unit/ContextUsageSnapshotTest.php tests/Unit/Contract/`; move `tests/Unit/Integration/ProfileSwitchingTest.php` into the real `tests/Integration/` tree (it self-identifies as an integration test) and delete the one-off `tests/Unit/Integration/` subtree.
- **D3** Add dedicated unit tests for `CorsMiddleware` and `RateLimitMiddleware` (currently zero direct coverage; only indirect via `PublicRouteMiddlewareStackTest`). Cover: CORS header emission + OPTIONS handling; rate-limit windowing + `EXEMPT_PATHS` (`/api/v1/health`).
- **D4** Add unit tests for the `#6` surface (some assert the Group 5 fixes):
  - `QuestionHandler` — the normalized 404 shape (post-E2), the 409 (already-answered) and 422 (invalid) branches.
  - `LoopQuestionAnswerReopener` — the reset sequence **and** the new informational `dispatch` block (post-E3).
  - Group `ask_user` wiring (post-E1) — assert a group actor receives a responder. Rename/replace the misnamed 23-line `tests/Unit/Agent/QuestionResponderWiringTest.php` (currently only asserts tool count) to actually verify responder attachment across single/group/API paths.

### Group 5 — Structured-questions (`#6`) behavioral fixes (Tier E)

- **E1** Wire `ask_user` into REPL **group** turns. `AgentTurnExecutor::executeGroupTurn` (`:184-235`) calls `agentRunner->runSegment(...)` per actor with no `questionResponder:`. Construct the same `InteractiveQuestionResponder` the single path uses (`AgentTurnExecutor.php:120`) and pass it per actor. **Care:** this is a genuine behavioral addition (group actors currently silently have no responder). Keep it minimal and mirror the single-session wiring; add the D4 test.
- **E2** Normalize the `GET /api/v1/sessions/{id}/questions` / `answer` 404 shapes. `QuestionHandler.php:65` returns a bare `{"error":"Question not found"}` (no `code`), while session-not-found returns the standard envelope (`{"error","code"}`). Route the question-not-found (and the 409/422) responses through the standard `Router::errorResponse(ApiErrorCode::…)` envelope. Add a `QUESTION_NOT_FOUND` case to `ApiErrorCode` (or reuse `NOT_FOUND`) mapping to HTTP 404. **Care:** this changes response shape — update the existing `tests/Integration/Api/QuestionHandlerTest.php` expectations and the `docs/API.md` question-endpoint documentation.
- **E3** `LoopQuestionAnswerReopener::reopen` (`:42-56`) — add the informational `dispatch` metadata block that the sibling reopen paths emit (`LoopHandler::retryIteration:908-918`, skip-stage `:798-814`): `['status' => 'pending', 'message' => '<answer-driven reopen note>', 'iteration_id' => …, 'stage_index' => 0, 'updated_at' => Clock::nowUtc()]`. Cover with the D4 test.

### Group 6 — Extras

- `composer.json:37` — the `suggest` entry for `ext-zip` still says "backstory extraction" (moved to the mod). Reword to drop the backstory-extraction claim, or remove the suggest line if `ext-zip` has no other core use (verify before removing).

## Risks & Sequencing

- **Ordering:** Group 5 (E fixes) must land before or with the Group 4 `#6` tests that assert them. E2 changes an API response shape — update the integration test and docs in the same commit.
- **A4 + E2** are the only non-mechanical changes touching type/response contracts; everything else is deletion, moves, prompt-string edits, or additive tests. Keep each group in its own commit for a clean whole-branch review.
- **SecretMasker (C4)** is the one open-ended item — its outcome (wire vs. remove) is the implementer's call, documented in the PR. If wiring, it must not change existing audit output beyond masking.
- **`source.json` (Group 2)** — validate JSON after edits; `CoquiSourceToolkit` parses the whole file.

## Testing & Verification

- `composer test` (Pest) must stay green; add the D3/D4 tests.
- `composer analyse` (PHPStan L8) must stay clean — specifically confirm A4 introduces no new errors.
- `composer regen-docs` if any doc index inputs changed (the generated `config/documentation.json` is git-ignored; do not commit).
- Manual: confirm the removed dead-code files are referenced nowhere post-removal (`grep` each class name).

## Docs to Update (same change)

- `docs/API.md` — the `#6` question-endpoint 404/409/422 response shapes (E2).
- `config/source.json` — Group 2 (this *is* a doc-of-record update).
- Role files `config/roles/plan.md`, `config/roles/coder.md` — Group 1.
- No `README.md` change expected (nothing user-facing beyond the E2 error-shape, covered in API.md).

## Out of Scope / Follow-ups (user-side)

- Removing the webhooks mod's `@phpstan-ignore` (mod repo, gated on the next coqui tag that carries A4).
- The pre-existing `CredentialHandler` missing-test gap (not part of the newly-added surface) — noted, not included.

## Build Process

Dispatch as a single `/prompt-agent-task` brief → agent produces a full plan and STOPS for review → SDD (Opus implementer + Opus reviewer, two-verdict gate, whole-branch review) → independent verification (re-run suite, diff faithfulness) → merge. Consistent with the program's established pattern.
