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

test('provides exactly 5 tools', function () {
    expect($this->toolkit->tools())->toHaveCount(5);
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
    $tool = $this->toolkit->tools()[0];

    $result = $tool->execute([
        'title' => 'My Script',
        'content' => 'echo "hi"',
        'type' => 'code',
        'language' => 'php',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);

    $data = json_decode($result->content, true);
    expect($data['title'])->toBe('My Script');
    expect($data['version'])->toBe(1);
    expect($data['stage'])->toBe('draft');
});

test('artifact_create tool rejects empty title', function () {
    $tool = $this->toolkit->tools()[0];

    $result = $tool->execute(['title' => '', 'content' => 'x']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('Title is required');
});

test('artifact_update tool updates content', function () {
    $id = $this->store->create($this->sessionId, 'Doc', 'v1');

    $tool = $this->toolkit->tools()[1]; // artifact_update

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
    $tool = $this->toolkit->tools()[1];

    $result = $tool->execute(['id' => 'nonexistent', 'content' => 'x']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

test('artifact_get tool retrieves artifact', function () {
    $id = $this->store->create($this->sessionId, 'Fetched', 'body');

    $tool = $this->toolkit->tools()[2]; // artifact_get

    $result = $tool->execute(['id' => $id]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $data = json_decode($result->content, true);
    expect($data['title'])->toBe('Fetched');
    expect($data['content'])->toBe('body');
});

test('artifact_get tool retrieves specific version', function () {
    $id = $this->store->create($this->sessionId, 'Versioned', 'original');
    $this->store->update($id, 'updated');

    $tool = $this->toolkit->tools()[2];

    $result = $tool->execute(['id' => $id, 'version' => 1]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $data = json_decode($result->content, true);
    expect($data['content'])->toBe('original');
    expect($data['version'])->toBe(1);
});

test('artifact_list tool returns artifacts', function () {
    $this->store->create($this->sessionId, 'A', 'content a');
    $this->store->create($this->sessionId, 'B', 'content b');

    $tool = $this->toolkit->tools()[3]; // artifact_list

    $result = $tool->execute([]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $data = json_decode($result->content, true);
    expect($data['count'])->toBe(2);
});

test('artifact_list tool returns message when empty', function () {
    $tool = $this->toolkit->tools()[3];

    $result = $tool->execute([]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('No artifacts found');
});

test('artifact_stage tool transitions stage', function () {
    $id = $this->store->create($this->sessionId, 'Staged', 'content');

    $tool = $this->toolkit->tools()[4]; // artifact_stage

    $result = $tool->execute(['id' => $id, 'stage' => 'review']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $data = json_decode($result->content, true);
    expect($data['previous_stage'])->toBe('draft');
    expect($data['new_stage'])->toBe('review');
});

test('artifact_stage tool errors on missing artifact', function () {
    $tool = $this->toolkit->tools()[4];

    $result = $tool->execute(['id' => 'nonexistent', 'stage' => 'final']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});
