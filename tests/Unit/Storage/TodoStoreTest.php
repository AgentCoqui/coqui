<?php

declare(strict_types=1);

use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Storage\TodoStore;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-todo-test-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
    $this->sessionId = $this->storage->createSession('orchestrator', 'test/model');
    // ArtifactStore must be instantiated first to create the artifacts table (FK dependency)
    $this->artifactStore = new ArtifactStore($this->storage->getPdo());
    $this->store = new TodoStore($this->storage->getPdo());
});

afterEach(function () {
    releaseTestObjectProperties($this);
    cleanupSqliteTestDb($this->dbPath);
});

// --- Create ---

test('create returns a 32-char hex id', function () {
    $id = $this->store->create(
        sessionId: $this->sessionId,
        title: 'Implement feature',
    );

    expect($id)->toBeString();
    expect(strlen($id))->toBe(32);
});

test('create stores todo with correct fields', function () {
    $id = $this->store->create(
        sessionId: $this->sessionId,
        title: 'Write tests',
        priority: 'high',
        createdBy: 'coder',
        notes: 'Cover edge cases',
    );

    $todo = $this->store->get($id);

    expect($todo)->not->toBeNull();
    expect($todo['title'])->toBe('Write tests');
    expect($todo['status'])->toBe('pending');
    expect($todo['priority'])->toBe('high');
    expect($todo['created_by'])->toBe('coder');
    expect($todo['notes'])->toBe('Cover edge cases');
    expect($todo['session_id'])->toBe($this->sessionId);
});

test('create with artifact and parent links', function () {
    $parentId = $this->store->create(
        sessionId: $this->sessionId,
        title: 'Parent task',
    );

    $artifactId = $this->artifactStore->create(
        sessionId: $this->sessionId,
        type: 'plan',
        title: 'Test plan',
        content: 'Plan content',
    );

    $childId = $this->store->create(
        sessionId: $this->sessionId,
        title: 'Child task',
        parentId: $parentId,
        artifactId: $artifactId,
    );

    $child = $this->store->get($childId);

    expect($child['parent_id'])->toBe($parentId);
    expect($child['artifact_id'])->toBe($artifactId);
});

// --- Get ---

test('get returns null for nonexistent todo', function () {
    expect($this->store->get('nonexistent'))->toBeNull();
});

// --- Update ---

test('update changes todo fields', function () {
    $id = $this->store->create(
        sessionId: $this->sessionId,
        title: 'Original title',
    );

    $result = $this->store->update(
        id: $id,
        title: 'Updated title',
        priority: 'high',
        notes: 'New notes',
        status: 'in_progress',
    );

    expect($result)->toBeTrue();

    $todo = $this->store->get($id);
    expect($todo['title'])->toBe('Updated title');
    expect($todo['priority'])->toBe('high');
    expect($todo['notes'])->toBe('New notes');
    expect($todo['status'])->toBe('in_progress');
});

test('update returns false for nonexistent todo', function () {
    $result = $this->store->update(id: 'nonexistent', title: 'x');

    expect($result)->toBeFalse();
});

// --- Complete ---

test('complete marks todo as completed', function () {
    $id = $this->store->create(
        sessionId: $this->sessionId,
        title: 'Task to complete',
    );

    $result = $this->store->complete(id: $id, completedBy: 'coder');

    expect($result)->toBeTrue();

    $todo = $this->store->get($id);
    expect($todo['status'])->toBe('completed');
    expect($todo['completed_by'])->toBe('coder');
});

test('complete with notes appends to existing notes', function () {
    $id = $this->store->create(
        sessionId: $this->sessionId,
        title: 'Task with notes',
        notes: 'Initial notes',
    );

    $this->store->complete(id: $id, completedBy: 'coder', notes: 'Done successfully');

    $todo = $this->store->get($id);
    expect($todo['status'])->toBe('completed');
    // Notes should contain completion notes
    expect($todo['notes'])->toContain('Done successfully');
});

test('complete returns false for nonexistent todo', function () {
    $result = $this->store->complete(id: 'nonexistent', completedBy: 'coder');

    expect($result)->toBeFalse();
});

// --- Delete ---

test('delete removes todo', function () {
    $id = $this->store->create(
        sessionId: $this->sessionId,
        title: 'To be deleted',
    );

    $result = $this->store->delete($id);

    expect($result)->toBeTrue();
    expect($this->store->get($id))->toBeNull();
});

test('delete cascades to subtasks', function () {
    $parentId = $this->store->create(
        sessionId: $this->sessionId,
        title: 'Parent',
    );
    $childId = $this->store->create(
        sessionId: $this->sessionId,
        title: 'Child',
        parentId: $parentId,
    );

    $this->store->delete($parentId);

    expect($this->store->get($parentId))->toBeNull();
    expect($this->store->get($childId))->toBeNull();
});

test('delete returns false for nonexistent todo', function () {
    expect($this->store->delete('nonexistent'))->toBeFalse();
});

test('deleteCompletedBySession removes only completed and cancelled todos', function () {
    $completedId = $this->store->create($this->sessionId, 'Completed');
    $cancelledId = $this->store->create($this->sessionId, 'Cancelled');
    $pendingId = $this->store->create($this->sessionId, 'Pending');
    $this->store->complete($completedId, 'coder');
    $this->store->update($cancelledId, status: 'cancelled');

    $deleted = $this->store->deleteCompletedBySession($this->sessionId);

    expect($deleted)->toBe(2);
    expect($this->store->get($completedId))->toBeNull();
    expect($this->store->get($cancelledId))->toBeNull();
    expect($this->store->get($pendingId))->not->toBeNull();
});

test('completeAllBySession completes pending and in progress todos only', function () {
    $pendingId = $this->store->create($this->sessionId, 'Pending');
    $inProgressId = $this->store->create($this->sessionId, 'In Progress');
    $completedId = $this->store->create($this->sessionId, 'Completed');
    $this->store->update($inProgressId, status: 'in_progress');
    $this->store->complete($completedId, 'coder');

    $count = $this->store->completeAllBySession($this->sessionId, 'orchestrator');

    expect($count)->toBe(2);
    expect($this->store->get($pendingId)['status'])->toBe('completed');
    expect($this->store->get($inProgressId)['status'])->toBe('completed');
    expect($this->store->get($completedId)['status'])->toBe('completed');
});

// --- List ---

test('list returns todos for session', function () {
    $this->store->create($this->sessionId, 'Task A');
    $this->store->create($this->sessionId, 'Task B');

    $list = $this->store->list($this->sessionId);

    expect($list)->toHaveCount(2);
});

test('list filters by status', function () {
    $id1 = $this->store->create($this->sessionId, 'Pending task');
    $id2 = $this->store->create($this->sessionId, 'In progress task');
    $this->store->update(id: $id2, status: 'in_progress');

    $inProgress = $this->store->list($this->sessionId, status: 'in_progress');

    expect($inProgress)->toHaveCount(1);
    expect($inProgress[0]['title'])->toBe('In progress task');
});

test('list filters by artifact id', function () {
    $artifactId = $this->artifactStore->create(
        sessionId: $this->sessionId,
        type: 'plan',
        title: 'Test plan',
        content: 'Content',
    );

    $this->store->create($this->sessionId, 'Linked', artifactId: $artifactId);
    $this->store->create($this->sessionId, 'Unlinked');

    $linked = $this->store->list($this->sessionId, artifactId: $artifactId);

    expect($linked)->toHaveCount(1);
    expect($linked[0]['title'])->toBe('Linked');
});

test('list filters by priority', function () {
    $this->store->create($this->sessionId, 'High priority', priority: 'high');
    $this->store->create($this->sessionId, 'Low priority', priority: 'low');

    $high = $this->store->list($this->sessionId, priority: 'high');

    expect($high)->toHaveCount(1);
    expect($high[0]['title'])->toBe('High priority');
});

test('list excludes completed when flag is false', function () {
    $id1 = $this->store->create($this->sessionId, 'Active');
    $id2 = $this->store->create($this->sessionId, 'Done');
    $this->store->complete(id: $id2, completedBy: 'coder');

    $active = $this->store->list($this->sessionId, includeCompleted: false);

    expect($active)->toHaveCount(1);
    expect($active[0]['title'])->toBe('Active');
});

// --- Subtasks ---

test('getSubtasks returns children', function () {
    $parentId = $this->store->create($this->sessionId, 'Parent');
    $this->store->create($this->sessionId, 'Child 1', parentId: $parentId);
    $this->store->create($this->sessionId, 'Child 2', parentId: $parentId);

    $subtasks = $this->store->getSubtasks($parentId);

    expect($subtasks)->toHaveCount(2);
});

// --- Stats ---

test('getStats returns correct counts', function () {
    $id1 = $this->store->create($this->sessionId, 'Pending');
    $id2 = $this->store->create($this->sessionId, 'In progress');
    $id3 = $this->store->create($this->sessionId, 'Completed');
    $id4 = $this->store->create($this->sessionId, 'Cancelled');

    $this->store->update(id: $id2, status: 'in_progress');
    $this->store->complete(id: $id3, completedBy: 'coder');
    $this->store->update(id: $id4, status: 'cancelled');

    $stats = $this->store->getStats($this->sessionId);

    expect($stats['total'])->toBe(4);
    expect($stats['pending'])->toBe(1);
    expect($stats['in_progress'])->toBe(1);
    expect($stats['completed'])->toBe(1);
    expect($stats['cancelled'])->toBe(1);
});

test('getStats filters by artifact id', function () {
    $artifactId = $this->artifactStore->create(
        sessionId: $this->sessionId,
        type: 'plan',
        title: 'Test plan',
        content: 'Content',
    );

    $this->store->create($this->sessionId, 'Linked', artifactId: $artifactId);
    $this->store->create($this->sessionId, 'Unlinked');

    $stats = $this->store->getStats($this->sessionId, $artifactId);

    expect($stats['total'])->toBe(1);
});

// --- Bulk Operations ---

test('bulkCreate creates multiple todos', function () {
    $items = [
        ['title' => 'Step 1', 'priority' => 'high'],
        ['title' => 'Step 2', 'priority' => 'medium'],
        ['title' => 'Step 3', 'priority' => 'low'],
    ];

    $ids = $this->store->bulkCreate(
        sessionId: $this->sessionId,
        items: $items,
        createdBy: 'plan',
    );

    expect($ids)->toHaveCount(3);

    $list = $this->store->list($this->sessionId);
    expect($list)->toHaveCount(3);
});

test('bulkCreate links todos to artifact', function () {
    $artifactId = $this->artifactStore->create(
        sessionId: $this->sessionId,
        type: 'plan',
        title: 'Test plan',
        content: 'Content',
    );

    $items = [
        ['title' => 'Task 1'],
        ['title' => 'Task 2'],
    ];

    $ids = $this->store->bulkCreate(
        sessionId: $this->sessionId,
        items: $items,
        createdBy: 'plan',
        artifactId: $artifactId,
    );

    $linked = $this->store->list($this->sessionId, artifactId: $artifactId);
    expect($linked)->toHaveCount(2);
});

test('bulkUpdate updates multiple todos', function () {
    $id1 = $this->store->create($this->sessionId, 'Task 1');
    $id2 = $this->store->create($this->sessionId, 'Task 2');

    $count = $this->store->bulkUpdate([
        ['id' => $id1, 'status' => 'in_progress'],
        ['id' => $id2, 'status' => 'cancelled'],
    ]);

    expect($count)->toBe(2);

    $todo1 = $this->store->get($id1);
    $todo2 = $this->store->get($id2);
    expect($todo1['status'])->toBe('in_progress');
    expect($todo2['status'])->toBe('cancelled');
});

// --- Cleanup ---

test('cleanupOrphaned removes todos from nonexistent sessions', function () {
    // Disable FK enforcement so we can create an orphan without CASCADE
    $pdo = $this->storage->getPdo();
    $pdo->exec('PRAGMA foreign_keys=OFF');

    $id = $this->store->create($this->sessionId, 'Orphan candidate');

    // Delete the session directly — FK enforcement is off so todo survives
    $pdo->exec("DELETE FROM sessions WHERE id = '{$this->sessionId}'");

    // Re-enable FK enforcement before cleanup
    $pdo->exec('PRAGMA foreign_keys=ON');

    $cleaned = $this->store->cleanupOrphaned();

    expect($cleaned)->toBe(1);
    expect($this->store->get($id))->toBeNull();
});

test('cleanupOrphaned returns zero when no orphans', function () {
    $this->store->create($this->sessionId, 'Valid todo');

    $cleaned = $this->store->cleanupOrphaned();

    expect($cleaned)->toBe(0);
});

test('cleanupStale removes old completed todos from inactive sessions', function () {
    $id = $this->store->create($this->sessionId, 'Old completed');
    $this->store->complete(id: $id, completedBy: 'coder');

    // Backdate the todo and session to make them stale
    $oldDate = date('Y-m-d\TH:i:s\Z', strtotime('-60 days'));
    $this->storage->getPdo()->exec("UPDATE todos SET completed_at = '{$oldDate}' WHERE id = '{$id}'");
    $this->storage->getPdo()->exec("UPDATE sessions SET updated_at = '{$oldDate}' WHERE id = '{$this->sessionId}'");

    $cleaned = $this->store->cleanupStale(staleDays: 30, inactiveDays: 7);

    expect($cleaned)->toBeGreaterThanOrEqual(1);
});

test('cleanupStale preserves recent todos', function () {
    $id = $this->store->create($this->sessionId, 'Recent completed');
    $this->store->complete(id: $id, completedBy: 'coder');

    $cleaned = $this->store->cleanupStale(staleDays: 30, inactiveDays: 7);

    expect($cleaned)->toBe(0);
    expect($this->store->get($id))->not->toBeNull();
});

// --- cleanupUnlinked ---

test('cleanupUnlinked removes NULL artifact_id todos from inactive sessions', function () {
    $id = $this->store->create(
        sessionId: $this->sessionId,
        title: 'Unlinked todo',
    );

    // Backdate session to make it inactive
    $oldDate = date('Y-m-d\TH:i:s\Z', strtotime('-30 days'));
    $this->storage->getPdo()->exec("UPDATE sessions SET updated_at = '{$oldDate}' WHERE id = '{$this->sessionId}'");

    $cleaned = $this->store->cleanupUnlinked(inactiveDays: 7);

    expect($cleaned)->toBe(1);
    expect($this->store->get($id))->toBeNull();
});

test('cleanupUnlinked preserves linked todos from inactive sessions', function () {
    $artifactId = $this->artifactStore->create(
        sessionId: $this->sessionId,
        type: 'plan',
        title: 'Plan',
        content: 'Content',
    );

    $id = $this->store->create(
        sessionId: $this->sessionId,
        title: 'Linked todo',
        artifactId: $artifactId,
    );

    // Backdate session to make it inactive
    $oldDate = date('Y-m-d\TH:i:s\Z', strtotime('-30 days'));
    $this->storage->getPdo()->exec("UPDATE sessions SET updated_at = '{$oldDate}' WHERE id = '{$this->sessionId}'");

    $cleaned = $this->store->cleanupUnlinked(inactiveDays: 7);

    expect($cleaned)->toBe(0);
    expect($this->store->get($id))->not->toBeNull();
});

test('cleanupUnlinked preserves unlinked todos from active sessions', function () {
    $id = $this->store->create(
        sessionId: $this->sessionId,
        title: 'Unlinked but active',
    );

    // Session was just created — it's active
    $cleaned = $this->store->cleanupUnlinked(inactiveDays: 7);

    expect($cleaned)->toBe(0);
    expect($this->store->get($id))->not->toBeNull();
});

test('cleanupUnlinked returns zero when no unlinked todos exist', function () {
    $artifactId = $this->artifactStore->create(
        sessionId: $this->sessionId,
        type: 'plan',
        title: 'Plan',
        content: 'Content',
    );

    $this->store->create(
        sessionId: $this->sessionId,
        title: 'Linked',
        artifactId: $artifactId,
    );

    $cleaned = $this->store->cleanupUnlinked(inactiveDays: 7);

    expect($cleaned)->toBe(0);
});

// --- Session Isolation ---

test('get with sessionId rejects cross-session access', function () {
    $id = $this->store->create(
        sessionId: $this->sessionId,
        title: 'Session A todo',
    );

    $otherSessionId = $this->storage->createSession('orchestrator', 'test/model');

    // Without sessionId — returns the todo
    expect($this->store->get($id))->not->toBeNull();

    // With correct sessionId — returns the todo
    expect($this->store->get($id, sessionId: $this->sessionId))->not->toBeNull();

    // With wrong sessionId — returns null
    expect($this->store->get($id, sessionId: $otherSessionId))->toBeNull();
});

test('update with sessionId rejects cross-session mutation', function () {
    $id = $this->store->create(
        sessionId: $this->sessionId,
        title: 'Original',
    );

    $otherSessionId = $this->storage->createSession('orchestrator', 'test/model');

    $result = $this->store->update(
        id: $id,
        title: 'Hacked',
        sessionId: $otherSessionId,
    );

    expect($result)->toBeFalse();

    // Title unchanged
    $todo = $this->store->get($id);
    expect($todo['title'])->toBe('Original');
});

test('complete with sessionId rejects cross-session mutation', function () {
    $id = $this->store->create(
        sessionId: $this->sessionId,
        title: 'Task to complete',
    );

    $otherSessionId = $this->storage->createSession('orchestrator', 'test/model');

    $result = $this->store->complete(
        id: $id,
        completedBy: 'attacker',
        sessionId: $otherSessionId,
    );

    expect($result)->toBeFalse();

    // Status unchanged
    $todo = $this->store->get($id);
    expect($todo['status'])->toBe('pending');
});

test('delete with sessionId rejects cross-session deletion', function () {
    $id = $this->store->create(
        sessionId: $this->sessionId,
        title: 'Protected todo',
    );

    $otherSessionId = $this->storage->createSession('orchestrator', 'test/model');

    $result = $this->store->delete($id, sessionId: $otherSessionId);

    expect($result)->toBeFalse();

    // Todo still exists
    expect($this->store->get($id))->not->toBeNull();
});

test('list only returns todos for the requested session', function () {
    $this->store->create($this->sessionId, 'Session A');

    $otherSessionId = $this->storage->createSession('orchestrator', 'test/model');
    $this->store->create($otherSessionId, 'Session B');

    $listA = $this->store->list($this->sessionId);
    $listB = $this->store->list($otherSessionId);

    expect($listA)->toHaveCount(1);
    expect($listA[0]['title'])->toBe('Session A');

    expect($listB)->toHaveCount(1);
    expect($listB[0]['title'])->toBe('Session B');
});

test('bulkUpdate with sessionId rejects cross-session mutations', function () {
    $id = $this->store->create(
        sessionId: $this->sessionId,
        title: 'Original',
    );

    $otherSessionId = $this->storage->createSession('orchestrator', 'test/model');

    $count = $this->store->bulkUpdate(
        [['id' => $id, 'title' => 'Hacked']],
        sessionId: $otherSessionId,
    );

    expect($count)->toBe(0);

    $todo = $this->store->get($id);
    expect($todo['title'])->toBe('Original');
});
