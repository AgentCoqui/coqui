<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Toolkit\BackgroundTaskToolkit;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-bgtask-test-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
    $this->parentSessionId = $this->storage->createSession('orchestrator', 'test/model');
    $this->config = OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'ollama/qwen3:latest'],
                'roles' => ['coder' => 'anthropic/claude'],
            ],
        ],
    ]);
    $this->roleResolver = new RoleResolver($this->config);
    $this->healthyBackgroundHealthCheck = static fn(): array => ['ok' => true, 'error' => null];
    $this->toolkit = new BackgroundTaskToolkit(
        storage: $this->storage,
        parentSessionId: $this->parentSessionId,
        roleResolver: $this->roleResolver,
        healthCheck: $this->healthyBackgroundHealthCheck,
    );
});

afterEach(function () {
    if (file_exists($this->dbPath)) {
        unlink($this->dbPath);
    }
});

// --- Tool structure ---

test('provides exactly 5 tools', function () {
    expect($this->toolkit->tools())->toHaveCount(5);
});

test('tool names are correct', function () {
    $names = array_map(
        fn($t) => $t->toFunctionSchema()['function']['name'],
        $this->toolkit->tools(),
    );

    expect($names)->toBe([
        'start_background_task',
        'start_background_tool',
        'task_status',
        'list_tasks',
        'cancel_task',
    ]);
});

test('all tools produce valid function schemas', function () {
    foreach ($this->toolkit->tools() as $tool) {
        $schema = $tool->toFunctionSchema();

        expect($schema)->toHaveKey('type');
        expect($schema['type'])->toBe('function');
        expect($schema['function'])->toHaveKey('name');
        expect($schema['function'])->toHaveKey('description');
        expect($schema['function'])->toHaveKey('parameters');
        expect($schema['function']['parameters'])->toHaveKey('type');
        expect($schema['function']['parameters']['type'])->toBe('object');
    }
});

test('guidelines returns non-empty string with key content', function () {
    $guidelines = $this->toolkit->guidelines();

    expect($guidelines)->toBeString();
    expect($guidelines)->not->toBeEmpty();
    expect($guidelines)->toContain('Background Tasks');
    expect($guidelines)->toContain('start_background_tool');
});

// --- maxIterationsCap clamping ---

test('maxIterationsCap clamps zero to 1', function () {
    $toolkit = new BackgroundTaskToolkit(
        storage: $this->storage,
        parentSessionId: $this->parentSessionId,
        maxIterationsCap: 0,
    );

    $tools = $toolkit->tools();
    $startTaskTool = toolFromToolkit($toolkit, 'start_background_task');
    $schema = $startTaskTool->toFunctionSchema();

    // The max_iterations parameter maximum should be clamped to 1
    $maxIterationsParam = $schema['function']['parameters']['properties']['max_iterations'] ?? null;
    expect($maxIterationsParam)->not->toBeNull();
    expect((int) $maxIterationsParam['maximum'])->toBe(1);
});

test('maxIterationsCap clamps negative to 1', function () {
    $toolkit = new BackgroundTaskToolkit(
        storage: $this->storage,
        parentSessionId: $this->parentSessionId,
        maxIterationsCap: -5,
    );

    $tools = $toolkit->tools();
    $startTaskTool = toolFromToolkit($toolkit, 'start_background_task');
    $schema = $startTaskTool->toFunctionSchema();

    $maxIterationsParam = $schema['function']['parameters']['properties']['max_iterations'] ?? null;
    expect($maxIterationsParam)->not->toBeNull();
    expect((int) $maxIterationsParam['maximum'])->toBe(1);
});

// --- start_background_task validation ---

test('start_background_task returns error for empty prompt', function () {
    $tool = toolFromToolkit($this->toolkit, 'start_background_task');

    $result = $tool->execute(['prompt' => '', 'title' => 'Test']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('prompt');
});

test('start_background_task returns error for empty title', function () {
    $tool = toolFromToolkit($this->toolkit, 'start_background_task');

    $result = $tool->execute(['prompt' => 'Do something', 'title' => '']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('title');
});

test('start_background_task creates task successfully', function () {
    $tool = toolFromToolkit($this->toolkit, 'start_background_task');

    $result = $tool->execute([
        'prompt' => 'Research PHP 8.4 features',
        'title' => 'PHP research',
        'role' => 'orchestrator',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $data = json_decode($result->content, true);
    expect($data)->toHaveKeys(['task_id', 'session_id', 'status', 'title']);
    expect($data['status'])->toBe('pending');
    expect($data['title'])->toBe('PHP research');
});

test('start_background_task refuses to queue work when API worker is unhealthy', function () {
    $toolkit = new BackgroundTaskToolkit(
        storage: $this->storage,
        parentSessionId: $this->parentSessionId,
        roleResolver: $this->roleResolver,
        healthCheck: static fn(): array => ['ok' => false, 'error' => 'API background task manager is not dispatch-ready.'],
    );
    $tool = toolFromToolkit($toolkit, 'start_background_task');

    $result = $tool->execute([
        'prompt' => 'Research PHP 8.4 features',
        'title' => 'PHP research',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('dispatch-ready');
});

// --- start_background_tool validation ---

test('start_background_tool returns error for empty tool_name', function () {
    $tool = toolFromToolkit($this->toolkit, 'start_background_tool');

    $result = $tool->execute([
        'tool_name' => '',
        'arguments' => '{}',
        'title' => 'Test',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('tool_name');
});

test('start_background_tool returns error for empty arguments', function () {
    $tool = toolFromToolkit($this->toolkit, 'start_background_tool');

    $result = $tool->execute([
        'tool_name' => 'web_search',
        'arguments' => '',
        'title' => 'Test',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('arguments');
});

test('start_background_tool returns error for invalid JSON arguments', function () {
    $tool = toolFromToolkit($this->toolkit, 'start_background_tool');

    $result = $tool->execute([
        'tool_name' => 'web_search',
        'arguments' => 'not-json',
        'title' => 'Test',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('Invalid');
});

test('start_background_tool returns error for empty title', function () {
    $tool = toolFromToolkit($this->toolkit, 'start_background_tool');

    $result = $tool->execute([
        'tool_name' => 'web_search',
        'arguments' => '{"query": "test"}',
        'title' => '',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('title');
});

test('start_background_tool creates tool task successfully', function () {
    $tool = toolFromToolkit($this->toolkit, 'start_background_tool');

    $result = $tool->execute([
        'tool_name' => 'web_search',
        'arguments' => '{"query": "PHP 8.4"}',
        'title' => 'Search PHP',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $data = json_decode($result->content, true);
    expect($data)->toHaveKeys(['task_id', 'session_id', 'status', 'tool_name', 'title']);
    expect($data['status'])->toBe('pending');
    expect($data['tool_name'])->toBe('web_search');
});

// --- task_status ---

test('task_status returns error for non-existent task', function () {
    $tool = toolFromToolkit($this->toolkit, 'task_status');

    $result = $tool->execute(['task_id' => 'nonexistent-id']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('not found');
});

test('task_status returns details for existing task', function () {
    // Create a task first
    $startTool = toolFromToolkit($this->toolkit, 'start_background_task');
    $createResult = $startTool->execute([
        'prompt' => 'Test task',
        'title' => 'Test',
    ]);
    $taskId = json_decode($createResult->content, true)['task_id'];

    // Check status
    $statusTool = toolFromToolkit($this->toolkit, 'task_status');
    $result = $statusTool->execute(['task_id' => $taskId]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('pending');
});

test('task_status includes structured metadata when present', function () {
    $taskId = $this->storage->createTask(
        sessionId: $this->parentSessionId,
        prompt: 'Inspect provenance',
        role: 'orchestrator',
        title: 'Inspect provenance',
        metadata: ['workflow_phase' => 'review', 'intent' => 'code_review'],
    );

    $statusTool = toolFromToolkit($this->toolkit, 'task_status');
    $result = $statusTool->execute(['task_id' => $taskId]);
    $decoded = json_decode($result->content, true);

    expect($decoded['metadata'])->toBe([
        'workflow_phase' => 'review',
        'intent' => 'code_review',
    ]);
});

// --- list_tasks ---

test('list_tasks returns empty list when no tasks', function () {
    $tool = toolFromToolkit($this->toolkit, 'list_tasks');

    $result = $tool->execute([]);

    expect($result->status)->toBe(ToolResultStatus::Success);
});

test('list_tasks includes decoded metadata when present', function () {
    $this->storage->createTask(
        sessionId: $this->parentSessionId,
        prompt: 'Loop stage task',
        role: 'coder',
        title: 'Loop stage task',
        metadata: ['loop_id' => 'loop-123', 'stage_index' => 1],
    );

    $tool = toolFromToolkit($this->toolkit, 'list_tasks');
    $result = $tool->execute([]);
    $decoded = json_decode($result->content, true);

    expect($decoded['tasks'][0]['metadata'])->toBe([
        'loop_id' => 'loop-123',
        'stage_index' => 1,
    ]);
});

test('list_tasks returns created tasks', function () {
    // Create a task
    $startTool = toolFromToolkit($this->toolkit, 'start_background_task');
    $startTool->execute([
        'prompt' => 'Test task',
        'title' => 'My task',
    ]);

    $tool = toolFromToolkit($this->toolkit, 'list_tasks');
    $result = $tool->execute([]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('My task');
});

// --- cancel_task ---

test('cancel_task returns error for non-existent task', function () {
    $tool = toolFromToolkit($this->toolkit, 'cancel_task');

    $result = $tool->execute(['task_id' => 'nonexistent-id']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});
