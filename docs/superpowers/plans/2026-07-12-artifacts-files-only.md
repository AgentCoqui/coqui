# Artifacts Files-Only Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Collapse Coqui artifacts to one storage model — plain files on disk with a DB index — and delete the hybrid/stage/bulk machinery, replacing the volatile artifact list with a pinned recent-artifacts index.

**Architecture:** `ArtifactFileService` becomes a small always-on file backend (`pathFor`/`write`/`read`/`delete`). `ArtifactStore` is always constructed with it; every row's content lives in a file under `artifacts/<type>/<slug>-<shortid>.<ext>` and the DB row is a pure index (`id, session_id, project_id, created_by, title, type, path, content_hash, version, timestamps`). The draft→review→final stage machine, bulk ops, drift API, and the `filesystemBacked` opt-in are deleted; retention becomes ownership-based (session-only artifacts cleaned on session delete, project-linked persist). The volatile artifact guidance/list is replaced by a pinned (Workflow-priority) recent-artifacts index modeled on core-memory injection.

**Tech Stack:** PHP 8.4 (strict types, `final`, constructor injection), SQLite (PDO), Pest tests, PHPStan.

## Global Constraints

- `declare(strict_types=1);` in every PHP file; `final` classes by default; one class per file; 4-space indent; constructor injection.
- No new dependencies (Composer-only; PHP built-ins/SPL preferred).
- Source of truth is code; update `config/source.json` when class responsibilities change.
- Spec is authoritative: `docs/superpowers/specs/2026-07-11-artifacts-files-only-design.md`. If plan and spec disagree, spec wins.
- `created_by` is **display-only provenance**, never a scope/filter. Artifacts are shared, not profile-scoped.
- Path convention: `artifacts/<type>/<slug>-<shortid>.<ext>`; stable for the artifact's life.
- Type enum for `artifact_create`: `{plan, document, code, config}` (default `document`). `artifact_list` filter enum additionally keeps `loop_output`.
- Tests: `composer test`; static: `composer analyse` (phpstan, `--memory-limit=512M`). Docs index: `composer regen-docs` (generated, not committed).

---

## File Structure

**Modify (source):**
- `src/Storage/ArtifactFileService.php` — rewrite to small always-on file backend.
- `src/Storage/ArtifactStore.php` — files-only index; drop hybrid/stage/bulk/dead methods; add `created_by`; add `cleanupSessionArtifacts`; legacy migration.
- `src/Toolkit/ArtifactToolkit.php` — trim tools/params/enums; drop bulk+stage; new pinned index builder + guidelines; `createdBy` param.
- `src/Agent/OrchestratorAgent.php` — wire file service into store; pin the recent-artifacts section; thread `createdBy`.
- `src/Api/LoopManager.php` — `createStageArtifact` passes `createdBy`, drops `stage`.
- `src/Command/ApiCommand.php:228` — construct store with file service.
- `src/Tool/SpawnAgentTool.php:363` — construct store with file service + `createdBy`.
- `src/Command/DoctorCommand.php:461` — construct store with file service.
- `src/Config/BootManager.php` — unconditional file service; replace `cleanupFinalized()` call.
- `src/Config/OpenClawConfig.php` — delete `isArtifactFilesystemBacked()`.
- `src/Contract/CoquiDefaults.php` — delete `ARTIFACT_FILESYSTEM_BACKED`.
- `src/Repl/ExecutionPolicyFactory.php:25` — remove `artifact_bulk_delete` gated entry.
- `src/Api/Handler/ArtifactHandler.php` — remove `stage` from PATCH body + `?stage=` filter; drop `persistent` mutation; fix header comment.
- `src/Storage/SessionStorage.php` — `deleteSession` guard uses `project_id`; call `cleanupSessionArtifacts`.

**Modify (docs/config):**
- `prompts/tools/artifacts.md` — when-to-use rewrite; no version-history promise.
- `docs/ARTIFACTS.md` — files-only rewrite.
- `config/source.json` — refresh Artifact* responsibilities.

**Tests:**
- `tests/Unit/Storage/ArtifactFileServiceTest.php` — rewrite for file-only backend.
- `tests/Unit/Storage/ArtifactStoreHybridTest.php` — repurpose/replace as files-only store tests (or delete + fold into ArtifactStoreTest).
- `tests/Unit/Storage/ArtifactStoreTest.php` — files-only CRUD, cleanup, migration, created_by.
- `tests/Unit/Toolkit/ArtifactToolkitTest.php` — trimmed surface + pinned index.
- `tests/Unit/Api/Handler/ArtifactHandlerTest.php` — stage removal.

---

## Task 1: `ArtifactFileService` → small always-on file backend

**Files:**
- Modify: `src/Storage/ArtifactFileService.php`
- Test: `tests/Unit/Storage/ArtifactFileServiceTest.php`

**Interfaces:**
- Produces:
  - `__construct(string $workspacePath)`
  - `pathFor(string $type, string $title, string $id, ?string $language = null): string` — returns workspace-relative `artifacts/<type>/<slug>-<shortid>.<ext>`.
  - `write(string $relativePath, string $content): string` — writes file (mkdir -p), returns sha-256 hash of content.
  - `read(string $relativePath): ?string` — file content or null.
  - `delete(string $relativePath): bool` — unlink (true if gone/absent).
  - `hash(string $content): string` — sha-256 of a string.
- Consumes: `CoquiDefaults::DIRECTORY_MODE`, `PathHelper`.

**Notes on `pathFor`:** ext derives from type — `plan|document|loop_output → .md`; `code → language ext via a small map else .txt`; `config → .json/.yaml/.txt` (default `.txt` unless language hints json/yaml). `<shortid>` = first 8 chars of `$id`. Reuse the existing `slugify` (cap 60).

- [ ] **Step 1: Write the failing test**

Replace `tests/Unit/Storage/ArtifactFileServiceTest.php` contents:

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Storage\ArtifactFileService;

beforeEach(function (): void {
    $this->workspace = sys_get_temp_dir() . '/afs-' . bin2hex(random_bytes(6));
    mkdir($this->workspace, 0775, true);
    $this->svc = new ArtifactFileService($this->workspace);
});

afterEach(function (): void {
    exec('rm -rf ' . escapeshellarg($this->workspace));
});

it('generates a predictable path under artifacts/<type>/', function (): void {
    $path = $this->svc->pathFor('document', 'My Design Doc', 'abcd1234 effff', null);
    expect($path)->toStartWith('artifacts/document/')
        ->and($path)->toEndWith('.md')
        ->and($path)->toContain('my-design-doc-abcd1234');
});

it('derives code extension from language', function (): void {
    $path = $this->svc->pathFor('code', 'Widget', 'deadbeef0000', 'php');
    expect($path)->toStartWith('artifacts/code/')->and($path)->toEndWith('.php');
});

it('writes content and returns its hash, read returns same content', function (): void {
    $path = $this->svc->pathFor('plan', 'Plan A', 'aaaa1111bbbb', null);
    $hash = $this->svc->write($path, "hello world\n");
    expect(file_exists($this->workspace . '/' . $path))->toBeTrue()
        ->and($hash)->toBe(hash('sha256', "hello world\n"))
        ->and($this->svc->read($path))->toBe("hello world\n");
});

it('read returns null for a missing file', function (): void {
    expect($this->svc->read('artifacts/document/nope-00000000.md'))->toBeNull();
});

it('delete removes the file and is idempotent', function (): void {
    $path = $this->svc->pathFor('document', 'Del', 'ccccdddd0000', null);
    $this->svc->write($path, 'x');
    expect($this->svc->delete($path))->toBeTrue()
        ->and(file_exists($this->workspace . '/' . $path))->toBeFalse()
        ->and($this->svc->delete($path))->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Storage/ArtifactFileServiceTest.php`
Expected: FAIL (methods `pathFor`/`write`/`read`/`delete`/`hash` do not exist).

- [ ] **Step 3: Rewrite `ArtifactFileService`**

Replace the whole class body (keep namespace/imports for `PathHelper`, `CoquiDefaults`):

```php
/**
 * Always-on filesystem backend for artifacts.
 *
 * Every artifact's content is a plain file under artifacts/<type>/. The DB
 * row is a pure index; this service owns path generation and file I/O.
 */
final class ArtifactFileService
{
    /** language → file extension for code artifacts. */
    private const array CODE_EXT = [
        'php' => 'php', 'python' => 'py', 'py' => 'py', 'javascript' => 'js',
        'js' => 'js', 'typescript' => 'ts', 'ts' => 'ts', 'bash' => 'sh',
        'sh' => 'sh', 'go' => 'go', 'rust' => 'rs', 'ruby' => 'rb',
        'java' => 'java', 'json' => 'json', 'yaml' => 'yaml', 'yml' => 'yaml',
        'sql' => 'sql', 'html' => 'html', 'css' => 'css',
    ];

    private readonly string $workspacePath;

    public function __construct(string $workspacePath)
    {
        $this->workspacePath = PathHelper::trimTrailingSlash($workspacePath);
    }

    /**
     * Workspace-relative canonical path: artifacts/<type>/<slug>-<shortid>.<ext>
     */
    public function pathFor(string $type, string $title, string $id, ?string $language = null): string
    {
        $slug = $this->slugify($title);
        $shortId = substr($id, 0, 8);
        $ext = $this->extensionFor($type, $language);

        return "artifacts/{$type}/{$slug}-{$shortId}.{$ext}";
    }

    /** Write content to the relative path (creating dirs); return sha-256 of content. */
    public function write(string $relativePath, string $content): string
    {
        $absolute = $this->toAbsolutePath($relativePath);
        $dir = dirname($absolute);
        if (!is_dir($dir) && !mkdir($dir, CoquiDefaults::DIRECTORY_MODE, true) && !is_dir($dir)) {
            throw new \RuntimeException("Unable to create artifact directory: {$dir}");
        }
        if (file_put_contents($absolute, $content) === false) {
            throw new \RuntimeException("Unable to write artifact file: {$relativePath}");
        }

        return $this->hash($content);
    }

    public function read(string $relativePath): ?string
    {
        $absolute = $this->toAbsolutePath($relativePath);
        if (!is_file($absolute)) {
            return null;
        }
        $content = file_get_contents($absolute);

        return $content !== false ? $content : null;
    }

    public function delete(string $relativePath): bool
    {
        $absolute = $this->toAbsolutePath($relativePath);
        if (!file_exists($absolute)) {
            return true;
        }

        return unlink($absolute);
    }

    public function hash(string $content): string
    {
        return hash('sha256', $content);
    }

    private function extensionFor(string $type, ?string $language): string
    {
        if ($type === 'code') {
            $lang = $language !== null ? mb_strtolower(trim($language)) : '';

            return self::CODE_EXT[$lang] ?? 'txt';
        }
        if ($type === 'config') {
            $lang = $language !== null ? mb_strtolower(trim($language)) : '';

            return in_array($lang, ['json', 'yaml', 'yml'], true) ? ($lang === 'yml' ? 'yaml' : $lang) : 'txt';
        }

        // plan, document, loop_output, and anything else → markdown
        return 'md';
    }

    private function toAbsolutePath(string $relativePath): string
    {
        return $this->workspacePath . '/' . ltrim($relativePath, '/');
    }

    private function slugify(string $title): string
    {
        $slug = mb_strtolower($title);
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        if (mb_strlen($slug) > 60) {
            $slug = rtrim(mb_substr($slug, 0, 60), '-');
        }

        return $slug !== '' ? $slug : 'artifact';
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Storage/ArtifactFileServiceTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Storage/ArtifactFileService.php tests/Unit/Storage/ArtifactFileServiceTest.php
git commit -m "refactor(artifacts): simplify ArtifactFileService to always-on file backend"
```

---

## Task 2: `ArtifactStore` → files-only index (create/get/update/delete)

**Files:**
- Modify: `src/Storage/ArtifactStore.php`
- Test: `tests/Unit/Storage/ArtifactStoreTest.php`

**Interfaces:**
- Consumes: `ArtifactFileService` (Task 1), `ProjectStore`, `Clock`, `IdGenerator`, `SchemaHelper`.
- Produces (new signatures):
  - `__construct(PDO $db, ArtifactFileService $fileService, ?ProjectStore $projectStore = null)` — file service now **required**.
  - `create(string $sessionId, string $title, string $content, string $type = 'document', ?string $language = null, ?string $projectId = null, ?string $createdBy = null, ?string $turnId = null, ?array $metadata = null): string` — writes file, stores `path`+`content_hash`+`created_by`; returns id.
  - `update(string $id, string $content, ?string $title = null, ?string $sessionId = null): bool` — rewrites file, bumps `version`.
  - `get(string $id, ?string $sessionId = null): ?array` — row + `content` read from file.
  - `list(string $sessionId, ?string $type = null, int $limit = 50, ?string $projectId = null, ?string $createdAfter = null): array`
  - `delete(string $id, ?string $sessionId = null): bool` — deletes file + row.
  - `patch(string $id, array $patch, ?string $sessionId = null): bool` — keys: `title, metadata, project_id`.
  - `cleanupSessionArtifacts(string $sessionId): int` (Task 4).
  - `migrateLegacyContent(): int` (Task 6).
- Removed: `bulkDelete`, `bulkUpdateStage`, `updateStage`, `cleanupFinalized`, `hasPersistentArtifacts`, `getRaw` (folded), `resolveProjectDirectory` (path no longer needs project dir).

**Schema (createTables):** add `path TEXT`, `created_by TEXT`, keep `content_hash`; keep `content` (nullable/default '') for legacy + migration; stop declaring `sprint_id`; keep dormant `stage`/`storage_mode`/`canonical_path`/`persistent` columns via existing `migrateAddColumn` for backward compat (SQLite cannot cheaply drop). Do **not** read/write `stage`/`sprint_id`/`storage_mode`/`canonical_path` in new code.

- [ ] **Step 1: Write the failing test**

Replace `tests/Unit/Storage/ArtifactStoreTest.php` with files-only coverage:

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Storage\ArtifactFileService;
use CoquiBot\Coqui\Storage\ArtifactStore;

beforeEach(function (): void {
    $this->workspace = sys_get_temp_dir() . '/as-' . bin2hex(random_bytes(6));
    mkdir($this->workspace, 0775, true);
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // sessions table for FK context (artifacts references it, but sqlite in-memory
    // does not enforce FK unless enabled; create a minimal sessions table anyway).
    $pdo->exec('CREATE TABLE IF NOT EXISTS sessions (id TEXT PRIMARY KEY)');
    $pdo->exec("INSERT INTO sessions (id) VALUES ('s1')");
    $this->svc = new ArtifactFileService($this->workspace);
    $this->store = new ArtifactStore($pdo, $this->svc);
});

afterEach(function (): void {
    exec('rm -rf ' . escapeshellarg($this->workspace));
});

it('create writes a real file at a predictable path and indexes it', function (): void {
    $id = $this->store->create('s1', 'Design Doc', "# Title\nbody\n", 'document', createdBy: 'coder');
    $row = $this->store->get($id, 's1');
    expect($row)->not->toBeNull()
        ->and($row['path'])->toStartWith('artifacts/document/')
        ->and(file_exists($this->workspace . '/' . $row['path']))->toBeTrue()
        ->and($row['content'])->toBe("# Title\nbody\n")
        ->and($row['created_by'])->toBe('coder')
        ->and((int) $row['version'])->toBe(1);
});

it('get reads content from the file, not the DB', function (): void {
    $id = $this->store->create('s1', 'Doc', 'original', 'document');
    $path = $this->store->get($id, 's1')['path'];
    file_put_contents($this->workspace . '/' . $path, 'edited on disk');
    expect($this->store->get($id, 's1')['content'])->toBe('edited on disk');
});

it('update rewrites the same path and bumps the version', function (): void {
    $id = $this->store->create('s1', 'Doc', 'v1', 'document');
    $path1 = $this->store->get($id, 's1')['path'];
    $this->store->update($id, 'v2', sessionId: 's1');
    $after = $this->store->get($id, 's1');
    expect($after['path'])->toBe($path1)
        ->and($after['content'])->toBe('v2')
        ->and((int) $after['version'])->toBe(2)
        ->and(file_get_contents($this->workspace . '/' . $path1))->toBe('v2');
});

it('delete removes the file and the row', function (): void {
    $id = $this->store->create('s1', 'Doc', 'x', 'document');
    $path = $this->store->get($id, 's1')['path'];
    expect($this->store->delete($id, 's1'))->toBeTrue()
        ->and($this->store->get($id, 's1'))->toBeNull()
        ->and(file_exists($this->workspace . '/' . $path))->toBeFalse();
});

it('list returns index rows filtered by type and project', function (): void {
    $this->store->create('s1', 'A', 'a', 'document');
    $this->store->create('s1', 'B', 'b', 'plan', projectId: 'p1');
    expect($this->store->list('s1'))->toHaveCount(2)
        ->and($this->store->list('s1', type: 'plan'))->toHaveCount(1)
        ->and($this->store->list('s1', projectId: 'p1'))->toHaveCount(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Storage/ArtifactStoreTest.php`
Expected: FAIL (constructor now requires file service / new columns absent / signature mismatch).

- [ ] **Step 3: Rewrite `ArtifactStore`**

Rewrite the class. Key points:
- Constructor: `public function __construct(private PDO $db, private ArtifactFileService $fileService, private ?ProjectStore $projectStore = null) { $this->createTables(); }`
- `createTables()`: keep the `CREATE TABLE IF NOT EXISTS artifacts (...)` (existing columns are fine for back-compat), keep the `DROP TABLE artifact_versions` legacy line, keep `idx_artifacts_session`. Migrations: `project_id`, `persistent` (keep dormant), `storage_mode`/`canonical_path`/`content_hash` (keep), **add** `path TEXT`, **add** `created_by TEXT`. **Remove** the `sprint_id` `migrateAddColumn` line and its comment.
- `create()`:

```php
public function create(
    string $sessionId,
    string $title,
    string $content,
    string $type = 'document',
    ?string $language = null,
    ?string $projectId = null,
    ?string $createdBy = null,
    ?string $turnId = null,
    ?array $metadata = null,
): string {
    $id = IdGenerator::hex();
    $now = Clock::nowUtc();
    $path = $this->fileService->pathFor($type, $title, $id, $language);
    $hash = $this->fileService->write($path, $content);

    $stmt = $this->db->prepare(<<<'SQL'
        INSERT INTO artifacts
            (id, session_id, turn_id, title, type, content, language, filepath, path, content_hash, created_by, version, metadata, project_id, persistent, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, '', ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?)
    SQL);
    $stmt->execute([
        $id, $sessionId, $turnId, $title, $type,
        $language, $path, $path, $hash, $createdBy,
        $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
        $projectId,
        ($projectId !== null && $projectId !== '') ? 1 : 0,
        $now, $now,
    ]);

    return $id;
}
```

  (Keep writing `filepath = path` and `persistent` for back-compat with dormant columns; content column stored empty.)
- `update()`:

```php
public function update(string $id, string $content, ?string $title = null, ?string $sessionId = null): bool
{
    $row = $this->fetchRow($id, $sessionId);
    if ($row === null) {
        return false;
    }
    $path = (string) ($row['path'] ?? '');
    if ($path === '') {
        // Legacy safety: synthesize a path if a pre-migration row slipped through.
        $path = $this->fileService->pathFor((string) $row['type'], (string) $row['title'], $id, $row['language'] ?? null);
    }
    $hash = $this->fileService->write($path, $content);
    $sets = ['content' => "''"]; // stays empty; file is source of truth
    $params = [];
    $assign = ['content = ?', 'path = ?', 'content_hash = ?', 'version = ?', 'updated_at = ?'];
    $params = ['', $path, $hash, ((int) $row['version']) + 1, Clock::nowUtc()];
    if ($title !== null) {
        $assign[] = 'title = ?';
        $params[] = $title;
    }
    $params[] = $id;
    $this->db->prepare('UPDATE artifacts SET ' . implode(', ', $assign) . ' WHERE id = ?')->execute($params);

    return true;
}
```

- `get()`: fetch row (optionally scoped by session), then `$row['content'] = $this->fileService->read((string)($row['path'] ?? '')) ?? ($row['content'] ?? '');` and return.
- `fetchRow(string $id, ?string $sessionId)`: private helper (raw row, no content overlay) — replaces old `getRaw`.
- `list()`: drop the `stage` param entirely; keep `type`, `limit`, `projectId`, `createdAfter`; order by `updated_at DESC`.
- `delete()`: read path via `fetchRow`, `fileService->delete($path)`, then `DELETE FROM artifacts`.
- `patch()`: keep only `title`, `metadata`, `project_id` keys (drop `language`, `persistent`, `stage`). When `project_id` set, also set `persistent = 1` for dormant back-compat.
- **Delete methods:** `bulkDelete`, `bulkUpdateStage`, `updateStage`, `cleanupFinalized`, `hasPersistentArtifacts`, `resolveProjectDirectory`.
- Update the class docblock to describe files-only index (no stage lifecycle language).

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Storage/ArtifactStoreTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Storage/ArtifactStore.php tests/Unit/Storage/ArtifactStoreTest.php
git commit -m "refactor(artifacts): files-only ArtifactStore index with created_by provenance"
```

---

## Task 3: Wire the file service into every store construction site

**Files:**
- Modify: `src/Config/BootManager.php` (~565-569)
- Modify: `src/Command/ApiCommand.php:228`
- Modify: `src/Tool/SpawnAgentTool.php:363`
- Modify: `src/Command/DoctorCommand.php:461`
- Modify: `src/Agent/OrchestratorAgent.php:453`
- Modify: `src/Config/OpenClawConfig.php` (delete `isArtifactFilesystemBacked`)
- Modify: `src/Contract/CoquiDefaults.php` (delete `ARTIFACT_FILESYSTEM_BACKED`)
- Test: `tests/Unit/Storage/ArtifactStoreHybridTest.php` (repurpose — see Step 1)

**Interfaces:**
- Consumes: `ArtifactStore.__construct(PDO, ArtifactFileService, ?ProjectStore)` (Task 2).

Since the constructor's second arg is now **required**, every `new ArtifactStore($pdo)` fails to compile-run until fixed. This task makes them all pass a file service.

- [ ] **Step 1: Repurpose the hybrid test as a wiring smoke test**

The hybrid engine is gone. Replace `tests/Unit/Storage/ArtifactStoreHybridTest.php` with a small test asserting the store is always file-capable (no opt-in), or delete it and assert here that a bare-workspace store writes files. Minimal replacement:

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Storage\ArtifactFileService;
use CoquiBot\Coqui\Storage\ArtifactStore;

it('every store is file-capable — create always writes a file', function (): void {
    $ws = sys_get_temp_dir() . '/ash-' . bin2hex(random_bytes(6));
    mkdir($ws, 0775, true);
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $store = new ArtifactStore($pdo, new ArtifactFileService($ws));
    $id = $store->create('s1', 'X', 'body', 'code', language: 'php');
    $path = $store->get($id)['path'];
    expect($path)->toEndWith('.php')->and(file_exists($ws . '/' . $path))->toBeTrue();
    exec('rm -rf ' . escapeshellarg($ws));
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Storage/ArtifactStoreHybridTest.php`
Expected: PASS already if Task 2 done — but the *other* construction sites won't compile in integration tests. Instead verify failure via analyse: `composer analyse` reports arg-count errors at the four bare sites. Expected: FAIL (too few arguments to ArtifactStore::__construct).

- [ ] **Step 3: Fix `BootManager` (unconditional file service, no gate)**

`src/Config/BootManager.php` ~565-569:

```php
$fileService = new ArtifactFileService($this->workspacePath);
$this->artifactStore = new ArtifactStore($pdo, $fileService, $this->projectStore);
```

Remove the `if ($this->config->isArtifactFilesystemBacked())` block. (The `cleanupFinalized()` call at ~578 is handled in Task 4 — for now, change it to `// artifact cleanup is now ownership-based on session delete` and remove the call, or leave until Task 4. To keep this task green, delete the `$this->artifactStore->cleanupFinalized();` line now.)

- [ ] **Step 4: Fix `ApiCommand.php:228`**

```php
$artifactStore = new ArtifactStore(
    $storage->getPdo(),
    new ArtifactFileService($boot->workspacePath()),
    $boot->projectStore(),
);
```

Add `use CoquiBot\Coqui\Storage\ArtifactFileService;` if not present. (`$boot->workspacePath()` and `$boot->projectStore()` are already used nearby.)

- [ ] **Step 5: Fix `SpawnAgentTool.php:363`**

```php
$artifactStore = new ArtifactStore(
    $this->storage->getPdo(),
    new ArtifactFileService($this->workspacePath),
    $this->projectStore,
);
```

Add the `ArtifactFileService` import. (Provenance `createdBy` for this toolkit is added in Task 5.)

- [ ] **Step 6: Fix `DoctorCommand.php:461`**

The store check factory only needs a PDO; give it a throwaway file service rooted at the workspace (or system temp if no workspace on hand):

```php
'artifact_store' => fn(PDO $db): mixed => new ArtifactStore($db, new ArtifactFileService($this->resolveWorkspacePath())),
```

Use whatever workspace accessor `DoctorCommand` already has; if none, use `sys_get_temp_dir()` — the check only constructs the store (creates tables), it never writes an artifact. Add the import.

- [ ] **Step 7: Fix `OrchestratorAgent.php:453`**

```php
$artifactStore = new \CoquiBot\Coqui\Storage\ArtifactStore(
    $this->storage->getPdo(),
    new \CoquiBot\Coqui\Storage\ArtifactFileService($this->workspacePath),
    $this->projectStore,
);
```

(Store `$this->artifactStore = $artifactStore;` and `$this->artifactToolkitSessionId = $toolkitSessionId;` as new private fields here — needed by Task 5's pinned section.)

- [ ] **Step 8: Delete the opt-in flag**

- `src/Config/OpenClawConfig.php`: delete the `isArtifactFilesystemBacked()` method and its docblock.
- `src/Contract/CoquiDefaults.php`: delete the `ARTIFACT_FILESYSTEM_BACKED` constant and its comment.
- Grep to confirm no remaining references: `grep -rn "isArtifactFilesystemBacked\|ARTIFACT_FILESYSTEM_BACKED\|filesystemBacked" src/ config/`. Expected: no hits.

- [ ] **Step 9: Run analyse + full test to verify**

Run: `composer analyse`
Expected: no arg-count errors for `ArtifactStore`.
Run: `./vendor/bin/pest tests/Unit/Storage/`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add src/Config/BootManager.php src/Command/ApiCommand.php src/Tool/SpawnAgentTool.php src/Command/DoctorCommand.php src/Agent/OrchestratorAgent.php src/Config/OpenClawConfig.php src/Contract/CoquiDefaults.php tests/Unit/Storage/ArtifactStoreHybridTest.php
git commit -m "feat(artifacts): wire always-on file service into every store site; drop filesystemBacked opt-in"
```

---

## Task 4: Ownership-based cleanup (replace `cleanupFinalized`)

**Files:**
- Modify: `src/Storage/ArtifactStore.php` (add `cleanupSessionArtifacts`)
- Modify: `src/Storage/SessionStorage.php` (`deleteSession` uses `project_id`; call cleanup)
- Modify: `src/Config/BootManager.php` (remove `cleanupFinalized` call — done in Task 3 Step 3; confirm)
- Test: `tests/Unit/Storage/ArtifactStoreTest.php` (append)

**Interfaces:**
- Produces: `ArtifactStore::cleanupSessionArtifacts(string $sessionId): int` — deletes files + rows for `session_id = ? AND (project_id IS NULL OR project_id = '')`; returns count.

- [ ] **Step 1: Write the failing test (append to ArtifactStoreTest.php)**

```php
it('cleanupSessionArtifacts removes session-only artifacts and keeps project-linked ones', function (): void {
    $sessionOnly = $this->store->create('s1', 'Ephemeral', 'x', 'document');
    $projectLinked = $this->store->create('s1', 'Keeper', 'y', 'plan', projectId: 'p1');
    $sessionPath = $this->store->get($sessionOnly, 's1')['path'];
    $projectPath = $this->store->get($projectLinked, 's1')['path'];

    $removed = $this->store->cleanupSessionArtifacts('s1');

    expect($removed)->toBe(1)
        ->and($this->store->get($sessionOnly, 's1'))->toBeNull()
        ->and(file_exists($this->workspace . '/' . $sessionPath))->toBeFalse()
        ->and($this->store->get($projectLinked, 's1'))->not->toBeNull()
        ->and(file_exists($this->workspace . '/' . $projectPath))->toBeTrue();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Storage/ArtifactStoreTest.php --filter cleanupSessionArtifacts`
Expected: FAIL (method missing).

- [ ] **Step 3: Implement `cleanupSessionArtifacts`**

```php
/**
 * Remove session-only artifacts (no project link) and their files.
 * Project-linked artifacts persist. Called on session deletion.
 */
public function cleanupSessionArtifacts(string $sessionId): int
{
    $stmt = $this->db->prepare(
        "SELECT id, path FROM artifacts WHERE session_id = ? AND (project_id IS NULL OR project_id = '')",
    );
    $stmt->execute([$sessionId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $path = (string) ($row['path'] ?? '');
        if ($path !== '') {
            $this->fileService->delete($path);
        }
    }

    $del = $this->db->prepare(
        "DELETE FROM artifacts WHERE session_id = ? AND (project_id IS NULL OR project_id = '')",
    );
    $del->execute([$sessionId]);

    return $del->rowCount();
}
```

- [ ] **Step 4: Wire into `SessionStorage::deleteSession`**

In `src/Storage/SessionStorage.php:1818`, change the guard from `persistent = 1` to `project_id IS NOT NULL AND project_id != ''` (project-linked artifacts block non-forced deletion). This is metadata-only; `SessionStorage` has no `ArtifactStore`, so file cleanup for session-only artifacts must happen via the caller. **Decision:** add file cleanup at the call site. The DB rows for session-only artifacts already cascade-delete via FK; only their *files* need removal. Since `SessionStorage` cannot see the workspace, expose cleanup on `ArtifactStore` and call it from the API/REPL session-delete path **before** `deleteSession`.

Update `src/Api/Handler/SessionHandler.php:295` region: before `$this->storage->deleteSession($id);`, if an `ArtifactStore` is available to the handler, call `$this->artifactStore->cleanupSessionArtifacts($id);`. If `SessionHandler` does not already hold an `ArtifactStore`, inject one (constructor param, wired in `ApiCommand`). Keep the `deleteSession` guard update regardless so project-linked rows are protected.

*(If injecting into `SessionHandler` widens scope too far, the acceptable fallback per spec is: `cleanupSessionArtifacts` is the tested unit deliverable, and `deleteSession`'s FK cascade removes session-only rows while the guard protects project-linked rows; file cleanup is best-effort at the delete call site. Prefer wiring it in; note the deviation in the report if you fall back.)*

- [ ] **Step 5: Run tests**

Run: `./vendor/bin/pest tests/Unit/Storage/ArtifactStoreTest.php`
Expected: PASS.
Run: `./vendor/bin/pest tests/Unit/Storage/SessionStorageTest.php` (if present)
Expected: PASS (adjust any test asserting the old `persistent = 1` guard message).

- [ ] **Step 6: Commit**

```bash
git add src/Storage/ArtifactStore.php src/Storage/SessionStorage.php src/Api/Handler/SessionHandler.php src/Command/ApiCommand.php tests/Unit/Storage/ArtifactStoreTest.php
git commit -m "feat(artifacts): ownership-based session cleanup; retire cleanupFinalized footgun"
```

---

## Task 5: Toolkit surface, provenance, and pinned recent-artifacts index

**Files:**
- Modify: `src/Toolkit/ArtifactToolkit.php`
- Modify: `src/Agent/OrchestratorAgent.php` (pinned section + `createdBy` threading; exclude artifact toolkit from Volatile guidelines loop)
- Modify: `src/Tool/SpawnAgentTool.php` (pass `createdBy` = role/agent identity)
- Modify: `src/Api/LoopManager.php` (`createStageArtifact` passes `createdBy`, drops `stage`)
- Modify: `src/Repl/ExecutionPolicyFactory.php:25` (remove `artifact_bulk_delete`)
- Test: `tests/Unit/Toolkit/ArtifactToolkitTest.php`

**Interfaces:**
- `ArtifactToolkit.__construct(ArtifactStore $store, string $sessionId, bool $readOnly = false, ?string $defaultProjectId = null, ?string $createdBy = null)`.
- New public method `ArtifactToolkit::recentArtifactsIndex(): string` — returns the pinned index text (guidance prose + capped pointer list), used by the OrchestratorAgent pinned section. `guidelines()` is removed from Volatile emission for this toolkit (see OrchestratorAgent change).

### Toolkit changes

- `tools()`: `[createTool, updateTool, getTool, listTool]` + `deleteTool` when `!readOnly`. **Remove `stageTool`.**
- `artifact_create` params: `title` (required), `content` (required), `type?` enum `['plan','document','code','config']` default `document`, `language?` (kept — informs code/config extension), **drop** `filepath`, **drop** `project_id` (use `defaultProjectId`). Callback passes `createdBy: $this->createdBy` and returns `{id, title, type, version:1, path}` (fetch path via `store->get`).
- `artifact_update` params: `id`, `content`, `title?`. **Drop** `change_summary`. Returns `{id, title, version, updated:true, path}`.
- `artifact_get`: unchanged behavior (returns file content + metadata + path).
- `artifact_list` params: `type?` enum `['plan','document','code','config','loop_output']`, `project_id?`, `created_after?`. **Drop `stage`.** Summary map drops `stage`/`storage_mode`/`canonical_path`, adds `path`/`created_by`.
- `artifact_delete`: single `id` only. **Drop** `ids`/`all`/filters. Returns `{id, title, deleted:true}`.
- **Delete** `resolveArtifactTargets`.
- Replace `guidelines()` with `recentArtifactsIndex()`:

```php
public function recentArtifactsIndex(): string
{
    $projectId = $this->defaultProjectId;
    $artifacts = $this->store->list(
        $this->sessionId,
        projectId: $projectId,
        limit: 10,
    );

    $when = <<<'WHEN'
    Create an artifact when the output is (1) **substantial** — more than ~15 lines
    or a complete file/document; (2) **durable** — the user would keep, re-open, share,
    or iterate on it; (3) **self-contained** — it stands on its own without the chat.
    Do NOT create one for one-off answers, short snippets, explanations, or commentary
    about an existing artifact. If unsure, prefer a file the user can open on disk over
    an ephemeral message. Artifacts are plain files under `artifacts/<path>` — inspectable,
    greppable, and versioned by the user's own git; reference one by path instead of
    re-pasting it to save context budget. To change one, `artifact_update` its id (full
    rewrite, reuses the file); only `artifact_create` for a genuinely new deliverable.
    WHEN;

    if ($artifacts === []) {
        return "<ARTIFACTS>\n{$when}\n</ARTIFACTS>";
    }

    $lines = [];
    foreach ($artifacts as $a) {
        $by = ($a['created_by'] ?? '') !== '' ? " — by {$a['created_by']}" : '';
        $lines[] = sprintf(
            '- **%s** (%s) [%s] %s%s',
            $a['title'],
            $a['id'],
            $a['type'],
            $a['path'] ?? '',
            $by,
        );
    }
    $listing = implode("\n", $lines);

    return "<ARTIFACTS>\n{$when}\n\nRecent artifacts in scope (read/grep by path):\n{$listing}\n</ARTIFACTS>";
}
```

- Update the class docblock (no stage lifecycle / bulk language).

### OrchestratorAgent changes

- Store `$this->artifactStore` and `$this->artifactToolkitSessionId` fields (set in the block at ~452, done partly in Task 3 Step 7). Add `$this->artifactToolkit` reference too, or re-derive.
- When artifacts enabled (line ~452), pass `createdBy: $this->resolveArtifactCreatedBy()` to the `ArtifactToolkit` and **call `$this->excludeToolkitPromptSlug('artifacts');`** in the enabled branch too, so the generic Volatile `buildToolkitGuidelinePromptSections()` no longer emits the artifact guidelines.
- Add `resolveArtifactCreatedBy(): ?string` — returns `$this->activeProfile` if non-null/non-empty, else the effective role/agent identity string (e.g. `$this->activeRole`). Display-only.
- Add `buildArtifactPromptSection(): ?PromptSection` modeled on `buildMemoryPromptSections()`:

```php
private function buildArtifactPromptSection(): ?PromptSection
{
    if ($this->artifactStore === null || $this->artifactToolkitSessionId === null) {
        return null;
    }
    if (!$this->isProfileFeatureEnabled('artifacts')) {
        return null;
    }
    $toolkit = new ArtifactToolkit(
        $this->artifactStore,
        $this->artifactToolkitSessionId,
        defaultProjectId: $this->defaultProjectId,
    );
    $content = $toolkit->recentArtifactsIndex();
    if ($content === '') {
        return null;
    }

    return new PromptSection(
        id: 'context.artifacts',
        title: 'Artifacts',
        content: $content,
        priority: PromptSectionPriority::Workflow,
        rationale: 'Artifact availability and recent pointers stay pinned so durable deliverables survive budget pressure.',
        decision: 'pinned_workflow',
        group: 'artifacts',
    );
}
```

  (Reuse the already-constructed toolkit if convenient; a fresh read-only instance for index rendering is fine — it only calls `store->list`.)
- In `buildPromptSections()` (~1614), after the memory sections loop, add:

```php
if (($artifacts = $this->buildArtifactPromptSection()) !== null) {
    $sections[] = $artifacts;
}
```

### SpawnAgentTool

- Pass `createdBy: $role` (or the agent identity available at that site) into the `ArtifactToolkit` constructor.

### LoopManager::createStageArtifact

- Drop `stage: 'final'` from the `create()` call (param removed). Pass `createdBy:` a loop/stage label, e.g. `sprintf('loop:%s stage:%d', $role, $stageIndex)` or `$role`.

### ExecutionPolicyFactory

- Remove the `'artifact_bulk_delete' => ['*'],` line (keep `'artifact_delete'`).

- [ ] **Step 1: Write failing tests**

Update `tests/Unit/Toolkit/ArtifactToolkitTest.php`. Add/replace with cases:

```php
it('artifact_create writes a file and returns its path, stamping created_by', function (): void {
    // build store with temp workspace file service, toolkit with createdBy: 'alice'
    // call the create tool with title/content/type=document
    // assert result json has 'path' and store->get()['created_by'] === 'alice'
});

it('exposes only create/update/get/list/delete — no stage/bulk tools', function (): void {
    $names = array_map(fn($t) => $t->name(), $toolkit->tools());
    expect($names)->toContain('artifact_create')
        ->and($names)->not->toContain('artifact_stage')
        ->and($names)->toContain('artifact_delete');
    // and delete tool has no 'ids'/'all' params
});

it('recentArtifactsIndex is pointers+path+by-created_by, capped, project-scoped, not creator-filtered', function (): void {
    // create 12 artifacts across two creators; assert index lists <=10, includes paths and "by <creator>",
    // includes artifacts from BOTH creators (no creator filter)
});
```

Fill these in with concrete setup mirroring `ArtifactStoreTest` (temp workspace + `ArtifactFileService` + `ArtifactStore`). Use the tool objects: find each by name, invoke `->callback()` / `->run()` per the existing test's pattern (match how `ArtifactToolkitTest` currently invokes tools).

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/pest tests/Unit/Toolkit/ArtifactToolkitTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement toolkit + orchestrator + loop + policy changes** (as specified above).

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/pest tests/Unit/Toolkit/ArtifactToolkitTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Toolkit/ArtifactToolkit.php src/Agent/OrchestratorAgent.php src/Tool/SpawnAgentTool.php src/Api/LoopManager.php src/Repl/ExecutionPolicyFactory.php tests/Unit/Toolkit/ArtifactToolkitTest.php
git commit -m "feat(artifacts): trim tool surface, add created_by provenance, pin recent-artifacts index"
```

---

## Task 6: Legacy data migration on boot

**Files:**
- Modify: `src/Storage/ArtifactStore.php` (add `migrateLegacyContent`)
- Modify: `src/Config/BootManager.php` (call migration in `initializeArtifacts`, long-lived processes only)
- Test: `tests/Unit/Storage/ArtifactStoreTest.php` (append)

**Interfaces:**
- Produces: `ArtifactStore::migrateLegacyContent(): int` — for every row with non-empty `content` and empty/null `path`, write content to a file per the convention, set `path`+`content_hash`, blank `content`; returns count migrated. Rows referenced by `loop_stages.artifact_id` are migrated (never dropped) — this loop covers all rows including loop_output.

- [ ] **Step 1: Write the failing test (append)**

```php
it('migrateLegacyContent moves inline content to files and preserves loop_output rows', function (): void {
    $pdo = $this->store->pdoForTest(); // or reuse $this->store's pdo via reflection/helper
    // Insert a legacy row directly with inline content and empty path:
    $pdo->prepare("INSERT INTO artifacts (id, session_id, title, type, content, path, version, created_at, updated_at) VALUES ('legacy1','s1','Old','loop_output','LEGACY BODY','',1,'2020-01-01T00:00:00Z','2020-01-01T00:00:00Z')")->execute();

    $migrated = $this->store->migrateLegacyContent();

    $row = $this->store->get('legacy1', 's1');
    expect($migrated)->toBeGreaterThanOrEqual(1)
        ->and($row['path'])->not->toBe('')
        ->and(file_exists($this->workspace . '/' . $row['path']))->toBeTrue()
        ->and($row['content'])->toBe('LEGACY BODY');
});
```

(If a `pdo` accessor is awkward, construct the legacy row through a second `ArtifactStore` sharing the same in-memory PDO created in `beforeEach`; expose the PDO in the test's `beforeEach` as `$this->pdo`.)

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/pest tests/Unit/Storage/ArtifactStoreTest.php --filter migrateLegacyContent`
Expected: FAIL (method missing).

- [ ] **Step 3: Implement `migrateLegacyContent`**

```php
/**
 * One-time forward migration: move any inline-content row to a file.
 * Rows referenced by loop_stages.artifact_id are migrated, never dropped.
 */
public function migrateLegacyContent(): int
{
    $stmt = $this->db->query(
        "SELECT id, title, type, language, content FROM artifacts
         WHERE content IS NOT NULL AND content != '' AND (path IS NULL OR path = '')",
    );
    if ($stmt === false) {
        return 0;
    }
    $count = 0;
    $update = $this->db->prepare(
        "UPDATE artifacts SET path = ?, content_hash = ?, content = '', updated_at = ? WHERE id = ?",
    );
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $path = $this->fileService->pathFor((string) $row['type'], (string) $row['title'], (string) $row['id'], $row['language'] ?? null);
        $hash = $this->fileService->write($path, (string) $row['content']);
        $update->execute([$path, $hash, Clock::nowUtc(), $row['id']]);
        $count++;
    }

    return $count;
}
```

- [ ] **Step 4: Call it on boot (long-lived processes only)**

In `BootManager::initializeArtifacts`, inside `if (!$skipMaintenance) { ... }`, add `$this->artifactStore->migrateLegacyContent();` (replacing the removed `cleanupFinalized()` call). Ephemeral background tasks skip it (they pass `skipMaintenance = true`), avoiding races.

- [ ] **Step 5: Run tests**

Run: `./vendor/bin/pest tests/Unit/Storage/ArtifactStoreTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Storage/ArtifactStore.php src/Config/BootManager.php tests/Unit/Storage/ArtifactStoreTest.php
git commit -m "feat(artifacts): forward-migrate legacy inline content to files on boot"
```

---

## Task 7: API handler — remove `stage`, drop `persistent` mutation, fix comment

**Files:**
- Modify: `src/Api/Handler/ArtifactHandler.php`
- Test: `tests/Unit/Api/Handler/ArtifactHandlerTest.php`

**Interfaces:**
- `create` no longer accepts `stage`; default create is fine. `update` (PATCH) no longer accepts `stage`; `ALLOWED_STAGES` const removed; `?stage=` list filter removed. `persistent` standalone mutation dropped (project_id still settable). `create()` call uses the new store signature (no `stage`, no `persistent`, no `filepath`). Header comment corrected.

- [ ] **Step 1: Update the failing test**

In `tests/Unit/Api/Handler/ArtifactHandlerTest.php`: replace any test that sends `stage` in create/PATCH or asserts `?stage=` filtering. Add:

```php
it('rejects stage in the PATCH body as an unknown field', function (): void {
    // PATCH with body {stage: 'final'} → 422 unknown field 'stage'
});

it('ignores a ?stage= query filter on list (no longer supported)', function (): void {
    // list?stage=draft returns all artifacts regardless of the param
});
```

Match the handler's existing test harness (request builder, store double).

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/pest tests/Unit/Api/Handler/ArtifactHandlerTest.php`
Expected: FAIL.

- [ ] **Step 3: Edit `ArtifactHandler`**

- Delete `private const array ALLOWED_STAGES`.
- `list()`: remove `$stage` parsing and the `stage:` arg to `store->list`.
- `create()`: remove `$stage` parsing/validation; remove `stage:`/`persistent:`/`filepath:` args to `store->create`; keep `type`, `language`? (store no longer takes `language` via API create unless we keep it — the store `create` keeps `language`, so pass it), `project_id`, `metadata`. Remove `persistent` read.
- `update()`: remove `$stage` handling; remove `stage` from `$allowedKeys`; remove the `updateStage` call; remove the two `persistent` patch branches (keep `project_id`); pass `null` where `update()` previously took `$stage`. Update `store->update($artifactId, $content, $title, $id)` to the new 4-arg signature (id, content, title, sessionId) — note `change_summary` is dropped from the store; keep accepting it in the body but ignore, or remove from `$allowedKeys`. **Decision:** remove `change_summary` and `stage` from `$allowedKeys`; keep `title, content, metadata, tags, summary, language, project_id`.
- Fix the header docblock: replace "Mutating operations (create, update, delete) are REPL-only." with an accurate line, e.g. "Full CRUD is exposed over the API (create, update, delete are wired)."

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/pest tests/Unit/Api/Handler/ArtifactHandlerTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Api/Handler/ArtifactHandler.php tests/Unit/Api/Handler/ArtifactHandlerTest.php
git commit -m "refactor(artifacts): drop stage/persistent from API; fix REPL-only header comment"
```

---

## Task 8: Docs + source map

**Files:**
- Modify: `prompts/tools/artifacts.md`
- Modify: `docs/ARTIFACTS.md`
- Modify: `config/source.json`

- [ ] **Step 1: Rewrite `prompts/tools/artifacts.md`**

Lead with WHEN (substantial / durable / self-contained), the do-NOT list, tie-breaker, payoff (files under `artifacts/<path>`, greppable, git-versioned, path-reference saves budget), update-don't-recreate (reuse id/path, full rewrite). Tools list: `artifact_create` (types `plan|document|code|config`, default `document`), `artifact_update` (id + content, full rewrite, bumps a monotonic version counter — **not** a history store), `artifact_get`, `artifact_list`, `artifact_delete` (single id). Remove `artifact_stage`, bulk-ops, the `sketch`/`hypothesis`/`data`/`other` types, the stage lifecycle section, and the "version history preserves all prior states" line.

- [ ] **Step 2: Rewrite `docs/ARTIFACTS.md`**

Files-only model: content lives at `artifacts/<type>/<slug>-<shortid>.<ext>`; DB row is an index; retention is ownership-based (project-linked persist, session-only cleaned on session delete). Remove draft→review→final lifecycle, bulk-ops, persistence-flag, and "injected every iteration" claims. Document the pinned recent-artifacts index (capped ~10, pointers+path+`by <created_by>`, session→project scope, provenance not filter).

- [ ] **Step 3: Update `config/source.json`**

Refresh the `description`/`api` entries for `ArtifactFileService` (small always-on file backend: pathFor/write/read/delete/hash — no hybrid/drift), `ArtifactStore` (files-only index: columns `id, session_id, project_id, created_by, title, type, path, content_hash, version, timestamps`; CRUD + `cleanupSessionArtifacts` + `migrateLegacyContent`; no stage/bulk; loop_output is a file), `ArtifactToolkit` (create/update/get/list/delete + `recentArtifactsIndex`; no stage/bulk), and `ArtifactHandler` (CRUD, no stage). Update `createStageArtifact` note (no stage). Remove the `sprint_id` mention.

- [ ] **Step 4: Verify links/commands, regen docs index if headings changed**

Run: `composer regen-docs`
(Generated `config/documentation.json` is not committed.)

- [ ] **Step 5: Commit**

```bash
git add prompts/tools/artifacts.md docs/ARTIFACTS.md config/source.json
git commit -m "docs(artifacts): rewrite for files-only model; refresh source map"
```

---

## Task 9: Full verification

- [ ] **Step 1: Full test suite**

Run: `composer test`
Expected: green. Fix any residual references to removed methods/columns (grep `cleanupFinalized`, `bulkDelete`, `bulkUpdateStage`, `updateStage` on ArtifactStore, `artifact_stage`, `isArtifactFilesystemBacked`, `ARTIFACT_FILESYSTEM_BACKED`, `hasPersistentArtifacts`, `detectDrift`, `computeFileHash`, `resolveCanonicalPath`, `isFilesystemBacked` across `src/` and `tests/`).

- [ ] **Step 2: Static analysis**

Run: `composer analyse`
Expected: green (0 errors).

- [ ] **Step 3: Grep sweep for dangling references**

```bash
grep -rn "cleanupFinalized\|hasPersistentArtifacts\|isArtifactFilesystemBacked\|ARTIFACT_FILESYSTEM_BACKED\|artifact_bulk_delete\|resolveCanonicalPath\|detectDrift\|bulkUpdateStage\|artifact_stage\|sprint_id" src/ tests/ config/ prompts/ docs/
```
Expected: only intentional historical mentions (e.g. this plan/spec), none in `src/`.

- [ ] **Step 4: Request code review**

Use superpowers:requesting-code-review before merge.

---

## Self-Review (against spec)

- **Files-only storage** → Tasks 1–2. ✓
- **Wire file service into bare sites (ApiCommand/SpawnAgentTool/DoctorCommand/BootManager)** → Task 3. ✓
- **Delete hybrid engine (DB_ONLY_TYPES/AUTO_PATH/EXPLICIT_PATH, drift API)** → Task 1 (rewrite drops them). ✓
- **Delete isArtifactFilesystemBacked + default + gate** → Task 3 Step 8. ✓
- **Delete hasPersistentArtifacts, sprint_id declaration** → Task 2. ✓
- **Remove artifact_bulk_delete approval** → Task 5. ✓
- **loop_output → file** → falls out of Task 2 (all types are files) + Task 5 (`createStageArtifact` drops stage, adds createdBy). ✓
- **Preserve loop_stages.artifact_id + reviewer artifact_get(id)** → unchanged; Task 2 `get` reads file. ✓
- **Kill stage machine; replace cleanupFinalized with ownership cleanup** → Task 4. ✓
- **Trim create enum to {plan,document,code,config}; keep loop_output in list filter** → Task 5. ✓
- **Drop bulk ops / resolveArtifactTargets / single-id delete** → Task 5. ✓
- **created_by provenance (profile else role; loop identity for loop_output), display-only** → Tasks 2 + 5. ✓
- **Pinned recent-artifacts index (Workflow priority, pointers+path+by, cap ~10, session→project, not creator-filtered)** → Task 5. ✓
- **Guidance rewrite (WHEN threshold + payoff + update-don't-recreate)** → Tasks 5 (`recentArtifactsIndex`) + 8 (`artifacts.md`). ✓
- **Legacy migration on boot (forward-migrate, preserve loop_stages rows)** → Task 6. ✓
- **API: remove stage from PATCH/`?stage=`, fix header comment** → Task 7. ✓
- **Docs: ARTIFACTS.md, artifacts.md, source.json** → Task 8. ✓
- **Tests: create/get/update/delete/cleanup/loop_output/migration/index** → distributed across Tasks 1–7. ✓
```

**Deviations / decisions flagged for the report:**
- Dormant columns (`stage`, `storage_mode`, `canonical_path`, `persistent`) are **left in place** (kept written for `persistent`/`filepath` back-compat) rather than dropped via table rebuild — spec permits "leave dormant if a table rebuild is not cheap." SQLite column-drop is a full rebuild; not worth it.
- Session-file cleanup is wired at the API session-delete call site (`SessionHandler` gets an `ArtifactStore`); if that widens scope unacceptably, fall back to `cleanupSessionArtifacts` as the tested unit + FK cascade for rows, and note it.
