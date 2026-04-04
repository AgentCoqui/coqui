<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\ProjectStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Storage\TodoStore;
use CoquiBot\Coqui\Toolkit\SprintToolkit;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-sprint-toolkit-test-' . bin2hex(random_bytes(8)) . '.db';
    $this->workspacePath = sys_get_temp_dir() . '/coqui-sprint-toolkit-ws-' . bin2hex(random_bytes(8));
    mkdir($this->workspacePath . '/projects', 0755, true);

    $this->storage = new SessionStorage($this->dbPath);
    $this->sessionId = $this->storage->createSession('orchestrator', 'test/model');
    $pdo = $this->storage->getPdo();
    $this->artifactStore = new ArtifactStore($pdo);
    $this->todoStore = new TodoStore($pdo);
    $this->projectStore = new ProjectStore($pdo);
    $this->toolkit = new SprintToolkit(
        projectStore: $this->projectStore,
        todoStore: $this->todoStore,
        sessionId: $this->sessionId,
        workspacePath: $this->workspacePath,
        activeProjectId: null,
        storage: $this->storage,
    );
});

afterEach(function () {
    if (is_dir($this->workspacePath . '/projects')) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->workspacePath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->workspacePath);
    }

    if (file_exists($this->dbPath)) {
        unlink($this->dbPath);
    }
});

test('toolkit exposes project and sprint delete tools', function () {
    $names = array_map(
        fn($tool) => $tool->toFunctionSchema()['function']['name'],
        $this->toolkit->tools(),
    );

    expect($names)->toContain('project_delete');
    expect($names)->toContain('sprint_delete');
});

test('project_delete clears active project references', function () {
    $projectId = $this->projectStore->createProject('Alpha', 'alpha');
    $this->storage->setActiveProject($this->sessionId, $projectId);

    $tool = array_values(array_filter(
        $this->toolkit->tools(),
        fn($candidate) => $candidate->toFunctionSchema()['function']['name'] === 'project_delete',
    ))[0];

    $result = $tool->execute(['id' => 'alpha']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($this->projectStore->getProject($projectId))->toBeNull();
    expect($this->storage->getActiveProjectId($this->sessionId))->toBeNull();
});

test('project_delete optionally removes project directory', function () {
    $projectId = $this->projectStore->createProject('Beta', 'beta');
    $directory = $this->projectStore->getProjectDirectory($projectId);
    mkdir($this->workspacePath . '/projects/' . $directory, 0755, true);
    file_put_contents($this->workspacePath . '/projects/' . $directory . '/note.txt', 'cleanup');

    $tool = array_values(array_filter(
        $this->toolkit->tools(),
        fn($candidate) => $candidate->toFunctionSchema()['function']['name'] === 'project_delete',
    ))[0];

    $result = $tool->execute(['id' => 'beta', 'delete_directory' => true]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect(is_dir($this->workspacePath . '/projects/' . $directory))->toBeFalse();
});

test('sprint_delete removes all sprints for a project', function () {
    $projectId = $this->projectStore->createProject('Gamma', 'gamma');
    $this->projectStore->createSprint($projectId, 'Sprint One');
    $this->projectStore->createSprint($projectId, 'Sprint Two');

    $tool = array_values(array_filter(
        $this->toolkit->tools(),
        fn($candidate) => $candidate->toFunctionSchema()['function']['name'] === 'sprint_delete',
    ))[0];

    $result = $tool->execute(['id' => 'all', 'project_id' => 'gamma']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($this->projectStore->listSprints($projectId))->toBeEmpty();
});