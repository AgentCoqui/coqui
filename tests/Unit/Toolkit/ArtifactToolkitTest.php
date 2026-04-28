<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Toolkit\ArtifactToolkit;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-toolkit-test-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
    $this->sessionId = $this->storage->createSession('orchestrator', 'test/model');
    $this->store = new ArtifactStore($this->storage->getPdo());
    $this->toolkit = new ArtifactToolkit($this->store, $this->sessionId);
});

afterEach(function () {
    if (file_exists($this->dbPath)) {
        unlink($this->dbPath);
    }
});

test('provides exactly 6 tools', function () {
    expect($this->toolkit->tools())->toHaveCount(6);
});

test('tool names are correct', function () {
    $names = array_map(
        fn($t) => $t->toFunctionSchema()['function']['name'],
        $this->toolkit->tools(),
    );

    expect($names)->toBe([
        'artifact_create',
        'artifact_update',
        'artifact_get',
        'artifact_list',
        'artifact_stage',
        'artifact_delete',
    ]);
});

test('guidelines shows empty state when no artifacts', function () {
    $guidelines = $this->toolkit->guidelines();

    expect($guidelines)->toContain('ARTIFACT-GUIDELINES');
    expect($guidelines)->toContain('artifact_create');
});

test('guidelines lists existing artifacts', function () {
    $this->store->create($this->sessionId, 'Auth Service', 'code here', type: 'code');

    $guidelines = $this->toolkit->guidelines();

    expect($guidelines)->toContain('Auth Service');
    expect($guidelines)->toContain('artifact_get');
    expect($guidelines)->toContain('1 artifact(s)');
});

test('artifact_create tool creates artifact', function () {
    $tool = toolFromToolkit($this->toolkit, 'artifact_create');

    $result = $tool->execute([
        'title' => 'My Script',
        'content' => 'echo "hi"',
        'type' => 'code',
        'language' => 'php',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->mimeType)->toBe('application/json');
    expect($result->displayHint)->toBe('structured-json');

    $data = json_decode($result->content, true);
    expect($data['title'])->toBe('My Script');
    expect($data['version'])->toBe(1);
    expect($data['stage'])->toBe('draft');
});

test('artifact_create tool rejects empty title', function () {
    $tool = toolFromToolkit($this->toolkit, 'artifact_create');

    $result = $tool->execute(['title' => '', 'content' => 'x']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('Title is required');
});

test('artifact_update tool updates content', function () {
    $id = $this->store->create($this->sessionId, 'Doc', 'v1');

    $tool = toolFromToolkit($this->toolkit, 'artifact_update');

    $result = $tool->execute([
        'id' => $id,
        'content' => 'v2',
        'change_summary' => 'Revised',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);

    $data = json_decode($result->content, true);
    expect($data['version'])->toBe(2);
});

test('artifact_update tool errors on missing id', function () {
    $tool = toolFromToolkit($this->toolkit, 'artifact_update');

    $result = $tool->execute(['id' => 'nonexistent', 'content' => 'x']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

test('artifact_get tool retrieves artifact', function () {
    $id = $this->store->create($this->sessionId, 'Fetched', 'body');

    $tool = toolFromToolkit($this->toolkit, 'artifact_get');

    $result = $tool->execute(['id' => $id]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $data = json_decode($result->content, true);
    expect($data['title'])->toBe('Fetched');
    expect($data['content'])->toBe('body');
});

test('artifact_get tool retrieves specific version', function () {
    $id = $this->store->create($this->sessionId, 'Versioned', 'original');
    $this->store->update($id, 'updated');

    $tool = toolFromToolkit($this->toolkit, 'artifact_get');

    $result = $tool->execute(['id' => $id, 'version' => 1]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $data = json_decode($result->content, true);
    expect($data['content'])->toBe('original');
    expect($data['version'])->toBe(1);
});

test('artifact_list tool returns artifacts', function () {
    $this->store->create($this->sessionId, 'A', 'content a');
    $this->store->create($this->sessionId, 'B', 'content b');

    $tool = toolFromToolkit($this->toolkit, 'artifact_list');

    $result = $tool->execute([]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $data = json_decode($result->content, true);
    expect($data['count'])->toBe(2);
});

test('artifact_list tool returns message when empty', function () {
    $tool = toolFromToolkit($this->toolkit, 'artifact_list');

    $result = $tool->execute([]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('No artifacts found');
});

test('artifact_stage tool transitions stage', function () {
    $id = $this->store->create($this->sessionId, 'Staged', 'content');

    $tool = toolFromToolkit($this->toolkit, 'artifact_stage');

    $result = $tool->execute(['id' => $id, 'stage' => 'review']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $data = json_decode($result->content, true);
    expect($data['previous_stage'])->toBe('draft');
    expect($data['new_stage'])->toBe('review');
});

test('artifact_stage tool errors on missing artifact', function () {
    $tool = toolFromToolkit($this->toolkit, 'artifact_stage');

    $result = $tool->execute(['id' => 'nonexistent', 'stage' => 'final']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

// --- ReadOnly Mode ---

test('default toolkit provides 6 tools including delete', function () {
    $toolkit = new ArtifactToolkit($this->store, $this->sessionId);

    $names = array_map(
        fn($t) => $t->toFunctionSchema()['function']['name'],
        $toolkit->tools(),
    );

    expect($names)->toContain('artifact_delete');
    expect($names)->toHaveCount(6);
});

test('readonly toolkit provides 5 tools without delete', function () {
    $toolkit = new ArtifactToolkit($this->store, $this->sessionId, readOnly: true);

    $names = array_map(
        fn($t) => $t->toFunctionSchema()['function']['name'],
        $toolkit->tools(),
    );

    expect($names)->not->toContain('artifact_delete');
    expect($names)->toHaveCount(5);
});

test('readonly toolkit still allows create, update, get, list, stage', function () {
    $toolkit = new ArtifactToolkit($this->store, $this->sessionId, readOnly: true);

    $names = array_map(
        fn($t) => $t->toFunctionSchema()['function']['name'],
        $toolkit->tools(),
    );

    expect($names)->toBe([
        'artifact_create',
        'artifact_update',
        'artifact_get',
        'artifact_list',
        'artifact_stage',
    ]);
});

test('artifact_delete tool deletes artifact', function () {
    $id = $this->store->create($this->sessionId, 'Deletable', 'content');

    $toolkit = new ArtifactToolkit($this->store, $this->sessionId);
    $deleteTool = toolFromToolkit($toolkit, 'artifact_delete');

    $result = $deleteTool->execute(['id' => $id]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $data = json_decode($result->content, true);
    expect($data['deleted'])->toBeTrue();
    expect($this->store->get($id))->toBeNull();
});

test('artifact_delete tool errors on missing artifact', function () {
    $toolkit = new ArtifactToolkit($this->store, $this->sessionId);
    $deleteTool = toolFromToolkit($toolkit, 'artifact_delete');

    $result = $deleteTool->execute(['id' => 'nonexistent']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

test('artifact_bulk_stage updates artifacts by explicit ids and returns structured json hints', function () {
    $id1 = $this->store->create($this->sessionId, 'One', 'content');
    $id2 = $this->store->create($this->sessionId, 'Two', 'content');

    $tool = toolFromToolkit($this->toolkit, 'artifact_stage');

    $result = $tool->execute([
        'ids' => [$id1, $id2],
        'stage' => 'review',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->mimeType)->toBe('application/json');
    expect($result->displayHint)->toBe('structured-json');
    $data = json_decode($result->content, true);
    expect($data['updated'])->toBe(2);
    expect($this->store->get($id1)['stage'])->toBe('review');
    expect($this->store->get($id2)['stage'])->toBe('review');
});

test('artifact_bulk_stage accepts native array ids', function () {
    $id1 = $this->store->create($this->sessionId, 'One', 'content');
    $id2 = $this->store->create($this->sessionId, 'Two', 'content');

    $tool = toolFromToolkit($this->toolkit, 'artifact_stage');

    $result = $tool->execute([
        'ids' => [$id1, $id2],
        'stage' => 'review',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $data = json_decode($result->content, true);
    expect($data['updated'])->toBe(2);
    expect($this->store->get($id1)['stage'])->toBe('review');
    expect($this->store->get($id2)['stage'])->toBe('review');
});

test('artifact_bulk_delete deletes artifacts selected by filter', function () {
    $this->store->create($this->sessionId, 'Draft One', 'content', type: 'code');
    $this->store->create($this->sessionId, 'Draft Two', 'content', type: 'code');
    $keepId = $this->store->create($this->sessionId, 'Document', 'content', type: 'document');

    $tool = toolFromToolkit($this->toolkit, 'artifact_delete');

    $result = $tool->execute([
        'type' => 'code',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $data = json_decode($result->content, true);
    expect($data['deleted'])->toBe(2);
    expect($this->store->list($this->sessionId))->toHaveCount(1);
    expect($this->store->get($keepId))->not->toBeNull();
});

test('artifact_bulk_delete accepts native array ids', function () {
    $id1 = $this->store->create($this->sessionId, 'Delete One', 'content');
    $id2 = $this->store->create($this->sessionId, 'Delete Two', 'content');

    $tool = toolFromToolkit($this->toolkit, 'artifact_delete');

    $result = $tool->execute([
        'ids' => [$id1, $id2],
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $data = json_decode($result->content, true);
    expect($data['deleted'])->toBe(2);
    expect($this->store->get($id1))->toBeNull();
    expect($this->store->get($id2))->toBeNull();
});
