# Phase 3 — §D Runtime (D2/D3/D4) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task (fresh Opus 4.8 implementer per task, Opus 4.8 spec+quality reviewer per task, whole-branch review at the end). Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the coqui runtime *behave* per CAP 0.5.0's three runtime disagreements — session-aware model precedence (D2), per-session workspace re-root with stage/child inheritance (D3), and "loops never block on a question; a missing stage role blocks" (D4) — turning the six behavioral gate rows CORE-15, 17, 19, 20, 21, 22, 23 green.

**Architecture:** Phase 2 already reshaped the stores; the objects are producible. Phase 3 changes *runtime behavior* at four seams: (1) model resolution gains a session-model override layer in front of the existing role/persona chain; (2) shell/files toolkits re-root from the session's `workspace` column (falling back to the global workspace), inherited by loop stages and child runs; (3) the loop question-block subsystem is deleted wholesale and loops auto-answer with the agent's suggested answer; (4) a stage with no role definition escalates `blocked` + Critical instead of silently stalling, an `artifact_required` definition on a persona lacking the `artifacts` feature is rejected 422 at loop creation, and deleting a session cascade-stops its non-terminal loops.

**Tech Stack:** PHP 8.4 (`declare(strict_types=1)`, `final` by default, constructor injection), SQLite via PDO, Pest (`composer test`), PHPStan (`composer analyse`). Conformance harness: `tests/conformance/` (`ConformanceValidator::isValid('<obj>.json', $data)`, `CoreChecklistTest.php` scoreboard).

## Global Constraints

Every task's requirements implicitly include this section.

- **Worktree only.** All work in `/home/carmelo/Projects/CoquiBot/Core/coqui-cap-migration` (branch `feat/cap-0.5-conformance`, unpushed). NEVER touch the primary checkout `/home/carmelo/Projects/CoquiBot/Core/coqui` or the vendored spec under `tests/conformance/spec/**` (pinned to spec `5dffc63`). Do NOT push, do NOT open PRs.
- **No-legacy / pre-release.** No installed base. No migration shims, no back-compat aliases, no dual code paths kept "just in case". Delete cleanly.
- **Scope is behavioral rows only.** Phase 3 turns CORE-15, 17, 19, 20, 21, 22, 23 green. It does **NOT** tear down the Project HTTP/wire surface (`/projects` routes, `ProjectHandler`, `SessionProjectHandler`, `active_project_id` in responses, `ProjectToolkit`, `ProjectStore`) — that stays intact this phase (user decision 2026-07-31, "behavioral-only"). Leave every Project code path working; only *add* workspace re-root beside it.
- **Safety invariants.** `src/Config/CatastrophicBlacklist.php` and its test MUST stay byte-for-byte unchanged (verify with `git log <base>..HEAD -- src/Config/CatastrophicBlacklist.php` = empty). Audit logging, shell/filesystem sandboxing, and destructive-op gating stay intact.
- **Do NOT touch the capability sense of "profile".** `ToolProfileResolver`, `toolProfile`, `TOOL_PROFILE_*`, perf `test:profile` / `COQUI_TEST_PROFILE_*`, `LeanDefaultProfilePrecedenceTest` are the *capability* sense and are out of scope. Phase 3 touches only the identity (`persona`) and runtime senses.
- **Green per task.** `composer test` and `composer analyse` MUST pass at the end of every task. Never commit red. Commit once per task.
- **Empty JSON objects.** When emitting/validating producer output, empty objects are `stdClass`, never `[]` (Phase-2 gotcha).
- **Conformance test style.** New behavioral assertions are real `it(...)->group('conformance')` tests replacing the matching `->todo()` row in `CoreChecklistTest.php`'s `$rows` array (lines ~476–481). Removing a row's string from `$rows` and adding a real `it()` is how a row flips green. Namespace `CoquiBot\Coqui\Tests\Conformance`; validator `use CoquiBot\Coqui\Tests\Conformance\Support\ConformanceValidator;`.

---

### Task 1: Session-aware model precedence (D2, CORE-15 behavioral)

Introduce the CAP precedence **session.model (non-null) → role → persona → instance default**. Today the role is the pivot and `session.model` is ignored on the single-turn path (only group turns consult it, inline). Add one session-aware method on `RoleResolver` that wraps the existing chain, thread it through `AgentRunner`, and fold the two inline group-path checks into it so both paths share one rule.

**Files:**
- Modify: `src/Config/RoleResolver.php` (add `resolveForSession()` after `resolve()`, ~line 67)
- Modify: `src/Agent/AgentRunner.php` (4 resolve sites: `doRun` ~L268, `executeSegment` ~L656, `createAgent` ~L934, `buildPreviewContext` ~L1382; `SessionStorage $storage` is already injected at L100)
- Modify: `src/Command/TurnRunCommand.php` (fold inline check ~L179–181)
- Modify: `src/Repl/AgentTurnExecutor.php` (fold inline check ~L198–200)
- Test: `tests/Unit/Config/RoleResolverTest.php` (add precedence cases)
- Test: `tests/conformance/CoreChecklistTest.php` (strengthen the existing CORE-15 test with a precedence assertion)

**Interfaces:**
- Consumes: `RoleResolver::resolve(string $role, ?string $persona = null): string` (existing terminal chain: persona-role-file → global-role-file → configured-role-model → persona-model → primary); `OpenClawConfig::resolveModel(string): string` (alias expansion); `SessionStorage::getSession(string $id): ?array` (row has nullable `model`).
- Produces: `RoleResolver::resolveForSession(?string $sessionModel, string $role, ?string $persona = null): string` — used by every model-selection call site that has a session in scope.

- [ ] **Step 1: Write the failing unit tests** in `tests/Unit/Config/RoleResolverTest.php` (mirror the existing `test(...)` style + fixtures in that file):

```php
test('resolveForSession: a non-null session model overrides the role/persona chain', function () {
    $resolver = /* build the same resolver the file's other tests build */;
    // A concrete session model wins outright and is alias-expanded via resolveModel().
    expect($resolver->resolveForSession('anthropic/claude-opus-4', 'orchestrator', null))
        ->toBe('anthropic/claude-opus-4');
});

test('resolveForSession: a null session model falls through to the existing role/persona/primary chain', function () {
    $resolver = /* same builder */;
    // Identical to resolve() when the session model is null (inherit).
    expect($resolver->resolveForSession(null, 'orchestrator', null))
        ->toBe($resolver->resolve('orchestrator', null));
});

test('resolveForSession: an empty-string session model is treated as inherit', function () {
    $resolver = /* same builder */;
    expect($resolver->resolveForSession('', 'orchestrator', null))
        ->toBe($resolver->resolve('orchestrator', null));
});
```

- [ ] **Step 2: Run the tests to confirm they fail** (method undefined):

Run: `./vendor/bin/pest tests/Unit/Config/RoleResolverTest.php`
Expected: FAIL — `Call to undefined method ...::resolveForSession()`.

- [ ] **Step 3: Add `resolveForSession()`** to `src/Config/RoleResolver.php` immediately after `resolve()` (~L67):

```php
    /**
     * CAP 0.5.0 model precedence (D2): a non-null session model overrides
     * everything; a null/empty session model means "inherit", falling through
     * to the existing role -> persona -> instance-default chain. This is the
     * single authority for effective-model selection wherever a session exists.
     */
    public function resolveForSession(?string $sessionModel, string $role, ?string $persona = null): string
    {
        if ($sessionModel !== null && $sessionModel !== '') {
            return $this->config->resolveModel($sessionModel);
        }

        return $this->resolve($role, $persona);
    }
```

- [ ] **Step 4: Run the unit tests to confirm they pass.**

Run: `./vendor/bin/pest tests/Unit/Config/RoleResolverTest.php`
Expected: PASS (all cases, including the pre-existing ones).

- [ ] **Step 5: Thread the session model through `AgentRunner`.** At each of the four call sites currently calling `$this->roleResolver->resolve($effectiveRole/$role, $persona)` (`doRun` ~L268, `executeSegment` ~L656, `createAgent` ~L934, `buildPreviewContext` ~L1382), load the session row (each has `$sessionId` in scope; `$this->storage` is injected) and call the new method. Pattern at each site:

```php
$session = $this->storage->getSession($sessionId);
$sessionModel = is_string($session['model'] ?? null) && $session['model'] !== '' ? $session['model'] : null;
$modelString = $this->roleResolver->resolveForSession($sessionModel, $effectiveRole, $persona);
```

Reuse an already-loaded session row if the method already fetched one (e.g. `doRun` calls `loadConversation`, but fetch `getSession` explicitly for the model — do not overload `loadConversation`). Keep the change minimal and local at each site. Do NOT alter which model is written to the turn record vs used to build the provider — both must be the same `$modelString` as today, now session-aware.

- [ ] **Step 6: Fold the two inline group-path checks** so group and single paths share one rule. In `src/Command/TurnRunCommand.php` (~L179–181) and `src/Repl/AgentTurnExecutor.php` (~L198–200), replace the inline `$session['model'] ?: $roleResolver->resolve($sessionRole, null)` idiom with:

```php
$modelString = $roleResolver->resolveForSession(
    is_string($session['model'] ?? null) && $session['model'] !== '' ? $session['model'] : null,
    $sessionRole,
    null,
);
```

(Match each call site's existing local variable names for `$session`, `$sessionRole`.)

- [ ] **Step 7: Strengthen the CORE-15 conformance test.** In `tests/conformance/CoreChecklistTest.php`, extend the existing `it('CORE-15: ...')` (~L57) so it also asserts precedence behavior via `RoleResolver::resolveForSession` — a non-null session model wins, a null session model inherits the role chain. Keep the existing producibility assertion (null passes through the wire as null). Add `use CoquiBot\Coqui\Config\RoleResolver;` if needed; build the resolver with the same helper the unit test uses (extract a tiny local fixture builder if convenient).

- [ ] **Step 8: Run the full suite and analyser.**

Run: `composer test && composer analyse`
Expected: PASS. Confirm no group-turn behavior regressed (the group path now routes through `resolveForSession`, which is behavior-identical for a non-null session model).

- [ ] **Step 9: Commit.**

```bash
git add -A && git commit -m "feat(runtime): session-aware model precedence resolver (D2, CORE-15)"
```

---

### Task 2: Per-session workspace re-root for agents + child runs (D3, CORE-19 behavioral)

Give `SessionStorage::createSession` a `workspace` parameter (so callers — including loop stages in Task 3 — can persist a workspace), and add a resolver that computes a session's effective workspace (`sessions.workspace` when set, else the global workspace). Thread it through `AgentRunner`'s two `OrchestratorAgent` construction sites so the shell/files toolkits root per session; child runs inherit for free because `SpawnAgentTool` copies the orchestrator's `workspacePath`.

> **Scope note:** the HTTP `/sessions` create body → `workspace` plumbing (through `SessionScopeResolver` + session-type handlers) is deferred to Phase 4 (§B API surface, session create/PATCH bodies). Phase 3 persists `workspace` via the `createSession` parameter and reads it at the runtime seam; the behavioral gate sets it directly.

**Files:**
- Create: `src/Agent/SessionWorkspaceResolver.php`
- Modify: `src/Storage/SessionStorage.php` (`createSession` signature + INSERT, ~L466–505)
- Modify: `src/Agent/AgentRunner.php` (construct a `SessionWorkspaceResolver`; use it at the two `OrchestratorAgent` builds ~L966–972 and ~L1405–1410, passing the effective workspace as `workspacePath:`)
- Test: `tests/Unit/Agent/SessionWorkspaceResolverTest.php`
- Test: `tests/Unit/Storage/SessionStorageTest.php` (createSession persists + getSession returns `workspace`)
- Test: `tests/conformance/CoreChecklistTest.php` (strengthen CORE-19 with an inheritance assertion via the resolver)

**Interfaces:**
- Consumes: `SessionStorage::getSession(string $id): ?array` (row already hydrates `workspace`, nullable — `SessionStorage.php:1728–1730`); `AgentRunner` holds `private readonly string $workspacePath` (global, L99) and `SessionStorage $storage` (L100); `OrchestratorAgent` takes `workspacePath:` (constructor L213) and passes it to `FileSystemToolkit`/`ShellToolkit` (L381–420) and `SpawnAgentTool` (L648).
- Produces: `SessionWorkspaceResolver::resolve(?string $sessionId): string`; `SessionStorage::createSession(..., ?string $workspace = null)`.

- [ ] **Step 1: Write the failing resolver test** `tests/Unit/Agent/SessionWorkspaceResolverTest.php`:

```php
<?php
declare(strict_types=1);

use CoquiBot\Coqui\Agent\SessionWorkspaceResolver;
use CoquiBot\Coqui\Storage\SessionStorage;

test('resolves a session workspace when the column is set, else the global default', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-wsres-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    try {
        $resolver = new SessionWorkspaceResolver($storage, '/global/ws');

        $rooted = $storage->createSession('orchestrator', null, 'caelum', workspace: '/srv/agents/ws-7');
        expect($resolver->resolve($rooted))->toBe('/srv/agents/ws-7');

        $unrooted = $storage->createSession('orchestrator', null, 'caelum');
        expect($resolver->resolve($unrooted))->toBe('/global/ws');

        // Null session id and unknown session both fall back to the global default.
        expect($resolver->resolve(null))->toBe('/global/ws');
        expect($resolver->resolve('does-not-exist'))->toBe('/global/ws');
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
});
```

- [ ] **Step 2: Run it to confirm failure** (class + param missing).

Run: `./vendor/bin/pest tests/Unit/Agent/SessionWorkspaceResolverTest.php`
Expected: FAIL — class `SessionWorkspaceResolver` not found (and `createSession` has no `workspace` param yet).

- [ ] **Step 3: Add the `workspace` parameter to `createSession`.** In `src/Storage/SessionStorage.php` (~L466), add `?string $workspace = null,` to the signature (after `$visibility`); add `workspace` to the INSERT column list and `VALUES`, and bind it:

```php
    public function createSession(
        string $modelRole,
        ?string $model,
        ?string $persona = null,
        bool $groupEnabled = false,
        ?string $groupCompositionKey = null,
        ?int $groupMaxRounds = null,
        SessionType|string|null $sessionType = null,
        string $visibility = 'visible',
        ?string $workspace = null,
    ): string
```

INSERT: add `, workspace` to the column list and `, :workspace` to `VALUES`; bind `'workspace' => ($workspace !== null && $workspace !== '') ? $workspace : null,`.

- [ ] **Step 4: Create `src/Agent/SessionWorkspaceResolver.php`:**

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Agent;

use CoquiBot\Coqui\Storage\SessionStorage;

/**
 * D3: resolves the effective filesystem/shell root for a session. A session's
 * opaque `workspace` (when set) wins; otherwise the instance-global workspace
 * is used. Loop stages and child runs inherit whatever a session resolves to.
 */
final readonly class SessionWorkspaceResolver
{
    public function __construct(
        private SessionStorage $storage,
        private string $defaultWorkspace,
    ) {}

    public function resolve(?string $sessionId): string
    {
        if ($sessionId === null) {
            return $this->defaultWorkspace;
        }

        $session = $this->storage->getSession($sessionId);
        $workspace = $session['workspace'] ?? null;

        return is_string($workspace) && $workspace !== '' ? $workspace : $this->defaultWorkspace;
    }
}
```

- [ ] **Step 5: Run the resolver + storage tests to confirm they pass.**

Run: `./vendor/bin/pest tests/Unit/Agent/SessionWorkspaceResolverTest.php tests/Unit/Storage/SessionStorageTest.php`
Expected: PASS. (Add a `SessionStorageTest` case asserting `createSession(..., workspace: '/x')` round-trips through `getSession(...)['workspace']`, and that the default is `null`.)

- [ ] **Step 6: Wire the resolver into `AgentRunner`.** Construct one instance (it has `$this->storage` + `$this->workspacePath`), e.g. lazily in a private helper `effectiveWorkspace(?string $sessionId): string` that memoizes a `SessionWorkspaceResolver($this->storage, $this->workspacePath)`, OR build it once in the constructor. At **both** `OrchestratorAgent` construction sites (~L966–972 and ~L1405–1410), replace `workspacePath: $this->workspacePath` with `workspacePath: $this->effectiveWorkspace($sessionId)` (use the `$sessionId` already in scope at each site). Do not change any other constructor argument. Child runs inherit automatically: `OrchestratorAgent` passes its now-per-session `workspacePath` to `SpawnAgentTool` (L648), which roots child toolkits there (recon confirmed).

- [ ] **Step 7: Strengthen the CORE-19 conformance test.** In `tests/conformance/CoreChecklistTest.php`, extend `it('CORE-19: ...')` (~L74) to also assert the *inheritance* behavior through `SessionWorkspaceResolver`: a session with a `workspace` resolves to that path; a session without one resolves to the supplied global default; a `null` session id resolves to the global default. Keep the existing producibility assertions (workspace echoed verbatim on the wire, null when unset).

- [ ] **Step 8: Run the full suite and analyser.**

Run: `composer test && composer analyse`
Expected: PASS.

- [ ] **Step 9: Commit.**

```bash
git add -A && git commit -m "feat(runtime): per-session workspace re-root for agents + child runs (D3, CORE-19)"
```

---

### Task 3: Loop-stage + headless workspace inheritance (D3, CORE-21 behavioral)

Loop stages and the auto-provisioned headless work-scope session must inherit the workspace of the session that owns the loop. Prior-stage output threading already exists (Task confirms it in an assertion). With `createSession` now accepting `workspace` (Task 2), pass the parent session's workspace when creating stage sessions and the headless work-scope session.

**Files:**
- Modify: `src/Api/LoopManager.php` (stage `createSession` ~L173–179 — pass the work-scope session's `workspace`)
- Modify: `src/Agent/LoopExecutor.php` (headless work-scope `createSession` ~L122–129 — inherit the loop-owning session's `workspace`)
- Test: `tests/Unit/Api/LoopManagerTest.php` (stage session inherits workspace)
- Test: `tests/conformance/CoreChecklistTest.php` (CORE-21: replace the `->todo()` row with a real `it()` asserting both halves)

**Interfaces:**
- Consumes: `SessionStorage::createSession(..., ?string $workspace = null)` (Task 2); `SessionStorage::getSession()['workspace']`; the existing loop-stage dispatch that reads the work-scope/parent session id (`LoopManager.php:167–204`, `$task['parent_session_id']` / `loop['session_id']`); the prior-output threading seam (`LoopManager::createStageArtifact` `loop_output`, `LoopExecutor::completeStage` 2000-char summary, `LoopExecutor::buildStagePrompt` "Previous Stages This Cycle").
- Produces: stage + headless sessions whose `workspace` equals the owning session's `workspace`.

- [ ] **Step 1: Write the failing test** in `tests/Unit/Api/LoopManagerTest.php` (match the file's existing setup style): create a work-scope session with a `workspace`, drive a stage dispatch, and assert the created stage session's `workspace` equals the parent's. Expected initial failure: stage session `workspace` is `null` (not inherited).

- [ ] **Step 2: Run it to confirm failure.**

Run: `./vendor/bin/pest tests/Unit/Api/LoopManagerTest.php`
Expected: FAIL — stage session workspace is `null`.

- [ ] **Step 3: Pass the parent workspace at the stage `createSession`.** In `src/Api/LoopManager.php` (~L173), fetch the work-scope/parent session's workspace and pass it:

```php
$parentWorkspace = null;
$parent = $this->storage->getSession($workScopeSessionId); // the id already used for parentSessionId propagation
if (is_array($parent) && is_string($parent['workspace'] ?? null) && $parent['workspace'] !== '') {
    $parentWorkspace = $parent['workspace'];
}

$sessionId = $this->storage->createSession(
    modelRole: $stageResult->role,
    model: '',
    persona: $activePersona,
    visibility: 'hidden',
    workspace: $parentWorkspace,
);
```

(Use the variable already holding the work-scope session id at this site — recon: the stage is created under the work-scope session, and `setActiveProject` already propagates from it at L181–187.)

- [ ] **Step 4: Inherit the workspace for the headless work-scope session.** In `src/Agent/LoopExecutor.php` (~L122–129), when auto-provisioning the hidden work-scope session for a headless loop, pass the loop-owning session's `workspace` when one exists (the owning session id is `$sessionId` bound at `startLoop`, L173–178 reads its persona; read its `workspace` the same way and thread it into the `createSession(..., workspace: ...)` call).

- [ ] **Step 5: Run the LoopManager test to confirm it passes.**

Run: `./vendor/bin/pest tests/Unit/Api/LoopManagerTest.php`
Expected: PASS.

- [ ] **Step 6: Add the CORE-21 conformance test.** In `tests/conformance/CoreChecklistTest.php`, remove the `'CORE-21: ...'` string from the `$rows` array and add a real `it('CORE-21: loop stages thread prior-stage output and inherit the session workspace')->group('conformance')` that asserts **both**: (a) a stage session created under a work-scope session with a `workspace` inherits that `workspace`; (b) the prior-output threading seam is present — assert the `loop_output` artifact / truncated-summary path produces a "Previous Stages This Cycle" context for stage N+1 (drive one stage to completion and assert the next stage's prompt references the prior output, or assert `createStageArtifact` wrote a `loop_output` artifact). Keep it hermetic (temp SQLite, `cleanupSqliteTestDb`).

- [ ] **Step 7: Run the full suite and analyser.**

Run: `composer test && composer analyse`
Expected: PASS.

- [ ] **Step 8: Commit.**

```bash
git add -A && git commit -m "feat(runtime): loop-stage + headless workspace inheritance (D3, CORE-21)"
```

---

### Task 4: Delete the loop question-block path; loops auto-answer (D4, CORE-20)

Delete the entire loop question-block subsystem and the `on_question` loop-definition field. Loops must never block on a question: the loop/background responder auto-answers with the agent's suggested answer (which `QuestionRequest` guarantees is a valid, non-null `QuestionResponse`). Keep `SuspendingQuestionResponder` (API) and `InteractiveQuestionResponder` (REPL) — both already return non-null and are independent of the deleted classes.

**Delete (files):**
- `src/Question/PolicyQuestionResponder.php`
- `src/Contract/OnQuestionPolicy.php`
- `src/Question/LoopQuestionBlockNotifier.php`
- `src/Contract/LoopBlockNotifier.php` (orphaned interface — sole implementer + sole injector are both deleted)
- `src/Api/LoopQuestionAnswerReopener.php`
- `src/Api/QuestionAnswerReopener.php` (orphaned interface — sole implementer deleted; `QuestionHandler` already tolerates a null reopener)
- `tests/Unit/Question/PolicyQuestionResponderTest.php`
- `tests/Unit/Api/LoopQuestionAnswerReopenerTest.php`
- `tests/Integration/Loop/LoopQuestionFlowTest.php`

**Create:**
- `src/Question/DefaultingQuestionResponder.php` (non-blocking loop/bg responder)
- `tests/Unit/Question/DefaultingQuestionResponderTest.php`

**Modify:**
- `src/Contract/QuestionResponderInterface.php` (return type `?QuestionResponse` → `QuestionResponse`; drop the null/block contract from the docblock)
- `src/Question/SuspendingQuestionResponder.php` + `src/Question/InteractiveQuestionResponder.php` (tighten `ask()` return type to `QuestionResponse`)
- `src/Toolkit/QuestionToolkit.php` (delete the `$answer === null` block-branch ~L128–140; `$answer` is now non-null; fix the tool-description string ~L55 that mentions `on_question: block` / `QUESTION_BLOCKED`)
- `src/Contract/LoopDefinition.php` (remove `on_question`: import L8-ish, docblock L24, property/ctor default L32, `fromArray` read L83, `toArray` write L115)
- `src/Command/TaskRunCommand.php` (replace the `on_question`/`LoopQuestionBlockNotifier`/`PolicyQuestionResponder` block ~L169–203 with a `DefaultingQuestionResponder`)
- `src/Command/ApiCommand.php` (~L356 — stop constructing/injecting `LoopQuestionAnswerReopener`; pass `null` or drop the `QuestionHandler` reopener argument)
- `src/Agent/LoopExecutor.php` (remove the dead `pending_answer` mechanism: the metadata read ~L261–268, the param `?array $pendingAnswer` ~L876, the prompt-build consumption ~L973–975, the clear ~L295–296, and the block-comment ~L257–259 referencing the reopener — leave `pending_guidance` intact, it is the operator-retry path and survives)
- `tests/Unit/Contract/LoopDefinitionTest.php` (remove the `on_question defaults to block and round-trips` case ~L345–359 and the `use OnQuestionPolicy` L7)
- `docs/LOOPS.md` (delete the "Non-interactive questions (`on_question`)" section ~L262–275 and the cross-link ~L408; keep the `blocked` circuit-breaker lifecycle ~L137,147–176)
- `docs/QUESTIONS.md` (delete the `PolicyQuestionResponder` row ~L96 and the `on_question` policy section ~L100–139; keep Suspending/Interactive)
- `AGENTS.md` (~L32 — drop the `on_question` mention in the QUESTIONS.md pointer)
- Test: `tests/conformance/CoreChecklistTest.php` (CORE-20 real assertion)

**Interfaces:**
- Consumes: `QuestionPersistence` (shared, kept); `QuestionRequest::$suggested` — a **guaranteed-valid, non-null** `QuestionResponse` (`QuestionRequest` constructor throws if `!$suggested->isValidFor($this)`); `AgentRunner::runForTask(..., questionResponder:)` (the loop/bg entrypoint slot).
- Produces: `DefaultingQuestionResponder implements QuestionResponderInterface` with `ask(QuestionRequest): QuestionResponse` returning `$question->suggested`.

- [ ] **Step 1: Write the failing test** `tests/Unit/Question/DefaultingQuestionResponderTest.php`:

```php
<?php
declare(strict_types=1);

use CoquiBot\Coqui\Contract\QuestionFormat;
use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Contract\QuestionResponse;
use CoquiBot\Coqui\Question\DefaultingQuestionResponder;
use CoquiBot\Coqui\Question\QuestionPersistence;
use CoquiBot\Coqui\Storage\SessionStorage;

test('a loop question is auto-answered with the suggested answer and never blocks', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-defresp-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    try {
        $sessionId = $storage->createSession('orchestrator', null, 'caelum');
        $responder = new DefaultingQuestionResponder(new QuestionPersistence($storage), $sessionId);

        $question = new QuestionRequest(
            id: 'q_1',
            prompt: 'Proceed?',
            format: QuestionFormat::FreeText,
            options: [],
            allowOther: false,
            suggested: new QuestionResponse(text: 'yes'),
        );

        $answer = $responder->ask($question);
        expect($answer)->toBeInstanceOf(QuestionResponse::class);   // never null
        expect($answer->text)->toBe('yes');
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
});
```

- [ ] **Step 2: Run it to confirm failure** (class missing).

Run: `./vendor/bin/pest tests/Unit/Question/DefaultingQuestionResponderTest.php`
Expected: FAIL — `DefaultingQuestionResponder` not found.

- [ ] **Step 3: Create `src/Question/DefaultingQuestionResponder.php`:**

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Question;

use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Contract\QuestionResponderInterface;
use CoquiBot\Coqui\Contract\QuestionResponse;

/**
 * Non-interactive responder for loop stages / background tasks (D4).
 *
 * Loops never block on a question: this responder auto-answers with the
 * agent's suggested answer, which QuestionRequest guarantees is valid and
 * non-null. It persists the asked + answered records for audit, exactly like
 * an operator answer would.
 */
final class DefaultingQuestionResponder implements QuestionResponderInterface
{
    public function __construct(
        private readonly QuestionPersistence $persistence,
        private readonly string $sessionId,
        private readonly ?string $turnId = null,
        private readonly ?string $loopId = null,
        private readonly ?string $stageId = null,
    ) {}

    public function ask(QuestionRequest $question): QuestionResponse
    {
        $this->persistence->persistAsked(
            $this->sessionId, $question, 'default', $this->turnId, $this->loopId, $this->stageId,
        );
        $this->persistence->persistAnswered(
            $question->id, $this->sessionId, $question, $question->suggested, $this->turnId,
        );

        return $question->suggested;
    }
}
```

- [ ] **Step 4: Tighten the interface + KEEP responders.** In `src/Contract/QuestionResponderInterface.php` change `public function ask(QuestionRequest $question): ?QuestionResponse;` to `: QuestionResponse;` and rewrite the docblock to drop the null/block-escalation contract (it no longer exists). Update `src/Question/SuspendingQuestionResponder.php` and `src/Question/InteractiveQuestionResponder.php` `ask()` return types to `QuestionResponse` (both already return non-null / throw on timeout).

- [ ] **Step 5: Remove the QuestionToolkit block-branch.** In `src/Toolkit/QuestionToolkit.php`, delete the `if ($answer === null) { ... QUESTION_BLOCKED ... }` block (~L128–140); `$answer` is now non-null and flows straight into the `ToolResult::json([...])` return. Fix the tool-description string (~L55) to remove the `on_question: block` / `QUESTION_BLOCKED` language.

- [ ] **Step 6: Remove `on_question` from `LoopDefinition`.** Delete the `use ...OnQuestionPolicy;` import, the `@param OnQuestionPolicy $onQuestion` docblock line, the `public OnQuestionPolicy $onQuestion = OnQuestionPolicy::Block,` ctor property, the `onQuestion: OnQuestionPolicy::fromString($data['on_question'] ?? null),` line in `fromArray`, and the `'on_question' => $this->onQuestion->value,` line in `toArray`. (The loop-definition JSON schema already forbids `on_question` via `additionalProperties:false` — this makes the runtime stop emitting/reading it.)

- [ ] **Step 7: Replace the loop/bg responder wiring** in `src/Command/TaskRunCommand.php` (~L169–203). Delete the whole `$onQuestion` / `LoopStore` / `on_question` / `LoopQuestionBlockNotifier` resolution block and the `PolicyQuestionResponder` construction. Replace with:

```php
// Loops and background tasks never block on a question (D4): auto-answer
// with the agent's suggested answer. Loop/stage ids are threaded for audit
// when present in the task handoff metadata.
$loopId = null;
$stageId = null;
$meta = is_string($task['metadata'] ?? null) ? json_decode($task['metadata'], true) : null;
if (is_array($meta) && isset($meta['loop_id'])) {
    $loopId = (string) $meta['loop_id'];
    $stageId = isset($meta['stage_id']) ? (string) $meta['stage_id'] : null;
}
$questionResponder = new \CoquiBot\Coqui\Question\DefaultingQuestionResponder(
    new \CoquiBot\Coqui\Question\QuestionPersistence($storage),
    $sessionId,
    turnId: null,
    loopId: $loopId,
    stageId: $stageId,
);
```

- [ ] **Step 8: Drop the reopener injection.** In `src/Command/ApiCommand.php` (~L356) stop constructing `LoopQuestionAnswerReopener`; pass `null` for the `QuestionHandler` reopener argument (or remove the now-unused constructor parameter from `QuestionHandler` and its call — recon: `QuestionHandler` already guards `if ($this->reopener !== null)`, so passing `null` is safe and the reopen path becomes unreachable).

- [ ] **Step 9: Remove the dead `pending_answer` path** in `src/Agent/LoopExecutor.php`: delete the `pending_answer` metadata read (~L261–268), the `?array $pendingAnswer` param (~L876) and its callers/threading, the prompt-build consumption (~L973–975), the clear (~L295–296), and the reopener reference in the comment (~L257–259). **Keep** `pending_guidance` (operator-retry path — unrelated to questions).

- [ ] **Step 10: Delete the obsolete files and tests.** `git rm` the six deletion-target source/interface files and the three test files listed above. Rewrite `tests/Unit/Contract/LoopDefinitionTest.php`: remove the `use ...OnQuestionPolicy;` and the `on_question defaults to block and round-trips` case; assert instead that a parsed/round-tripped `LoopDefinition::toArray()` contains **no** `on_question` key.

- [ ] **Step 11: Add the CORE-20 conformance test.** In `tests/conformance/CoreChecklistTest.php`, remove the `'CORE-20: ...'` string from `$rows` and add `it('CORE-20: loop definitions carry no on_question; the invalid vector is rejected')->group('conformance')` asserting BOTH: (a) `ConformanceValidator::isValid('loop-definition.json', <decoded invalid/loopdef.on-question.json vector>)` is `false` (schema rejects the extra field); (b) a `LoopDefinition` built and `->toArray()`-ed has no `on_question` key. Load the vector from `tests/conformance/spec/conformance/vectors/invalid/loopdef.on-question.json` and decode with `false` (object) as the other conformance tests do.

- [ ] **Step 12: Update docs.** Apply the `docs/LOOPS.md`, `docs/QUESTIONS.md`, `AGENTS.md` edits listed in **Files**. If a heading changes, check `tests/Unit/Config/DocumentationIndexRealDocsTest.php` still passes.

- [ ] **Step 13: Run the full suite and analyser.**

Run: `composer test && composer analyse`
Expected: PASS. PHPStan will flag any missed reference to a deleted class — fix by removing the reference (there should be none outside the listed sites).

- [ ] **Step 14: Commit.**

```bash
git add -A && git commit -m "feat(runtime): loops never block on a question; delete block path + on_question (D4, CORE-20)"
```

---

### Task 5: Missing stage role/definition → blocked + Critical (CORE-23)

A stage whose role index has no definition currently returns `null` from `prepareNextStage`, indistinguishable from "iteration complete" — the loop re-ticks forever (silent stall). Instead, escalate the loop to `blocked` with a `Critical` finding, reusing the existing `escalateBlocked()` + `StageFinding` mechanism.

**Files:**
- Modify: `src/Agent/LoopExecutor.php` (`prepareNextStage` ~L246–250)
- Test: `tests/Unit/Agent/LoopExecutorBlockedStageTest.php` (add a missing-role case)
- Test: `tests/conformance/CoreChecklistTest.php` (CORE-23 real assertion)

**Interfaces:**
- Consumes: `LoopExecutor::escalateBlocked(array $loop, string $iterationId, string $reason, array $findings, int $attempts): void` (private, same class, L836); `StageFinding(StageSeverity $severity, string $summary, ?string $location = null)`; `StageSeverity::Critical`. Both `StageFinding` and `StageSeverity` are already imported in `LoopExecutor` (used by `escalateBlocked`/`buildNonGateVerdict`).
- Produces: on a missing role index, the loop transitions to `blocked` with a Critical finding instead of returning a bare `null`.

- [ ] **Step 1: Write the failing test** in `tests/Unit/Agent/LoopExecutorBlockedStageTest.php` (mirror the file's existing setup — it already covers `artifact_required`/self-signalled blocked): construct a loop whose iteration has a pending stage at an index the definition has no role for, call `prepareNextStage($loopId)`, and assert the loop status is now `blocked` with an `escalation` whose findings include a `critical` severity. Expected initial behavior: status stays `running` (stall), no escalation.

- [ ] **Step 2: Run it to confirm failure.**

Run: `./vendor/bin/pest tests/Unit/Agent/LoopExecutorBlockedStageTest.php`
Expected: FAIL — loop remains `running`, no escalation recorded.

- [ ] **Step 3: Escalate on the missing-role branch.** In `src/Agent/LoopExecutor.php`, replace the bare null return (~L246–250) with an escalation (the `$loop` and `$iteration` arrays are in scope from the method body above):

```php
$stageIndex = (int) $nextStage['stage_index'];
$roleDefinition = $definition->roles[$stageIndex] ?? null;
if ($roleDefinition === null) {
    // CORE-23: a stage whose role/definition is undefined at dispatch is a hard
    // failure, not a silent stall. Escalate to `blocked` with a Critical finding
    // so the operator is notified instead of the loop re-ticking forever.
    $this->escalateBlocked(
        $loop,
        (string) $iteration['id'],
        sprintf('Loop definition "%s" has no role at stage index %d.', $definition->name, $stageIndex),
        [new StageFinding(
            StageSeverity::Critical,
            sprintf('Stage %d has no role/definition to dispatch.', $stageIndex),
        )],
        0,
    );

    return null;
}
```

- [ ] **Step 4: Run the blocked-stage test to confirm it passes.**

Run: `./vendor/bin/pest tests/Unit/Agent/LoopExecutorBlockedStageTest.php`
Expected: PASS. Confirm the legitimate "iteration complete" null (no pending stage, ~L242–244) is untouched — that branch returns before reaching the role lookup, so it still returns `null` without escalating.

- [ ] **Step 5: Add the CORE-23 conformance test.** In `tests/conformance/CoreChecklistTest.php`, remove the `'CORE-23: ...'` string from `$rows` and add `it('CORE-23: a stage whose role/definition is undefined at dispatch resolves blocked + Critical')->group('conformance')` driving the same scenario and asserting the loop is `blocked` with a Critical finding.

- [ ] **Step 6: Run the full suite and analyser.**

Run: `composer test && composer analyse`
Expected: PASS.

- [ ] **Step 7: Commit.**

```bash
git add -A && git commit -m "feat(runtime): missing stage role resolves blocked + Critical (CORE-23)"
```

---

### Task 6: `artifact_required` persona-gated 422 at loop creation (CORE-22)

A loop definition requiring `artifact_required` on any role, submitted against a session whose persona lacks the `artifacts` feature, must be rejected **422 at loop creation** — not discovered mid-run. The capability is the persona feature flag `PersonaPreferences::isFeatureEnabled('artifacts')` (there is no standalone "artifacts profile" object). The error catalog's full HTTP-status swap is Phase 4; here, add a narrow status override so `createLoop` returns 422 with the closed-set code `validation_error`.

**Files:**
- Modify: `src/Api/Router.php` (`errorResponse` — add an optional status override, L154–157)
- Modify: `src/Api/Handler/LoopHandler.php` (`create` — inject persona-feature check + 422; ~L62–176; constructor for a `PersonaDiscovery`/preferences dependency)
- Test: `tests/Unit/Api/Handler/LoopHandlerTest.php` (422 rejection case)
- Test: `tests/conformance/CoreChecklistTest.php` (CORE-22 real assertion)

**Interfaces:**
- Consumes: `PersonaPreferences::fromPersonaPath($personaDiscovery->getPersonaPath($persona))` then `->isFeatureEnabled('artifacts', true)` (pattern from `TaskHandler.php:173`, `SpawnAgentTool.php:545`); `LoopRoleDefinition::$artifactRequired` (bool, per role); `LoopDefinition::fromArray(...)->roles`; the loop's session persona (`$session['persona_id']` from the already-validated session in `create`); `ApiErrorCode::VALIDATION_ERROR` (code string `validation_error`, in the closed error set).
- Produces: `Router::errorResponse(ApiErrorCode $code, string $message, mixed $details = null, ?int $status = null): Response`; a 422 `validation_error` response from `LoopHandler::create` when the gate fails.

- [ ] **Step 1: Add the status override to `Router::errorResponse` (test-first).** Add a Router test (in the existing Router test file, or a focused new one) asserting `Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'x', null, 422)->getStatusCode() === 422` while the default (no override) still yields `400`.

- [ ] **Step 2: Run it to confirm failure.**

Run: `./vendor/bin/pest --filter=errorResponse`
Expected: FAIL — 4th argument not accepted.

- [ ] **Step 3: Implement the override.** In `src/Api/Router.php`:

```php
public static function errorResponse(ApiErrorCode $code, string $message, mixed $details = null, ?int $status = null): Response
{
    return self::jsonResponse($code->toPayload($message, $details), $status ?? $code->httpStatus());
}
```

Run: `./vendor/bin/pest --filter=errorResponse` → PASS. (This is additive; every existing call keeps its default status.)

- [ ] **Step 4: Write the failing 422 test** in `tests/Unit/Api/Handler/LoopHandlerTest.php`: register a loop definition with a role carrying `artifact_required: true`; create a session whose persona has `artifacts` disabled in `preferences.json` (`{"features":{"artifacts":false}}`); POST to `create`; assert the response status is `422` and the body `code` is `validation_error`. Also add/keep a positive case: the same definition on a persona **with** artifacts enabled (or no session) is accepted (201).

- [ ] **Step 5: Run it to confirm failure.**

Run: `./vendor/bin/pest tests/Unit/Api/Handler/LoopHandlerTest.php`
Expected: FAIL — currently accepted (201), no gate.

- [ ] **Step 6: Implement the gate in `LoopHandler::create`.** After the definition exists and the session (if any) is validated (~after L102, before `startLoop` at L154), parse the definition, and if any role requires an artifact while the session's persona lacks the `artifacts` feature, reject 422:

```php
$parsed = LoopDefinition::fromArray(
    json_decode($this->discovery->getRawDefinition($definition), true, 512, JSON_THROW_ON_ERROR),
);
$requiresArtifact = false;
foreach ($parsed->roles as $roleDef) {
    if ($roleDef->artifactRequired) { $requiresArtifact = true; break; }
}
if ($requiresArtifact && !$this->personaArtifactsEnabled($session['persona_id'] ?? null)) {
    return Router::errorResponse(
        ApiErrorCode::VALIDATION_ERROR,
        'This loop definition requires durable artifacts, but the session persona has the artifacts capability disabled.',
        ['capability' => 'artifacts'],
        422,
    );
}
```

Add a private helper (inject `PersonaDiscovery` into the handler constructor; wire it in `ApiCommand` where `LoopHandler` is constructed):

```php
private function personaArtifactsEnabled(?string $persona): bool
{
    if ($persona === null || $persona === '' || $this->personaDiscovery === null) {
        return true; // no persona bound (e.g. headless) → do not gate here
    }
    return PersonaPreferences::fromPersonaPath($this->personaDiscovery->getPersonaPath($persona))
        ->isFeatureEnabled('artifacts', true);
}
```

(Use `$session` from the block already validating `session_id` at L98; when `$sessionId` is null there is no `$session` — treat as ungated. Keep `LoopDefinition`/`PersonaPreferences`/`PersonaDiscovery` imports tidy.)

- [ ] **Step 7: Run the LoopHandler test to confirm it passes.**

Run: `./vendor/bin/pest tests/Unit/Api/Handler/LoopHandlerTest.php`
Expected: PASS (both the 422 rejection and the accepted cases).

- [ ] **Step 8: Add the CORE-22 conformance test.** In `tests/conformance/CoreChecklistTest.php`, remove the `'CORE-22: ...'` string from `$rows` and add `it('CORE-22: artifact_required is persona-gated; a def requiring it on a no-artifacts persona is rejected 422 at loop creation')->group('conformance')` exercising the handler path and asserting HTTP `422` + `validation_error`.

- [ ] **Step 9: Run the full suite and analyser.**

Run: `composer test && composer analyse`
Expected: PASS.

- [ ] **Step 10: Commit.**

```bash
git add -A && git commit -m "feat(runtime): artifact_required persona-gated 422 at loop creation (CORE-22)"
```

---

### Task 7: Session delete cascade-stops non-terminal loops (CORE-17)

Deleting a session must stop any non-terminal loop bound to it. Today the `loops.session_id` FK is `ON DELETE SET NULL`, so loops silently orphan and keep running. Add a `LoopStore` lookup by session and cancel non-terminal loops inside `SessionStorage::deleteSession` (the storage layer, so every delete path — HTTP and REPL — is covered).

**Files:**
- Modify: `src/Storage/LoopStore.php` (add `getLoopsBySession(string $sessionId): array`)
- Modify: `src/Storage/SessionStorage.php` (`deleteSession` ~L1995–2017 — cancel non-terminal loops before the `DELETE`)
- Test: `tests/Unit/Storage/LoopStoreTest.php` (or the store's existing test file — `getLoopsBySession` returns loops for a session)
- Test: `tests/Unit/Storage/SessionStorageTest.php` (deleting a session cancels its running loop)
- Test: `tests/conformance/CoreChecklistTest.php` (CORE-17 real assertion)

**Interfaces:**
- Consumes: `LoopStore::updateLoopStatus(string $id, string $status)` (sets `cancelled` + `completed_at`; terminal set is `['completed','failed','cancelled']`, L214); the `loops` table `session_id` column (L41); `SessionStorage` holds its PDO as `$this->db` (a `LoopStore` is constructed elsewhere from a PDO).
- Produces: `LoopStore::getLoopsBySession(string $sessionId): array` (list of loop rows); `SessionStorage::deleteSession` cancels non-terminal loops (`running`/`paused`/`blocked`) for the session before deleting it.

- [ ] **Step 1: Write the failing `getLoopsBySession` test** in the LoopStore test file: create two loops on session A and one on session B; assert `getLoopsBySession('A')` returns exactly the two, `getLoopsBySession('B')` the one, and an unknown session returns `[]`.

- [ ] **Step 2: Run it to confirm failure.**

Run: `./vendor/bin/pest tests/Unit/Storage/LoopStoreTest.php`
Expected: FAIL — method undefined.

- [ ] **Step 3: Add `getLoopsBySession`** to `src/Storage/LoopStore.php` (reuse the existing row hydration used by `getLoop`):

```php
/**
 * @return list<array<string, mixed>>
 */
public function getLoopsBySession(string $sessionId): array
{
    $stmt = $this->db->prepare('SELECT * FROM loops WHERE session_id = :sid');
    $stmt->execute(['sid' => $sessionId]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    return array_map(fn(array $row): array => $this->hydrateLoopRow($row), $rows);
}
```

(Use whatever the file's existing single-row hydration helper is called — mirror `getLoop`'s mapping so returned rows match the shape callers expect. If `getLoop` hydrates inline, extract or replicate the same mapping.)

- [ ] **Step 4: Write the failing cascade test** in `tests/Unit/Storage/SessionStorageTest.php`: create a session; create a `running` loop bound to it (via a `LoopStore` over the same PDO); call `deleteSession($id)`; assert the loop's status is now `cancelled`. Add a second assertion that a **terminal** loop (`completed`) on the same session is left untouched.

- [ ] **Step 5: Run it to confirm failure.**

Run: `./vendor/bin/pest tests/Unit/Storage/SessionStorageTest.php`
Expected: FAIL — loop stays `running` after session delete.

- [ ] **Step 6: Cascade-stop in `deleteSession`.** In `src/Storage/SessionStorage.php` (~L1995, after the existing project-linked-artifact guard and before the `DELETE FROM sessions`), cancel non-terminal loops:

```php
// CORE-17: deleting a session cascade-stops any non-terminal loop bound to
// it, so no orphaned loop keeps ticking after its session is gone.
$loopStore = new LoopStore($this->db);
foreach ($loopStore->getLoopsBySession($id) as $loop) {
    $status = (string) ($loop['status'] ?? '');
    if (!in_array($status, ['completed', 'failed', 'cancelled'], true)) {
        $loopStore->updateLoopStatus((string) $loop['id'], 'cancelled');
    }
}
```

Add `use CoquiBot\Coqui\Storage\LoopStore;` if not already imported (same namespace — a direct reference works without a `use`). Keep the `force` parameter semantics unchanged.

- [ ] **Step 7: Run the storage tests to confirm they pass.**

Run: `./vendor/bin/pest tests/Unit/Storage/SessionStorageTest.php tests/Unit/Storage/LoopStoreTest.php`
Expected: PASS.

- [ ] **Step 8: Add the CORE-17 conformance test.** In `tests/conformance/CoreChecklistTest.php`, remove the `'CORE-17: ...'` string from `$rows` and add `it('CORE-17: deleting a session cascade-stops any non-terminal loop using it')->group('conformance')` asserting a `running` loop bound to a session becomes `cancelled` on `deleteSession`, and a terminal loop is untouched.

- [ ] **Step 9: Run the full suite and analyser.**

Run: `composer test && composer analyse`
Expected: PASS.

- [ ] **Step 10: Commit.**

```bash
git add -A && git commit -m "feat(runtime): session delete cascade-stops non-terminal loops (CORE-17)"
```

---

## Phase Exit Criteria

- CORE-15, 17, 19, 20, 21, 22, 23 are real `it(...)->group('conformance')` assertions (removed from the `$rows` todo list); `todos` drop from 42 → 35.
- `composer test` and `composer analyse` green.
- `src/Config/CatastrophicBlacklist.php` byte-unchanged across all Phase-3 commits; vendored `tests/conformance/spec/**` untouched.
- Project HTTP/wire surface still present and functional (behavioral-only scope).
- Whole-branch review (base = the Phase-2 head the branch review used, i.e. the commit this phase started from) returns Ready-to-merge with no Critical/Important.

## Carry-forwards into Phase 4+

- **Persona-threading gap (Phase 4):** live session/loop/scheduled-task objects can still carry a null `persona_id` (group/headless); thread a default persona at creation so live objects are wire-conformant. CORE-22's ungated-when-headless branch is a symptom.
- **HTTP `/sessions` workspace write (Phase 4):** plumb the create/PATCH body `workspace` through `SessionScopeResolver` + session-type handlers (Phase 3 persists via the `createSession` param only).
- **Error-catalog HTTP-status swap (Phase 4):** Task 6 adds a narrow 422 override on `errorResponse`; Phase 4 reconciles the full catalog → status map (whether `validation_error` globally becomes 422 for authoring ops, etc.).
- **Project wire-surface teardown (later phase):** `/projects` routes, `active_project_id` in responses, `ProjectToolkit`/`ProjectStore` — deferred out of Phase 3 by the behavioral-only decision.
