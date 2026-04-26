<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Storage\TodoStore;
use CoquiBot\Coqui\Toolkit\TodoToolkit;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-todo-toolkit-test-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
    $this->sessionId = $this->storage->createSession('orchestrator', 'test/model');
    $this->artifactStore = new ArtifactStore($this->storage->getPdo());
    $this->todoStore = new TodoStore($this->storage->getPdo());
    $this->artifactId = $this->artifactStore->create($this->sessionId, 'Plan', 'content', type: 'plan');
    $this->toolkit = new TodoToolkit($this->todoStore, $this->sessionId, artifactStore: $this->artifactStore);
});

afterEach(function () {
    if (file_exists($this->dbPath)) {
        unlink($this->dbPath);
    }
});

test('full-access toolkit exposes session cleanup tools', function () {
    $names = array_map(
        fn($tool) => $tool->toFunctionSchema()['function']['name'],
        $this->toolkit->tools(),
    );

    expect($names)->toContain('todo_complete');
    expect($names)->toContain('todo_delete');
});

test('todo_complete supports completing all pending and in-progress todos', function () {
    $todoA = $this->todoStore->create($this->sessionId, 'Todo A', artifactId: $this->artifactId);
    $todoB = $this->todoStore->create($this->sessionId, 'Todo B', artifactId: $this->artifactId);
    $this->todoStore->update($todoB, status: 'in_progress', sessionId: $this->sessionId);

    $tool = array_values(array_filter(
        $this->toolkit->tools(),
        fn($candidate) => $candidate->toFunctionSchema()['function']['name'] === 'todo_complete',
    ))[0];

    $result = $tool->execute(['all' => true]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($this->todoStore->get($todoA)['status'])->toBe('completed');
    expect($this->todoStore->get($todoB)['status'])->toBe('completed');
});

test('todo_delete clears completed scope when requested', function () {
    $completedId = $this->todoStore->create($this->sessionId, 'Completed', artifactId: $this->artifactId);
    $pendingId = $this->todoStore->create($this->sessionId, 'Pending', artifactId: $this->artifactId);
    $this->todoStore->complete($completedId, 'coder', sessionId: $this->sessionId);

    $tool = array_values(array_filter(
        $this->toolkit->tools(),
        fn($candidate) => $candidate->toFunctionSchema()['function']['name'] === 'todo_delete',
    ))[0];

    $result = $tool->execute(['scope' => 'completed']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($this->todoStore->get($completedId))->toBeNull();
    expect($this->todoStore->get($pendingId))->not->toBeNull();
});

test('todo_delete can wipe the session todo list', function () {
    $this->todoStore->create($this->sessionId, 'One', artifactId: $this->artifactId);
    $this->todoStore->create($this->sessionId, 'Two', artifactId: $this->artifactId);

    $tool = array_values(array_filter(
        $this->toolkit->tools(),
        fn($candidate) => $candidate->toFunctionSchema()['function']['name'] === 'todo_delete',
    ))[0];

    $result = $tool->execute(['scope' => 'all']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($this->todoStore->list($this->sessionId))->toBeEmpty();
});

test('todo_add accepts native array items', function () {
    $tool = array_values(array_filter(
        $this->toolkit->tools(),
        fn($candidate) => $candidate->toFunctionSchema()['function']['name'] === 'todo_add',
    ))[0];

    $result = $tool->execute([
        'artifact_id' => $this->artifactId,
        'items' => [
            ['title' => 'First batch todo', 'priority' => 'high'],
            ['title' => 'Second batch todo', 'notes' => 'detail'],
        ],
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $data = json_decode($result->content, true);
    expect($data['created'])->toBe(2);
    expect($this->todoStore->list($this->sessionId))->toHaveCount(2);
});

test('todo_update accepts native array updates', function () {
    $todoId = $this->todoStore->create($this->sessionId, 'Todo A', artifactId: $this->artifactId);

    $tool = array_values(array_filter(
        $this->toolkit->tools(),
        fn($candidate) => $candidate->toFunctionSchema()['function']['name'] === 'todo_update',
    ))[0];

    $result = $tool->execute([
        'updates' => [
            ['id' => $todoId, 'status' => 'completed', 'notes' => 'done'],
        ],
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($this->todoStore->get($todoId)['status'])->toBe('completed');
});

test('todo_complete accepts native array ids', function () {
    $todoA = $this->todoStore->create($this->sessionId, 'Todo A', artifactId: $this->artifactId);
    $todoB = $this->todoStore->create($this->sessionId, 'Todo B', artifactId: $this->artifactId);

    $tool = array_values(array_filter(
        $this->toolkit->tools(),
        fn($candidate) => $candidate->toFunctionSchema()['function']['name'] === 'todo_complete',
    ))[0];

    $result = $tool->execute(['ids' => [$todoA, $todoB]]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($this->todoStore->get($todoA)['status'])->toBe('completed');
    expect($this->todoStore->get($todoB)['status'])->toBe('completed');
});

test('todo_delete accepts native array ids', function () {
    $todoA = $this->todoStore->create($this->sessionId, 'Todo A', artifactId: $this->artifactId);
    $todoB = $this->todoStore->create($this->sessionId, 'Todo B', artifactId: $this->artifactId);

    $tool = array_values(array_filter(
        $this->toolkit->tools(),
        fn($candidate) => $candidate->toFunctionSchema()['function']['name'] === 'todo_delete',
    ))[0];

    $result = $tool->execute(['ids' => [$todoA, $todoB]]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($this->todoStore->get($todoA))->toBeNull();
    expect($this->todoStore->get($todoB))->toBeNull();
});