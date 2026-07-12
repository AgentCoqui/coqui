<?php

declare(strict_types=1);

use CoquiBot\Coqui\Storage\ArtifactFileService;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\SessionStorage;

beforeEach(function (): void {
    $this->dbPath = sys_get_temp_dir() . '/coqui-artifact-test-' . bin2hex(random_bytes(8)) . '.db';
    $this->workspace = sys_get_temp_dir() . '/as-' . bin2hex(random_bytes(6));
    mkdir($this->workspace, 0775, true);
    $this->storage = new SessionStorage($this->dbPath);
    $this->sessionId = $this->storage->createSession('orchestrator', 'test/model');
    $this->svc = new ArtifactFileService($this->workspace);
    $this->store = new ArtifactStore($this->storage->getPdo(), $this->svc);
});

afterEach(function (): void {
    releaseTestObjectProperties($this);
    cleanupSqliteTestDb($this->dbPath);
    exec('rm -rf ' . escapeshellarg($this->workspace));
});

// --- Create ---

test('create returns a 32-char hex id', function (): void {
    $id = $this->store->create($this->sessionId, 'My File', '<?php echo "hi";');

    expect($id)->toBeString()->and(strlen($id))->toBe(32);
});

test('create writes a real file at a predictable path and indexes it', function (): void {
    $id = $this->store->create($this->sessionId, 'Design Doc', "# Title\nbody\n", 'document', createdBy: 'coder');
    $row = $this->store->get($id, $this->sessionId);

    expect($row)->not->toBeNull()
        ->and($row['path'])->toStartWith('artifacts/document/')
        ->and(file_exists($this->workspace . '/' . $row['path']))->toBeTrue()
        ->and($row['content'])->toBe("# Title\nbody\n")
        ->and($row['created_by'])->toBe('coder')
        ->and((int) $row['version'])->toBe(1);
});

test('create stores the code file with a language-derived extension', function (): void {
    $id = $this->store->create($this->sessionId, 'Auth Service', 'class AuthService {}', 'code', language: 'php');
    $row = $this->store->get($id, $this->sessionId);

    expect($row['path'])->toEndWith('.php')
        ->and($row['type'])->toBe('code')
        ->and($row['content'])->toBe('class AuthService {}');
});

// --- Get ---

test('get reads content from the file, not the DB', function (): void {
    $id = $this->store->create($this->sessionId, 'Doc', 'original', 'document');
    $path = $this->store->get($id, $this->sessionId)['path'];
    file_put_contents($this->workspace . '/' . $path, 'edited on disk');

    expect($this->store->get($id, $this->sessionId)['content'])->toBe('edited on disk');
});

test('get returns null for a wrong session scope', function (): void {
    $id = $this->store->create($this->sessionId, 'Doc', 'x', 'document');

    expect($this->store->get($id, 'some-other-session'))->toBeNull();
});

// --- Update ---

test('update rewrites the same path and bumps the version', function (): void {
    $id = $this->store->create($this->sessionId, 'Doc', 'v1', 'document');
    $path1 = $this->store->get($id, $this->sessionId)['path'];

    $this->store->update($id, 'v2', sessionId: $this->sessionId);
    $after = $this->store->get($id, $this->sessionId);

    expect($after['path'])->toBe($path1)
        ->and($after['content'])->toBe('v2')
        ->and((int) $after['version'])->toBe(2)
        ->and(file_get_contents($this->workspace . '/' . $path1))->toBe('v2');
});

test('update can rename the title', function (): void {
    $id = $this->store->create($this->sessionId, 'Old', 'x', 'document');
    $this->store->update($id, 'x', title: 'New', sessionId: $this->sessionId);

    expect($this->store->get($id, $this->sessionId)['title'])->toBe('New');
});

// --- List ---

test('list returns index rows filtered by type and project', function (): void {
    $this->store->create($this->sessionId, 'A', 'a', 'document');
    $this->store->create($this->sessionId, 'B', 'b', 'plan', projectId: 'p1');

    expect($this->store->list($this->sessionId))->toHaveCount(2)
        ->and($this->store->list($this->sessionId, type: 'plan'))->toHaveCount(1)
        ->and($this->store->list($this->sessionId, projectId: 'p1'))->toHaveCount(1);
});

// --- Delete ---

test('delete removes the file and the row', function (): void {
    $id = $this->store->create($this->sessionId, 'Doc', 'x', 'document');
    $path = $this->store->get($id, $this->sessionId)['path'];

    expect($this->store->delete($id, $this->sessionId))->toBeTrue()
        ->and($this->store->get($id, $this->sessionId))->toBeNull()
        ->and(file_exists($this->workspace . '/' . $path))->toBeFalse();
});

// --- Patch ---

test('patch updates title and project link', function (): void {
    $id = $this->store->create($this->sessionId, 'Doc', 'x', 'document');
    $this->store->patch($id, ['title' => 'Renamed', 'project_id' => 'p9'], $this->sessionId);
    $row = $this->store->get($id, $this->sessionId);

    expect($row['title'])->toBe('Renamed')->and($row['project_id'])->toBe('p9');
});

// --- Ownership cleanup ---

test('cleanupSessionArtifacts removes session-only artifacts and keeps project-linked ones', function (): void {
    $sessionOnly = $this->store->create($this->sessionId, 'Ephemeral', 'x', 'document');
    $projectLinked = $this->store->create($this->sessionId, 'Keeper', 'y', 'plan', projectId: 'p1');
    $sessionPath = $this->store->get($sessionOnly, $this->sessionId)['path'];
    $projectPath = $this->store->get($projectLinked, $this->sessionId)['path'];

    $removed = $this->store->cleanupSessionArtifacts($this->sessionId);

    expect($removed)->toBe(1)
        ->and($this->store->get($sessionOnly, $this->sessionId))->toBeNull()
        ->and(file_exists($this->workspace . '/' . $sessionPath))->toBeFalse()
        ->and($this->store->get($projectLinked, $this->sessionId))->not->toBeNull()
        ->and(file_exists($this->workspace . '/' . $projectPath))->toBeTrue();
});

// --- Recent index scoping ---

test('listRecent returns project artifacts across sessions when a project is loaded', function (): void {
    $other = $this->storage->createSession('orchestrator', 'test/model');
    $this->store->create($this->sessionId, 'Mine', 'a', 'plan', projectId: 'p1');
    $this->store->create($other, 'Theirs', 'b', 'plan', projectId: 'p1');
    $this->store->create($this->sessionId, 'Unrelated', 'c', 'document'); // no project

    $recent = $this->store->listRecent($this->sessionId, projectId: 'p1', limit: 10);

    expect($recent)->toHaveCount(2)
        ->and(array_column($recent, 'title'))->toContain('Mine')
        ->and(array_column($recent, 'title'))->toContain('Theirs');
});

test('listRecent falls back to the session when no project is loaded', function (): void {
    $other = $this->storage->createSession('orchestrator', 'test/model');
    $this->store->create($this->sessionId, 'Mine', 'a', 'document');
    $this->store->create($other, 'Theirs', 'b', 'document');

    $recent = $this->store->listRecent($this->sessionId, projectId: null, limit: 10);

    expect($recent)->toHaveCount(1)->and($recent[0]['title'])->toBe('Mine');
});

test('hasProjectLinkedArtifacts reflects project ownership', function (): void {
    expect($this->store->hasProjectLinkedArtifacts($this->sessionId))->toBeFalse();
    $this->store->create($this->sessionId, 'Doc', 'x', 'document', projectId: 'p1');
    expect($this->store->hasProjectLinkedArtifacts($this->sessionId))->toBeTrue();
});

// --- Legacy migration ---

test('migrateLegacyContent moves inline content to files and preserves the row', function (): void {
    $pdo = $this->storage->getPdo();
    $pdo->prepare(
        "INSERT INTO artifacts (id, session_id, title, type, content, path, version, created_at, updated_at)
         VALUES ('legacy1', ?, 'Old', 'loop_output', 'LEGACY BODY', '', 1, '2020-01-01T00:00:00Z', '2020-01-01T00:00:00Z')",
    )->execute([$this->sessionId]);

    $migrated = $this->store->migrateLegacyContent();
    $row = $this->store->get('legacy1', $this->sessionId);

    expect($migrated)->toBeGreaterThanOrEqual(1)
        ->and($row['path'])->not->toBe('')
        ->and(file_exists($this->workspace . '/' . $row['path']))->toBeTrue()
        ->and($row['content'])->toBe('LEGACY BODY');
});
