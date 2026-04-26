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

test('project_switch persists and clears the active project for the session', function () {
    $projectId = $this->projectStore->createProject('Delta', 'delta');

    $tool = array_values(array_filter(
        $this->toolkit->tools(),
        fn($candidate) => $candidate->toFunctionSchema()['function']['name'] === 'project_switch',
    ))[0];

    $activate = $tool->execute(['slug' => 'delta']);

    expect($activate->status)->toBe(ToolResultStatus::Success);
    expect($this->storage->getActiveProjectId($this->sessionId))->toBe($projectId);

    $clear = $tool->execute(['slug' => 'clear']);

    expect($clear->status)->toBe(ToolResultStatus::Success);
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

test('sprint_create accepts native array acceptance criteria', function () {
    $projectId = $this->projectStore->createProject('Omega', 'omega');

    $tool = array_values(array_filter(
        $this->toolkit->tools(),
        fn($candidate) => $candidate->toFunctionSchema()['function']['name'] === 'sprint_create',
    ))[0];

    $result = $tool->execute([
        'project_id' => $projectId,
        'title' => 'Sprint Criteria',
        'acceptance_criteria' => ['first passes', 'second passes'],
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $data = json_decode($result->content, true);
    $sprint = $this->projectStore->getSprint($data['id']);
    expect($sprint['acceptance_criteria'])->toBe('["first passes","second passes"]');
});

test('sprint_update accepts native array acceptance criteria', function () {
    $projectId = $this->projectStore->createProject('Sigma', 'sigma');
    $sprintId = $this->projectStore->createSprint($projectId, 'Existing Sprint');

    $tool = array_values(array_filter(
        $this->toolkit->tools(),
        fn($candidate) => $candidate->toFunctionSchema()['function']['name'] === 'sprint_update',
    ))[0];

    $result = $tool->execute([
        'id' => $sprintId,
        'acceptance_criteria' => ['updated one', 'updated two'],
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($this->projectStore->getSprint($sprintId)['acceptance_criteria'])->toBe('["updated one","updated two"]');
});