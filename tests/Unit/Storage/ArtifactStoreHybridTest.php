<?php

declare(strict_types=1);

use CoquiBot\Coqui\Storage\ArtifactFileService;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\ProjectStore;
use CoquiBot\Coqui\Storage\SessionStorage;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-hybrid-test-' . bin2hex(random_bytes(8)) . '.db';
    $this->workspace = sys_get_temp_dir() . '/coqui-hybrid-ws-' . bin2hex(random_bytes(8));
    mkdir($this->workspace, 0755, true);

    $this->storage = new SessionStorage($this->dbPath);
    $pdo = $this->storage->getPdo();
    $this->sessionId = $this->storage->createSession('orchestrator', 'test/model');

    $this->fileService = new ArtifactFileService($this->workspace);
    $this->projectStore = new ProjectStore($pdo);
    $this->store = new ArtifactStore($pdo, $this->fileService, $this->projectStore);

    // Create a test project
    $this->projectId = $this->projectStore->createProject('Test Project', 'test-project');
    $this->projectDir = $this->projectStore->getProjectDirectory($this->projectId);
});

afterEach(function () {
    releaseTestObjectProperties($this);
    cleanupSqliteTestDb($this->dbPath);
    if (is_dir($this->workspace)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->workspace, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($this->workspace);
    }
});

// --- Create with filesystem backing ---

test('create writes plan artifact to disk when project-linked', function () {
    $id = $this->store->create(
        sessionId: $this->sessionId,
        title: 'Sprint Plan',
        content: '# Sprint 1 Plan',
        type: 'plan',
        projectId: $this->projectId,
    );

    $artifact = $this->store->get($id);

    expect($artifact['storage_mode'])->toBe('filesystem');
    expect($artifact['canonical_path'])->not->toBeNull();
    expect($artifact['content_hash'])->not->toBeNull();

    // File should exist on disk
    expect($this->fileService->fileExists($artifact['canonical_path']))->toBeTrue();
    expect($this->fileService->readContent($artifact['canonical_path']))->toBe('# Sprint 1 Plan');
});

test('create keeps code artifact as db-only when no filepath', function () {
    $id = $this->store->create(
        sessionId: $this->sessionId,
        title: 'Code Snippet',
        content: '<?php echo "hello";',
        type: 'code',
        projectId: $this->projectId,
    );

    $artifact = $this->store->get($id);

    expect($artifact['storage_mode'] ?? 'database')->toBe('database');
    expect($artifact['canonical_path'])->toBeNull();
});

test('create writes code artifact to disk when filepath is supplied', function () {
    $id = $this->store->create(
        sessionId: $this->sessionId,
        title: 'Auth Service',
        content: '<?php class AuthService {}',
        type: 'code',
        language: 'php',
        filepath: 'src/AuthService.php',
        projectId: $this->projectId,
    );

    $artifact = $this->store->get($id);

    expect($artifact['storage_mode'])->toBe('filesystem');
    expect($artifact['canonical_path'])->toBe('src/AuthService.php');
    expect($this->fileService->readContent('src/AuthService.php'))->toBe('<?php class AuthService {}');
});

test('loop_output never gets filesystem backing', function () {
    $id = $this->store->create(
        sessionId: $this->sessionId,
        title: 'Loop Output',
        content: 'some output',
        type: 'loop_output',
        projectId: $this->projectId,
    );

    $artifact = $this->store->get($id);
    expect($artifact['storage_mode'] ?? 'database')->toBe('database');
});

// --- Get prefers disk content ---

test('get returns disk content when file has been edited externally', function () {
    $id = $this->store->create(
        sessionId: $this->sessionId,
        title: 'Editable Plan',
        content: 'original content',
        type: 'plan',
        projectId: $this->projectId,
    );

    $artifact = $this->store->get($id);
    $canonicalPath = $artifact['canonical_path'];

    // Simulate external edit
    $this->fileService->writeContent($canonicalPath, 'externally edited content');

    $refreshed = $this->store->get($id);
    expect($refreshed['content'])->toBe('externally edited content');
});

// --- Update writes to disk ---

test('update syncs content to canonical file', function () {
    $id = $this->store->create(
        sessionId: $this->sessionId,
        title: 'Plan',
        content: 'v1',
        type: 'plan',
        projectId: $this->projectId,
    );

    $this->store->update($id, 'v2', 'Updated content');

    $artifact = $this->store->get($id);
    expect($artifact['content'])->toBe('v2');
    expect($this->fileService->readContent($artifact['canonical_path']))->toBe('v2');
});

// --- Stage transition syncs drift ---

test('updateStage syncs drifted disk content to db before transition', function () {
    $id = $this->store->create(
        sessionId: $this->sessionId,
        title: 'Drift Plan',
        content: 'original',
        type: 'plan',
        projectId: $this->projectId,
    );

    $artifact = $this->store->get($id);

    // Simulate external file edit
    $this->fileService->writeContent($artifact['canonical_path'], 'externally revised');

    // Stage transition should sync
    $this->store->updateStage($id, 'final');

    // DB content should now reflect the file
    // Read directly from DB to verify sync (bypass get() which reads disk)
    $stmt = $this->store->list($this->sessionId, stage: 'final');
    expect($stmt[0]['content'])->toBe('externally revised');
});

// --- Delete cleans up files ---

test('delete removes canonical file', function () {
    $id = $this->store->create(
        sessionId: $this->sessionId,
        title: 'Deletable Plan',
        content: 'to be deleted',
        type: 'plan',
        projectId: $this->projectId,
    );

    $artifact = $this->store->get($id);
    $canonicalPath = $artifact['canonical_path'];
    expect($this->fileService->fileExists($canonicalPath))->toBeTrue();

    $this->store->delete($id);

    expect($this->store->get($id))->toBeNull();
    expect($this->fileService->fileExists($canonicalPath))->toBeFalse();
});

// --- cleanupFinalized cleans up files ---

test('cleanupFinalized deletes canonical files for finalized artifacts', function () {
    $id = $this->store->create(
        sessionId: $this->sessionId,
        title: 'Cleanup Plan',
        content: 'cleanup content',
        type: 'plan',
        projectId: $this->projectId,
        persistent: false,
    );

    $artifact = $this->store->get($id);
    $canonicalPath = $artifact['canonical_path'];

    // Project-linked artifacts are auto-persistent, so manually un-persist for test
    $pdo = $this->storage->getPdo();
    $pdo->prepare('UPDATE artifacts SET persistent = 0 WHERE id = ?')->execute([$id]);

    $this->store->updateStage($id, 'final');

    expect($this->fileService->fileExists($canonicalPath))->toBeTrue();

    $this->store->cleanupFinalized();

    expect($this->fileService->fileExists($canonicalPath))->toBeFalse();
});
