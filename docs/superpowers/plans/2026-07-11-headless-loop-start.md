# Headless Loop Start Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make loops start cleanly with no conversation — auto-provision a hidden loop-owned work-scope session when none is given (restoring project propagation + cross-stage artifact scoping), record `loop.metadata.origin`, and surface/filter headless loops in the loop API.

**Architecture:** Small, additive change in two places: `LoopExecutor::startLoop` provisions the work-scope session + records origin; `LoopHandler` surfaces `origin`/`headless` and adds a `?headless=` filter. `LoopManager` is untouched — with a non-null `loops.session_id` its existing propagation just works.

**Tech Stack:** PHP 8.4 (`declare(strict_types=1)`), Pest (`composer test`), PHPStan level 8 (`composer analyse`).

**Spec:** `docs/superpowers/specs/2026-07-11-headless-loop-start-design.md`.

## Global Constraints

- PHP 8.4, `declare(strict_types=1);`, `final` by default, 4-space indent, constructor injection.
- **Parallel-launch isolation:** develop in a git worktree off `origin/main` (this runs alongside the Live View + Custom-Definitions agents). From `/home/carmelo/Projects/CoquiBot/Core/coqui`: `git fetch origin && git worktree add -b feat/loop-headless-start-impl ../coqui-loop-headless origin/main && cd ../coqui-loop-headless && composer install`. Your worktree `git status` starts clean; do not touch the primary checkout. (The plan doc lives on `feat/loop-headless-start`; read it via `git show origin/main:...` is not needed — the content is below.)
- **Never `git add -A` / `git add .`** — stage only exact paths.
- Every commit message ends with: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.
- `composer test` and `composer analyse` both green before every commit.
- **Additive only:** no schema change, no `LoopManager` change, no new dependencies.
- **Shared-file note (integration):** `src/Api/Handler/LoopHandler.php`, `docs/API.md`, `docs/LOOPS.md`, and `config/source.json` are also edited by the parallel Live View and Custom-Definitions efforts. Expect the reviewer to resolve small adjacent-line merge conflicts there at integration — keep your edits localized (add near existing loop code; don't reflow unrelated lines).

---

### Task 1: Auto-provision a work-scope session + record origin (`LoopExecutor::startLoop`)

**Files:**
- Modify: `src/Agent/LoopExecutor.php` (inside `startLoop`, right after `resolveProject(...)` and in the `createLoop(...)` `metadata`)
- Test: `tests/Unit/Agent/LoopExecutorTest.php`

**Interfaces:**
- Consumes (existing): `LoopExecutor(LoopStore $loopStore, ProjectStore $projectStore, ?SessionStorage $sessionStorage = null, ?GoalEvaluator = null)`; `SessionStorage::createSession(modelRole, model, ..., visibility)`, `SessionStorage::setActiveProject(string $sessionId, ?string $projectId): void`; `LoopStore::createLoop(..., ?string $sessionId, ..., ?array $metadata)`.
- Produces: a headless loop persists with a non-null hidden `session_id` whose active project = the loop's project, and `loops.metadata.origin ∈ {'headless','conversation'}`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Agent/LoopExecutorTest.php` (mirror the file's existing fixture — it builds `SessionStorage`, `ProjectStore`, `LoopStore`, then `new LoopExecutor($loopStore, $projectStore, $storage)`; a `harness` raw definition array is already used elsewhere in the file — reuse that shape):

```php
test('headless startLoop provisions a hidden loop-owned work-scope session', function (): void {
    $dbPath = sys_get_temp_dir() . '/coqui-loop-headless-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new CoquiBot\Coqui\Storage\SessionStorage($dbPath);
    $projectStore = new CoquiBot\Coqui\Storage\ProjectStore($storage->getPdo());
    $loopStore = new CoquiBot\Coqui\Storage\LoopStore($storage->getPdo());
    $executor = new CoquiBot\Coqui\Agent\LoopExecutor($loopStore, $projectStore, $storage);

    $definition = [
        'name' => 'harness',
        'description' => 'test',
        'roles' => [['role' => 'plan', 'prompt' => 'Do {{subject}}.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => ['max_iterations' => 2]],
        'parameters' => [['name' => 'subject', 'description' => 's', 'required' => false, 'default' => 'x']],
    ];

    $loopId = $executor->startLoop($definition, 'ship it'); // no sessionId → headless

    $loop = $loopStore->getLoop($loopId);
    expect($loop['session_id'])->not->toBeNull();

    $session = $storage->getSession((string) $loop['session_id']);
    expect($session)->not->toBeNull();
    expect($session['visibility'])->toBe('hidden');
    expect($storage->getActiveProjectId((string) $loop['session_id']))->toBe((string) $loop['project_id']);

    $metadata = json_decode((string) $loop['metadata'], true);
    expect($metadata['origin'])->toBe('headless');
});

test('conversation startLoop records origin and does not provision a session', function (): void {
    $dbPath = sys_get_temp_dir() . '/coqui-loop-conv-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new CoquiBot\Coqui\Storage\SessionStorage($dbPath);
    $projectStore = new CoquiBot\Coqui\Storage\ProjectStore($storage->getPdo());
    $loopStore = new CoquiBot\Coqui\Storage\LoopStore($storage->getPdo());
    $executor = new CoquiBot\Coqui\Agent\LoopExecutor($loopStore, $projectStore, $storage);

    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: 'ollama/x');
    $definition = [
        'name' => 'harness',
        'description' => 'test',
        'roles' => [['role' => 'plan', 'prompt' => 'Do it.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => ['max_iterations' => 2]],
    ];

    $loopId = $executor->startLoop($definition, 'ship it', $sessionId);

    $loop = $loopStore->getLoop($loopId);
    expect($loop['session_id'])->toBe($sessionId);
    $metadata = json_decode((string) $loop['metadata'], true);
    expect($metadata['origin'])->toBe('conversation');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/pest tests/Unit/Agent/LoopExecutorTest.php`
Expected: FAIL — `session_id` is null (headless) and `metadata.origin` is unset.

- [ ] **Step 3: Implement in `startLoop`**

In `src/Agent/LoopExecutor.php`, immediately **after** the `$resolvedProjectId = $this->resolveProject(...)` line, insert:

```php
// Headless start: no conversation session was supplied. Auto-provision a
// hidden loop-owned work-scope session so LoopManager can propagate the
// project to stage sessions (cross-stage artifacts are project-scoped) and
// the live view has a work_scope_session_id — full parity with chat loops.
$headless = $sessionId === null;
if ($headless && $this->sessionStorage !== null) {
    $sessionId = $this->sessionStorage->createSession(
        modelRole: 'orchestrator',
        model: '',
        visibility: 'hidden',
    );
    $this->sessionStorage->setActiveProject($sessionId, $resolvedProjectId);
}
```

Then, in the `$this->loopStore->createLoop(...)` call, add `origin` to the `metadata` array (keep the existing `dispatch` entry):

```php
metadata: [
    'origin' => $headless ? 'headless' : 'conversation',
    'dispatch' => [
        'status' => 'pending',
        'message' => 'Waiting for the API loop manager to create the first stage background task.',
        'updated_at' => Clock::nowUtc(),
    ],
],
```

- [ ] **Step 4: Run to verify pass**

Run: `./vendor/bin/pest tests/Unit/Agent/LoopExecutorTest.php`
Expected: PASS (both new tests + existing ones).

- [ ] **Step 5: Commit**

```bash
git add src/Agent/LoopExecutor.php tests/Unit/Agent/LoopExecutorTest.php
git commit -m "$(cat <<'EOF'
feat(loops): provision a work-scope session for headless loops

When startLoop runs with no conversation session, auto-create a hidden
loop-owned session with the loop's project as its active project, and record
metadata.origin. Restores cross-stage artifact scoping with no LoopManager
change.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Surface `origin` + `headless` filter in the loop API (`LoopHandler`)

**Files:**
- Modify: `src/Api/Handler/LoopHandler.php` (a `loopOrigin()` helper; `list()` gains `headless` + `?headless=` filter; `normalizeLoop()` gains `origin`)
- Test: `tests/Unit/Api/Handler/LoopHandlerTest.php`

**Interfaces:**
- Consumes: existing `$this->store->listLoops(?string $status)`, `LoopHandler::normalizeLoop(array $loop)`, the `createLoopHandlerFixture()`/`cleanupLoopHandlerFixture()` helpers and `React\Http\Message\ServerRequest`.
- Produces: `GET /loops` entries carry `headless: bool`; `?headless=true|false` filters; `GET /loops/{id}` loop object carries `origin`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Api/Handler/LoopHandlerTest.php`:

```php
test('GET /loops flags and filters headless loops', function (): void {
    $fixture = createLoopHandlerFixture();

    try {
        // Headless loop (no session) and a conversation loop.
        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/x');
        $rawHarness = [
            'name' => 'harness',
            'description' => 't',
            'roles' => [['role' => 'plan', 'prompt' => 'go']],
            'termination_condition' => ['type' => 'iteration_bound', 'value' => ['max_iterations' => 2]],
        ];
        $headlessLoop = (new CoquiBot\Coqui\Agent\LoopExecutor(
            $fixture['loopStore'], $fixture['projectStore'], $fixture['storage']
        ))->startLoop($rawHarness, 'headless goal');
        $convLoop = (new CoquiBot\Coqui\Agent\LoopExecutor(
            $fixture['loopStore'], $fixture['projectStore'], $fixture['storage']
        ))->startLoop($rawHarness, 'conv goal', $sessionId);

        $all = json_decode((string) $fixture['handler']->list(new ServerRequest('GET', '/api/v1/loops'))->getBody(), true);
        $byId = [];
        foreach ($all['loops'] as $l) { $byId[$l['id']] = $l; }
        expect($byId[$headlessLoop]['headless'])->toBeTrue();
        expect($byId[$convLoop]['headless'])->toBeFalse();

        $filtered = json_decode((string) $fixture['handler']->list(
            new ServerRequest('GET', '/api/v1/loops?headless=true')
        )->getBody(), true);
        $ids = array_map(static fn(array $l): string => $l['id'], $filtered['loops']);
        expect($ids)->toContain($headlessLoop);
        expect($ids)->not->toContain($convLoop);
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('GET /loops/{id} includes origin', function (): void {
    $fixture = createLoopHandlerFixture();

    try {
        $rawHarness = [
            'name' => 'harness', 'description' => 't',
            'roles' => [['role' => 'plan', 'prompt' => 'go']],
            'termination_condition' => ['type' => 'iteration_bound', 'value' => ['max_iterations' => 2]],
        ];
        $loopId = (new CoquiBot\Coqui\Agent\LoopExecutor(
            $fixture['loopStore'], $fixture['projectStore'], $fixture['storage']
        ))->startLoop($rawHarness, 'g');

        $body = json_decode((string) $fixture['handler']->get(
            new ServerRequest('GET', "/api/v1/loops/{$loopId}"), $loopId
        )->getBody(), true);
        expect($body['loop']['origin'])->toBe('headless');
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});
```

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/pest tests/Unit/Api/Handler/LoopHandlerTest.php`
Expected: FAIL — no `headless`/`origin` keys.

- [ ] **Step 3: Implement in `LoopHandler`**

Add a private helper:

```php
/**
 * @param array<string, mixed> $loop
 */
private function loopOrigin(array $loop): string
{
    $metadata = isset($loop['metadata']) && is_string($loop['metadata'])
        ? json_decode($loop['metadata'], true)
        : (is_array($loop['metadata'] ?? null) ? $loop['metadata'] : null);

    $origin = is_array($metadata) && isset($metadata['origin']) ? (string) $metadata['origin'] : 'conversation';

    return $origin === 'headless' ? 'headless' : 'conversation';
}
```

In `list()`, after `$loops = $this->store->listLoops($status);`, apply the filter + enrich. Replace the loops passed to the response with:

```php
$params = $request->getQueryParams();
$headlessFilter = null;
if (isset($params['headless'])) {
    $raw = strtolower(trim((string) $params['headless']));
    if ($raw === 'true' || $raw === '1') { $headlessFilter = true; }
    elseif ($raw === 'false' || $raw === '0') { $headlessFilter = false; }
}

$loops = array_values(array_map(function (array $loop): array {
    $loop['headless'] = $this->loopOrigin($loop) === 'headless';
    return $loop;
}, $loops));

if ($headlessFilter !== null) {
    $loops = array_values(array_filter($loops, static fn(array $l): bool => ($l['headless'] ?? false) === $headlessFilter));
}
```

(Ensure the response uses this enriched/filtered `$loops`.)

In `normalizeLoop(array $loop): array`, add `origin` to the returned array:

```php
$normalized['origin'] = $this->loopOrigin($loop);
```
(Insert next to where the method builds its normalized array, using the raw `$loop` row it received.)

- [ ] **Step 4: Run to verify pass**

Run: `./vendor/bin/pest tests/Unit/Api/Handler/LoopHandlerTest.php`
Expected: PASS.

- [ ] **Step 5: Run PHPStan + commit**

```bash
composer analyse
git add src/Api/Handler/LoopHandler.php tests/Unit/Api/Handler/LoopHandlerTest.php
git commit -m "$(cat <<'EOF'
feat(loops): surface origin + headless filter in the loop API

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Docs + source map

**Files:**
- Modify: `docs/LOOPS.md`, `docs/API.md`, `config/source.json`

- [ ] **Step 1: Document the headless path**

In `docs/LOOPS.md` and `docs/API.md`: `POST /api/v1/loops` with just `{definition, goal}` starts a loop with no conversation; a hidden loop-owned work-scope session is provisioned automatically. Note `GET /loops` carries `headless` and supports `?headless=true|false`, and `GET /loops/{id}` carries `origin`.

- [ ] **Step 2: Update `config/source.json`**

Update the `LoopExecutor` and `LoopHandler` entries' descriptions to mention headless provisioning + the origin/headless surface. No new files.

- [ ] **Step 3: Verify + commit**

```bash
composer test && composer analyse
git add docs/LOOPS.md docs/API.md config/source.json
git commit -m "$(cat <<'EOF'
docs(loops): document headless loop start

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

## Self-Review

- **Spec coverage:** work-scope session provisioning ✓ (Task 1), `metadata.origin` ✓ (Task 1), `GET /loops` `headless` + `?headless=` ✓ (Task 2 `list()`), `GET /loops/{id}` `origin` ✓ (Task 2 `normalizeLoop`), no `SessionType` (Option B) ✓, no `LoopManager` change ✓, docs ✓ (Task 3). The spec's contingent live-view `origin` field is **out of scope here** (depends on unmerged Spec A) — noted as a trivial follow-up.
- **Placeholder scan:** none — complete code throughout. Fixture keys (`storage`,`loopStore`,`projectStore`,`handler`) verified from `LoopHandlerTest`.
- **Edge case:** if `sessionStorage` is null (non-API construction) a headless loop stays session-less as today; `origin` is still recorded. The API path always injects storage, so this is a safe fallback, not a regression.
