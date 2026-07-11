# Custom Loop Definitions via API Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Full CRUD over loop definitions through the API — create/read/update/delete the `workspace/loops/*.json` files that `loop_start` and `POST /loops` run, validated and path-safe.

**Architecture:** Add write methods to `LoopDiscovery` (it owns `workspace/loops/` + the discovery cache) and CRUD endpoints to `LoopHandler` (which already lists definitions). Definitions stay as files — no DB. Validation reuses `LoopDefinition::fromArray()` plus a strict slug/path-traversal name guard.

**Tech Stack:** PHP 8.4 (`declare(strict_types=1)`), Pest (`composer test`), PHPStan level 8 (`composer analyse`).

**Spec:** `docs/superpowers/specs/2026-07-11-custom-loop-definitions-design.md`.

## Global Constraints

- PHP 8.4, `declare(strict_types=1);`, 4-space indent, constructor injection.
- **Parallel-launch isolation:** develop in a git worktree off `origin/main` (runs alongside the Live View + Headless efforts). From `/home/carmelo/Projects/CoquiBot/Core/coqui`: `git fetch origin && git worktree add -b feat/loop-custom-definitions-impl ../coqui-loop-defs origin/main && cd ../coqui-loop-defs && composer install`. Worktree `git status` starts clean; do not touch the primary checkout.
- **Never `git add -A` / `git add .`** — stage only exact paths.
- Every commit message ends with: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.
- `composer test` and `composer analyse` both green before every commit.
- **Additive only:** no schema, no new dependencies. Definitions remain JSON files under `workspace/loops/`.
- **Error model:** `ApiErrorCode` has **no 422** — use `ApiErrorCode::VALIDATION_ERROR` (→ 400) for invalid name **and** invalid structure, `ApiErrorCode::CONFLICT` (→ 409) for create-exists, `ApiErrorCode::NOT_FOUND` (→ 404).
- **Shared-file note (integration):** `src/Api/Handler/LoopHandler.php` (the `register()` route list + class docblock), `docs/API.md`, `docs/LOOPS.md`, and `config/source.json` are also edited by the parallel Live View + Headless efforts. Keep edits localized; the reviewer resolves small adjacent-line conflicts at integration.

---

### Task 1: Write/validate methods on `LoopDiscovery`

**Files:**
- Modify: `src/Config/LoopDiscovery.php` (add `isValidDefinitionName`, `isBuiltin`, `saveDefinition`, `deleteDefinition`)
- Test: `tests/Unit/Config/LoopDiscoveryTest.php`

**Interfaces:**
- Consumes (existing, private): `$this->loopsDir` (target dir), `$this->builtinLoopsDir` (config/loops), `ensureLoopsDir()`, `invalidateCache()`, `exists()`, `discoverAll()`; `LoopDefinition::fromArray()` (already imported).
- Produces:
  - `isValidDefinitionName(string $name): bool`
  - `isBuiltin(string $name): bool`
  - `saveDefinition(string $name, array $definition): void` (throws `\InvalidArgumentException` on invalid name/structure)
  - `deleteDefinition(string $name): bool` (throws `\InvalidArgumentException` on invalid name; returns whether a file was removed)

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Config/LoopDiscoveryTest.php` (mirror the file's existing temp-workspace setup — it creates a temp dir with a `loops/` subdir and `new LoopDiscovery($workspacePath)`):

```php
test('saveDefinition writes a valid definition that becomes discoverable', function (): void {
    $ws = sys_get_temp_dir() . '/coqui-defs-' . bin2hex(random_bytes(8));
    mkdir($ws . '/loops', 0755, true);
    $discovery = new CoquiBot\Coqui\Config\LoopDiscovery($ws);

    $discovery->saveDefinition('my-loop', [
        'name' => 'ignored-name',
        'description' => 'mine',
        'roles' => [['role' => 'plan', 'prompt' => 'go']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => ['max_iterations' => 3]],
    ]);

    expect($discovery->exists('my-loop'))->toBeTrue();
    $raw = $discovery->getRawDefinition('my-loop');
    expect($raw['name'])->toBe('my-loop'); // filename is authoritative
    expect(is_file($ws . '/loops/my-loop.json'))->toBeTrue();
});

test('saveDefinition rejects traversal names without writing', function (): void {
    $ws = sys_get_temp_dir() . '/coqui-defs-' . bin2hex(random_bytes(8));
    mkdir($ws . '/loops', 0755, true);
    $discovery = new CoquiBot\Coqui\Config\LoopDiscovery($ws);

    foreach (['../evil', 'a/b', 'Bad Name', '.hidden'] as $bad) {
        expect(fn() => $discovery->saveDefinition($bad, [
            'roles' => [['role' => 'plan', 'prompt' => 'x']],
            'termination_condition' => ['type' => 'iteration_bound', 'value' => ['max_iterations' => 1]],
        ]))->toThrow(InvalidArgumentException::class);
    }
    // Nothing written outside the loops dir.
    expect(glob($ws . '/loops/*.json'))->toBe([]);
});

test('saveDefinition rejects structurally invalid definitions', function (): void {
    $ws = sys_get_temp_dir() . '/coqui-defs-' . bin2hex(random_bytes(8));
    mkdir($ws . '/loops', 0755, true);
    $discovery = new CoquiBot\Coqui\Config\LoopDiscovery($ws);

    // Missing termination_condition.
    expect(fn() => $discovery->saveDefinition('bad', [
        'roles' => [['role' => 'plan', 'prompt' => 'x']],
    ]))->toThrow(InvalidArgumentException::class);

    // Empty roles.
    expect(fn() => $discovery->saveDefinition('bad', [
        'roles' => [],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => ['max_iterations' => 1]],
    ]))->toThrow(InvalidArgumentException::class);
});

test('deleteDefinition removes a custom file and reports missing', function (): void {
    $ws = sys_get_temp_dir() . '/coqui-defs-' . bin2hex(random_bytes(8));
    mkdir($ws . '/loops', 0755, true);
    $discovery = new CoquiBot\Coqui\Config\LoopDiscovery($ws);
    $discovery->saveDefinition('temp', [
        'roles' => [['role' => 'plan', 'prompt' => 'x']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => ['max_iterations' => 1]],
    ]);

    expect($discovery->deleteDefinition('temp'))->toBeTrue();
    expect($discovery->exists('temp'))->toBeFalse();
    expect($discovery->deleteDefinition('never-existed'))->toBeFalse();
});

test('isBuiltin distinguishes built-in from custom', function (): void {
    $ws = sys_get_temp_dir() . '/coqui-defs-' . bin2hex(random_bytes(8));
    mkdir($ws . '/loops', 0755, true);
    // Default projectRoot points at the repo's config/loops, where harness.json ships.
    $discovery = new CoquiBot\Coqui\Config\LoopDiscovery($ws);

    expect($discovery->isBuiltin('harness'))->toBeTrue();
    expect($discovery->isBuiltin('my-custom-thing'))->toBeFalse();
});
```

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/pest tests/Unit/Config/LoopDiscoveryTest.php`
Expected: FAIL — `Call to undefined method ...::saveDefinition()`.

- [ ] **Step 3: Implement the four methods on `LoopDiscovery`**

Add to `src/Config/LoopDiscovery.php` (`LoopDefinition` is already imported):

```php
/**
 * Validate a definition name — must be a filesystem-safe slug. This is the
 * path-traversal guard: names become filenames.
 */
public function isValidDefinitionName(string $name): bool
{
    return preg_match('/^[a-z0-9][a-z0-9_-]*$/', $name) === 1;
}

/**
 * True when a built-in definition of this name ships in config/loops/.
 */
public function isBuiltin(string $name): bool
{
    if (!$this->isValidDefinitionName($name)) {
        return false;
    }

    return is_file($this->builtinLoopsDir . '/' . $name . '.json');
}

/**
 * Validate and persist a loop definition to workspace/loops/{name}.json.
 *
 * @param array<string, mixed> $definition
 * @throws \InvalidArgumentException on an invalid name or structure
 * @throws \RuntimeException on a write failure
 */
public function saveDefinition(string $name, array $definition): void
{
    if (!$this->isValidDefinitionName($name)) {
        throw new \InvalidArgumentException(sprintf('Invalid loop definition name: "%s"', $name));
    }

    // The filename is authoritative for the definition's name.
    $definition['name'] = $name;

    // Structural validation — throws InvalidArgumentException on a bad shape.
    $parsed = LoopDefinition::fromArray($definition);
    if ($parsed->roles === []) {
        throw new \InvalidArgumentException('A loop definition must declare at least one role');
    }

    $json = json_encode($definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new \InvalidArgumentException('Loop definition is not JSON-serializable');
    }

    $this->ensureLoopsDir();
    $path = $this->loopsDir . '/' . $name . '.json';
    if (file_put_contents($path, $json . "\n") === false) {
        throw new \RuntimeException(sprintf('Failed to write loop definition "%s"', $name));
    }

    $this->invalidateCache();
}

/**
 * Delete workspace/loops/{name}.json. Returns whether a file was removed.
 *
 * @throws \InvalidArgumentException on an invalid name
 */
public function deleteDefinition(string $name): bool
{
    if (!$this->isValidDefinitionName($name)) {
        throw new \InvalidArgumentException(sprintf('Invalid loop definition name: "%s"', $name));
    }

    $path = $this->loopsDir . '/' . $name . '.json';
    if (!is_file($path)) {
        return false;
    }

    $deleted = unlink($path);
    $this->invalidateCache();

    return $deleted;
}
```

- [ ] **Step 4: Run to verify pass + PHPStan**

Run: `./vendor/bin/pest tests/Unit/Config/LoopDiscoveryTest.php && ./vendor/bin/phpstan analyse src/Config/LoopDiscovery.php`
Expected: PASS; `[OK] No errors`.

- [ ] **Step 5: Commit**

```bash
git add src/Config/LoopDiscovery.php tests/Unit/Config/LoopDiscoveryTest.php
git commit -m "$(cat <<'EOF'
feat(loops): add LoopDiscovery save/delete definition write methods

Validate via LoopDefinition::fromArray + a strict slug/path-traversal name
guard, persist to workspace/loops/{name}.json, and invalidate the cache.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Definition CRUD endpoints (`LoopHandler`)

**Files:**
- Modify: `src/Api/Handler/LoopHandler.php` (add `getDefinition`/`createDefinition`/`updateDefinition`/`deleteDefinition`; register 4 routes; add `builtin` to `definitions()`; docblock)
- Test: `tests/Unit/Api/Handler/LoopHandlerTest.php`

**Interfaces:**
- Consumes: `$this->discovery` (`LoopDiscovery` with the Task 1 methods), `Router::jsonResponse/errorResponse`, `ApiErrorCode`, the `createLoopHandlerFixture()` helpers.
- Produces routes: `GET /loops/definitions/{name}`, `POST /loops/definitions`, `PUT /loops/definitions/{name}`, `DELETE /loops/definitions/{name}`; `definitions()` entries gain `builtin: bool`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Api/Handler/LoopHandlerTest.php`:

```php
test('POST creates a definition and 409s on duplicate', function (): void {
    $fixture = createLoopHandlerFixture();
    try {
        $body = json_encode([
            'name' => 'api-made',
            'description' => 'via api',
            'roles' => [['role' => 'plan', 'prompt' => 'go']],
            'termination_condition' => ['type' => 'iteration_bound', 'value' => ['max_iterations' => 2]],
        ]) ?: '';

        $created = $fixture['handler']->createDefinition(
            new ServerRequest('POST', '/api/v1/loops/definitions', ['Content-Type' => 'application/json'], $body)
        );
        expect($created->getStatusCode())->toBe(201);
        expect(json_decode((string) $created->getBody(), true)['name'])->toBe('api-made');

        $dup = $fixture['handler']->createDefinition(
            new ServerRequest('POST', '/api/v1/loops/definitions', ['Content-Type' => 'application/json'], $body)
        );
        expect($dup->getStatusCode())->toBe(409);
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('POST 400s on an invalid name and invalid structure', function (): void {
    $fixture = createLoopHandlerFixture();
    try {
        $badName = $fixture['handler']->createDefinition(new ServerRequest(
            'POST', '/api/v1/loops/definitions', [], json_encode(['name' => '../evil', 'roles' => []]) ?: ''
        ));
        expect($badName->getStatusCode())->toBe(400);

        $badShape = $fixture['handler']->createDefinition(new ServerRequest(
            'POST', '/api/v1/loops/definitions', [], json_encode(['name' => 'ok', 'roles' => []]) ?: ''
        ));
        expect($badShape->getStatusCode())->toBe(400); // empty roles / missing termination
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('PUT upserts and GET/{name} returns raw; DELETE removes', function (): void {
    $fixture = createLoopHandlerFixture();
    try {
        $body = json_encode([
            'description' => 'upserted',
            'roles' => [['role' => 'plan', 'prompt' => 'go']],
            'termination_condition' => ['type' => 'iteration_bound', 'value' => ['max_iterations' => 2]],
        ]) ?: '';

        $put = $fixture['handler']->updateDefinition(
            new ServerRequest('PUT', '/api/v1/loops/definitions/upsertme', ['Content-Type' => 'application/json'], $body),
            'upsertme'
        );
        expect($put->getStatusCode())->toBe(200);

        $got = $fixture['handler']->getDefinition(new ServerRequest('GET', '/api/v1/loops/definitions/upsertme'), 'upsertme');
        expect($got->getStatusCode())->toBe(200);
        expect(json_decode((string) $got->getBody(), true)['name'])->toBe('upsertme');

        $del = $fixture['handler']->deleteDefinition(new ServerRequest('DELETE', '/api/v1/loops/definitions/upsertme'), 'upsertme');
        expect($del->getStatusCode())->toBe(200);

        $missing = $fixture['handler']->getDefinition(new ServerRequest('GET', '/api/v1/loops/definitions/upsertme'), 'upsertme');
        expect($missing->getStatusCode())->toBe(404);
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('definitions list marks builtin', function (): void {
    $fixture = createLoopHandlerFixture();
    try {
        $body = json_decode((string) $fixture['handler']->definitions(
            new ServerRequest('GET', '/api/v1/loops/definitions')
        )->getBody(), true);
        $byName = [];
        foreach ($body['definitions'] as $d) { $byName[$d['name']] = $d; }
        // The fixture seeds a 'harness' definition into the workspace; it is a built-in.
        expect($byName['harness']['builtin'])->toBeTrue();
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});
```

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/pest tests/Unit/Api/Handler/LoopHandlerTest.php`
Expected: FAIL — undefined methods `createDefinition` etc.

- [ ] **Step 3: Implement the handler methods + routes**

Add the four methods to `src/Api/Handler/LoopHandler.php`:

```php
/**
 * GET /api/v1/loops/definitions/{name}
 */
public function getDefinition(ServerRequestInterface $request, string $name): Response
{
    if (!$this->discovery->isValidDefinitionName($name)) {
        return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid loop definition name');
    }
    if (!$this->discovery->exists($name)) {
        return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Loop definition not found');
    }

    return Router::jsonResponse($this->discovery->getRawDefinition($name));
}

/**
 * POST /api/v1/loops/definitions
 */
public function createDefinition(ServerRequestInterface $request): Response
{
    $body = json_decode((string) $request->getBody(), true);
    if (!is_array($body)) {
        return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
    }

    $name = isset($body['name']) ? trim((string) $body['name']) : '';
    if (!$this->discovery->isValidDefinitionName($name)) {
        return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid or missing loop definition name');
    }
    if ($this->discovery->exists($name)) {
        return Router::errorResponse(ApiErrorCode::CONFLICT, sprintf('Loop definition "%s" already exists', $name));
    }

    try {
        $this->discovery->saveDefinition($name, $body);
    } catch (\InvalidArgumentException $e) {
        return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $e->getMessage());
    }

    return Router::jsonResponse($this->discovery->getRawDefinition($name), 201);
}

/**
 * PUT /api/v1/loops/definitions/{name}
 */
public function updateDefinition(ServerRequestInterface $request, string $name): Response
{
    if (!$this->discovery->isValidDefinitionName($name)) {
        return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid loop definition name');
    }

    $body = json_decode((string) $request->getBody(), true);
    if (!is_array($body)) {
        return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
    }

    try {
        $this->discovery->saveDefinition($name, $body);
    } catch (\InvalidArgumentException $e) {
        return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $e->getMessage());
    }

    return Router::jsonResponse($this->discovery->getRawDefinition($name));
}

/**
 * DELETE /api/v1/loops/definitions/{name}
 */
public function deleteDefinition(ServerRequestInterface $request, string $name): Response
{
    if (!$this->discovery->isValidDefinitionName($name)) {
        return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid loop definition name');
    }
    if (!$this->discovery->deleteDefinition($name)) {
        return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Loop definition not found');
    }

    return Router::jsonResponse(['deleted' => true, 'name' => $name]);
}
```

In `definitions()`, add `builtin` to each result entry:

```php
$result[] = [
    'name' => $def->name,
    'builtin' => $this->discovery->isBuiltin($def->name),
    'description' => $def->description,
    // ... existing parameters/roles/termination fields unchanged ...
];
```

Register the routes in `register()`, immediately **after** the existing `$router->get($v1 . '/loops/definitions', [$this, 'definitions']);` line (so the literal `definitions` segment matches before `/loops/{id}`):

```php
$router->get($v1 . '/loops/definitions/{name}', [$this, 'getDefinition']);
$router->post($v1 . '/loops/definitions', [$this, 'createDefinition']);
$router->put($v1 . '/loops/definitions/{name}', [$this, 'updateDefinition']);
$router->delete($v1 . '/loops/definitions/{name}', [$this, 'deleteDefinition']);
```

Add to the class docblock route list:

```php
 * GET    /api/v1/loops/definitions/{name} — get one raw definition
 * POST   /api/v1/loops/definitions        — create a definition
 * PUT    /api/v1/loops/definitions/{name} — upsert a definition
 * DELETE /api/v1/loops/definitions/{name} — delete a definition
```

- [ ] **Step 4: Run to verify pass + PHPStan**

Run: `./vendor/bin/pest tests/Unit/Api/Handler/LoopHandlerTest.php && composer analyse`
Expected: PASS; `[OK] No errors`.

- [ ] **Step 5: Commit**

```bash
git add src/Api/Handler/LoopHandler.php tests/Unit/Api/Handler/LoopHandlerTest.php
git commit -m "$(cat <<'EOF'
feat(loops): definition CRUD endpoints

GET/POST/PUT/DELETE /loops/definitions[/{name}] with validation, 409 on
create-exists, builtin flag in the list.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Docs + source map

**Files:**
- Modify: `docs/API.md`, `docs/LOOPS.md`, `config/source.json`

- [ ] **Step 1: Document the CRUD surface**

In `docs/API.md`: the five endpoints, request/response shapes, status codes (`201`/`200`/`400`/`404`/`409`), the strict name rule (`^[a-z0-9][a-z0-9_-]*$`), and the built-in behavior (editable; a deleted built-in re-seeds on next boot). In `docs/LOOPS.md`: note that definitions can be authored via the API.

- [ ] **Step 2: Update `config/source.json`**

Update the `LoopDiscovery` and `LoopHandler` entries' descriptions to mention definition CRUD. No new files.

- [ ] **Step 3: Verify + commit**

```bash
composer test && composer analyse
git add docs/API.md docs/LOOPS.md config/source.json
git commit -m "$(cat <<'EOF'
docs(loops): document definition CRUD API

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

## Self-Review

- **Spec coverage:** `saveDefinition`/`deleteDefinition`/`isBuiltin`/`isValidDefinitionName` ✓ (Task 1); slug/path-traversal guard ✓ (Task 1 + tests); `GET`/`POST`/`PUT`/`DELETE` + `builtin` in list ✓ (Task 2); full CRUD on builtins (no name-based restriction) ✓; PUT=upsert, POST create-only (409) ✓; error model reconciled to 400/409/404 (no 422) ✓; docs ✓ (Task 3). Running-loop safety needs no code (snapshot at start) — no task required.
- **Placeholder scan:** none — complete code and commands throughout. Fixture keys verified from `LoopHandlerTest`; `LoopDiscovery` builtin dir defaults to the repo `config/loops` so `isBuiltin('harness')` is true in tests.
- **Type consistency:** `saveDefinition(string, array): void`, `deleteDefinition(string): bool`, `isBuiltin(string): bool`, `isValidDefinitionName(string): bool` used identically across Tasks 1–2.
- **Routing:** definition routes registered before `/loops/{id}` so the literal `definitions` segment wins; `/loops/definitions/{name}` (3 segments) does not collide with `/loops/{id}` (2 segments).
