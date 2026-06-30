<?php

declare(strict_types=1);

use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\SessionStorage;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-artifact-test-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
    $this->sessionId = $this->storage->createSession('orchestrator', 'test/model');
    $this->store = new ArtifactStore($this->storage->getPdo());
});

afterEach(function () {
    releaseTestObjectProperties($this);
    cleanupSqliteTestDb($this->dbPath);
});

// --- Create ---

test('create returns a 32-char hex id', function () {
    $id = $this->store->create(
        sessionId: $this->sessionId,
        title: 'My File',
        content: '<?php echo "hello";',
    );

    expect($id)->toBeString();
    expect(strlen($id))->toBe(32);
});

test('create stores artifact with correct fields', function () {
    $id = $this->store->create(
        sessionId: $this->sessionId,
        title: 'Auth Service',
        content: 'class AuthService {}',
        type: 'code',
        language: 'php',
        filepath: 'src/AuthService.php',
    );

    $artifact = $this->store->get($id);

    expect($artifact)->not->toBeNull();
    expect($artifact['title'])->toBe('Auth Service');
    expect($artifact['type'])->toBe('code');
    expect($artifact['language'])->toBe('php');
    expect($artifact['filepath'])->toBe('src/AuthService.php');
    expect($artifact['stage'])->toBe('draft');
    expect((int) $artifact['version'])->toBe(1);
    expect($artifact['content'])->toBe('class AuthService {}');
});

// --- Update ---

test('update bumps the version counter and content', function () {
    $id = $this->store->create(
        sessionId: $this->sessionId,
        title: 'Config',
        content: 'v1 content',
    );

    $result = $this->store->update($id, 'v2 content', 'Fixed typo');

    expect($result)->toBeTrue();

    $artifact = $this->store->get($id);
    expect((int) $artifact['version'])->toBe(2);
    expect($artifact['content'])->toBe('v2 content');
});

test('update can change title and stage', function () {
    $id = $this->store->create(
        sessionId: $this->sessionId,
        title: 'Old Title',
        content: 'content',
    );

    $this->store->update($id, 'new content', title: 'New Title', stage: 'review');

    $artifact = $this->store->get($id);
    expect($artifact['title'])->toBe('New Title');
    expect($artifact['stage'])->toBe('review');
});

test('update returns false for nonexistent artifact', function () {
    $result = $this->store->update('nonexistent', 'content');

    expect($result)->toBeFalse();
});

// --- Get ---

test('get returns null for missing artifact', function () {
    expect($this->store->get('nonexistent'))->toBeNull();
});

// --- List ---

test('list returns artifacts for session', function () {
    $this->store->create($this->sessionId, 'A', 'content a');
    $this->store->create($this->sessionId, 'B', 'content b');

    $list = $this->store->list($this->sessionId);

    expect($list)->toHaveCount(2);
});

test('list filters by type', function () {
    $this->store->create($this->sessionId, 'Code', 'x', type: 'code');
    $this->store->create($this->sessionId, 'Doc', 'y', type: 'document');

    $codeOnly = $this->store->list($this->sessionId, type: 'code');

    expect($codeOnly)->toHaveCount(1);
    expect($codeOnly[0]['title'])->toBe('Code');
});

test('list filters by stage', function () {
    $id = $this->store->create($this->sessionId, 'Draft', 'x');
    $id2 = $this->store->create($this->sessionId, 'Final', 'y');
    $this->store->updateStage($id2, 'final');

    $finals = $this->store->list($this->sessionId, stage: 'final');

    expect($finals)->toHaveCount(1);
    expect($finals[0]['title'])->toBe('Final');
});

test('list returns empty for other session', function () {
    $this->store->create($this->sessionId, 'Mine', 'content');
    $otherId = $this->storage->createSession('orchestrator', 'model');

    expect($this->store->list($otherId))->toHaveCount(0);
});

// --- Delete ---

test('delete removes artifact', function () {
    $id = $this->store->create($this->sessionId, 'Deletable', 'content');

    expect($this->store->delete($id))->toBeTrue();
    expect($this->store->get($id))->toBeNull();
});

test('delete returns false for nonexistent', function () {
    expect($this->store->delete('nonexistent'))->toBeFalse();
});

test('bulkDelete removes multiple artifacts in a session', function () {
    $id1 = $this->store->create($this->sessionId, 'One', 'content');
    $id2 = $this->store->create($this->sessionId, 'Two', 'content');
    $otherSessionId = $this->storage->createSession('orchestrator', 'test/model');
    $otherId = $this->store->create($otherSessionId, 'Other', 'content');

    $deleted = $this->store->bulkDelete([$id1, $id2, $otherId], $this->sessionId);

    expect($deleted)->toBe(2);
    expect($this->store->get($id1))->toBeNull();
    expect($this->store->get($id2))->toBeNull();
    expect($this->store->get($otherId))->not->toBeNull();
});

// --- Version counter ---

test('version counter increments on each update', function () {
    $id = $this->store->create($this->sessionId, 'Versioned', 'v1');
    $this->store->update($id, 'v2', 'Second');
    $this->store->update($id, 'v3', 'Third');

    $artifact = $this->store->get($id);

    expect((int) $artifact['version'])->toBe(3);
    expect($artifact['content'])->toBe('v3');
});

// --- Stage ---

test('updateStage changes stage', function () {
    $id = $this->store->create($this->sessionId, 'Staged', 'content');

    $this->store->updateStage($id, 'review');

    expect($this->store->get($id)['stage'])->toBe('review');
});

test('updateStage returns false for nonexistent', function () {
    expect($this->store->updateStage('nonexistent', 'final'))->toBeFalse();
});

// --- Cleanup ---

test('cleanupFinalized deletes final-stage artifacts', function () {
    $id1 = $this->store->create($this->sessionId, 'Final One', 'content', stage: 'draft');
    $this->store->updateStage($id1, 'final');

    $id2 = $this->store->create($this->sessionId, 'Final Two', 'content', stage: 'draft');
    $this->store->updateStage($id2, 'final');

    $count = $this->store->cleanupFinalized();

    expect($count)->toBe(2);
    expect($this->store->get($id1))->toBeNull();
    expect($this->store->get($id2))->toBeNull();
});

test('cleanupFinalized preserves draft and review artifacts', function () {
    $draft = $this->store->create($this->sessionId, 'Draft Plan', 'content');
    $review = $this->store->create($this->sessionId, 'Review Plan', 'content');
    $this->store->updateStage($review, 'review');
    $final = $this->store->create($this->sessionId, 'Final Plan', 'content');
    $this->store->updateStage($final, 'final');

    $count = $this->store->cleanupFinalized();

    expect($count)->toBe(1);
    expect($this->store->get($draft))->not->toBeNull();
    expect($this->store->get($review))->not->toBeNull();
    expect($this->store->get($final))->toBeNull();
});

test('cleanupFinalized returns zero when no final artifacts exist', function () {
    $this->store->create($this->sessionId, 'Draft', 'content');

    expect($this->store->cleanupFinalized())->toBe(0);
});

test('cleanupFinalized removes the finalized artifact row', function () {
    $id = $this->store->create($this->sessionId, 'Versioned', 'v1');
    $this->store->update($id, 'v2', 'Second');
    $this->store->updateStage($id, 'final');

    $this->store->cleanupFinalized();

    expect($this->store->get($id))->toBeNull();
});
