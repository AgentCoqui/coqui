<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Toolkit\ArtifactToolkit;

beforeEach(function (): void {
    $this->dbPath = sys_get_temp_dir() . '/coqui-toolkit-test-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
    $this->sessionId = $this->storage->createSession('orchestrator', 'test/model');
    $this->store = artifactStoreForTest($this->storage->getPdo());
    $this->toolkit = new ArtifactToolkit($this->store, $this->sessionId, createdBy: 'alice');
});

afterEach(function (): void {
    releaseTestObjectProperties($this);
    cleanupSqliteTestDb($this->dbPath);
});

// --- Tool surface ---

test('full-access toolkit exposes exactly create/update/get/list/delete', function (): void {
    $names = array_map(
        fn($t) => $t->toFunctionSchema()['function']['name'],
        $this->toolkit->tools(),
    );

    expect($names)->toBe([
        'artifact_create',
        'artifact_update',
        'artifact_get',
        'artifact_list',
        'artifact_delete',
    ]);
});

test('read-only toolkit omits delete', function (): void {
    $readOnly = new ArtifactToolkit($this->store, $this->sessionId, readOnly: true);
    $names = array_map(
        fn($t) => $t->toFunctionSchema()['function']['name'],
        $readOnly->tools(),
    );

    expect($names)->toBe(['artifact_create', 'artifact_update', 'artifact_get', 'artifact_list'])
        ->and($names)->not->toContain('artifact_delete');
});

test('no stage or bulk tools remain', function (): void {
    $names = array_map(
        fn($t) => $t->toFunctionSchema()['function']['name'],
        $this->toolkit->tools(),
    );

    expect($names)->not->toContain('artifact_stage');
});

// --- Create ---

test('artifact_create writes a file, returns its path, and stamps created_by', function (): void {
    $tool = toolFromToolkit($this->toolkit, 'artifact_create');

    $result = $tool->execute([
        'title' => 'My Doc',
        'content' => "line 1\nline 2\n",
        'type' => 'document',
    ]);

    $data = assertStructuredToolResult($result);

    expect($data['id'])->toBeString()
        ->and($data['path'])->toStartWith('artifacts/document/')
        ->and($data['version'])->toBe(1);

    $stored = $this->store->get($data['id'], $this->sessionId);
    expect($stored['created_by'])->toBe('alice')
        ->and($stored['content'])->toBe("line 1\nline 2\n");
});

test('artifact_create rejects an empty title', function (): void {
    $tool = toolFromToolkit($this->toolkit, 'artifact_create');
    $result = $tool->execute(['title' => '  ', 'content' => 'x']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

// --- Update ---

test('artifact_update rewrites content and bumps version', function (): void {
    $create = toolFromToolkit($this->toolkit, 'artifact_create')->execute(['title' => 'Doc', 'content' => 'v1']);
    $id = assertStructuredToolResult($create)['id'];

    $result = toolFromToolkit($this->toolkit, 'artifact_update')->execute(['id' => $id, 'content' => 'v2']);
    $data = assertStructuredToolResult($result);

    expect($data['version'])->toBe(2)
        ->and($this->store->get($id, $this->sessionId)['content'])->toBe('v2');
});

// --- Get / List / Delete ---

test('artifact_get returns content read from the file', function (): void {
    $id = assertStructuredToolResult(
        toolFromToolkit($this->toolkit, 'artifact_create')->execute(['title' => 'Doc', 'content' => 'body']),
    )['id'];

    $data = assertStructuredToolResult(toolFromToolkit($this->toolkit, 'artifact_get')->execute(['id' => $id]));

    expect($data['content'])->toBe('body')->and($data['path'])->toStartWith('artifacts/document/');
});

test('artifact_delete removes the artifact', function (): void {
    $id = assertStructuredToolResult(
        toolFromToolkit($this->toolkit, 'artifact_create')->execute(['title' => 'Doc', 'content' => 'x']),
    )['id'];

    $result = toolFromToolkit($this->toolkit, 'artifact_delete')->execute(['id' => $id]);

    expect(assertStructuredToolResult($result)['deleted'])->toBeTrue()
        ->and($this->store->get($id, $this->sessionId))->toBeNull();
});

// --- Pinned recent-artifacts index ---

test('recentArtifactsIndex shows when-to-use guidance and no list when empty', function (): void {
    $index = $this->toolkit->recentArtifactsIndex();

    expect($index)->toContain('<ARTIFACTS>')
        ->and($index)->toContain('substantial')
        ->and($index)->not->toContain('Recent artifacts in scope');
});

test('recentArtifactsIndex lists pointers with path and provenance, capped, not creator-filtered', function (): void {
    // Two different creators; the index must show both.
    $alice = new ArtifactToolkit($this->store, $this->sessionId, createdBy: 'alice');
    $bob = new ArtifactToolkit($this->store, $this->sessionId, createdBy: 'bob');

    for ($i = 0; $i < 8; $i++) {
        toolFromToolkit($alice, 'artifact_create')->execute(['title' => "Alice {$i}", 'content' => 'x']);
    }
    for ($i = 0; $i < 8; $i++) {
        toolFromToolkit($bob, 'artifact_create')->execute(['title' => "Bob {$i}", 'content' => 'y']);
    }

    $index = $alice->recentArtifactsIndex();

    // Capped at 10 pointer lines.
    expect(substr_count($index, "\n- **"))->toBe(10)
        ->and($index)->toContain('Recent artifacts in scope')
        ->and($index)->toContain('artifacts/document/')
        ->and($index)->toContain('by bob')   // not filtered to the loading creator
        ->and($index)->toContain('by alice');
});
