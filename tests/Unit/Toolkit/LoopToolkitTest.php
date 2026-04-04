<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CoquiBot\Coqui\Config\LoopDiscovery;
use CoquiBot\Coqui\Contract\LoopDefinition;
use CoquiBot\Coqui\Contract\TerminationCondition;
use CoquiBot\Coqui\Contract\TerminationType;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Toolkit\LoopToolkit;

beforeEach(function () {
    // Database setup
    $this->dbPath = sys_get_temp_dir() . '/coqui-loop-toolkit-test-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
    $this->sessionId = $this->storage->createSession('orchestrator', 'test/model');
    $this->loopStore = new LoopStore($this->storage->getPdo());

    // Temp workspace directory with loops subdirectory
    $this->workspacePath = sys_get_temp_dir() . '/coqui-toolkit-ws-' . bin2hex(random_bytes(8));
    $this->loopsDir = $this->workspacePath . '/loops';
    mkdir($this->loopsDir, 0755, true);

    file_put_contents($this->loopsDir . '/harness.json', json_encode([
        'name' => 'harness',
        'description' => 'Generator-evaluator pattern',
        'roles' => [
            ['role' => 'plan', 'prompt' => 'Create a plan.'],
            ['role' => 'coder', 'prompt' => 'Implement the plan.'],
            ['role' => 'reviewer', 'prompt' => 'Review the implementation.'],
        ],
        'termination_condition' => [
            'type' => 'evaluation_bound',
            'value' => 'APPROVED',
        ],
    ]));

    file_put_contents($this->loopsDir . '/research.json', json_encode([
        'name' => 'research',
        'description' => 'Research-driven implementation',
        'roles' => [
            ['role' => 'explorer', 'prompt' => 'Explore the codebase.'],
            ['role' => 'coder', 'prompt' => 'Implement findings.'],
        ],
        'termination_condition' => [
            'type' => 'iteration_bound',
            'value' => 5,
        ],
    ]));

    $this->loopDiscovery = new LoopDiscovery($this->workspacePath);
    $this->toolkit = new LoopToolkit($this->loopStore, $this->loopDiscovery);
});

afterEach(function () {
    unset($this->toolkit, $this->loopStore, $this->loopDiscovery, $this->storage);

    if (file_exists($this->dbPath)) {
        @unlink($this->dbPath);
    }

    // Clean up temp loops dir
    $files = glob($this->loopsDir . '/*.json');
    if ($files !== false) {
        foreach ($files as $f) {
            @unlink($f);
        }
    }
    @rmdir($this->loopsDir);
    @rmdir($this->workspacePath);
});

// ─── tools() ───

test('tools() returns 7 tools', function () {
    $tools = $this->toolkit->tools();

    expect($tools)->toHaveCount(7);

    $names = array_map(fn($t) => $t->name(), $tools);
    expect($names)->toContain(
        'loop_start',
        'loop_list',
        'loop_status',
        'loop_pause',
        'loop_resume',
        'loop_stop',
        'loop_definitions',
    );
});

// ─── guidelines() ───

test('guidelines() shows no active loops and available definitions', function () {
    $guidelines = $this->toolkit->guidelines();

    expect($guidelines)
        ->toContain('No active loops')
        ->toContain('harness')
        ->toContain('research');
});

test('guidelines() shows active loop count and details', function () {
    // Create a running loop
    $loopId = $this->loopStore->createLoop(
        definitionName: 'harness',
        goal: 'Build the widget feature',
        configuration: ['type' => 'evaluation_bound', 'value' => 'APPROVED'],
        sessionId: $this->sessionId,
        projectId: 'proj-1',
        maxIterations: 10,
    );
    $this->loopStore->updateLoopStatus($loopId, 'running');
    $this->loopStore->updateLoopProgress($loopId, 2, 1);

    $guidelines = $this->toolkit->guidelines();

    expect($guidelines)
        ->toContain('Active loops: 1')
        ->toContain('harness')
        ->toContain('Build the widget feature');
});

// ─── loop_start ───

test('loop_start validates required parameters', function () {
    $tool = $this->toolkit->tools()[0];
    expect($tool->name())->toBe('loop_start');

    $result = $tool->execute(['definition' => '', 'goal' => '']);
    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('required');
});

test('loop_start rejects missing definition', function () {
    $tool = $this->toolkit->tools()[0];

    $result = $tool->execute(['definition' => '', 'goal' => 'Do something']);
    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('required');
});

test('loop_start rejects nonexistent definition', function () {
    $tool = $this->toolkit->tools()[0];

    $result = $tool->execute(['definition' => 'nonexistent', 'goal' => 'Do something']);
    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('not found');
    expect($result->content)->toContain('harness');
    expect($result->content)->toContain('research');
});

test('loop_start returns action payload for valid definition', function () {
    $tool = $this->toolkit->tools()[0];

    $result = $tool->execute(['definition' => 'harness', 'goal' => 'Build feature X']);
    expect($result->status)->toBe(ToolResultStatus::Success);

    $decoded = json_decode($result->content, true);
    expect($decoded)
        ->toBeArray()
        ->and($decoded['action'])->toBe('start_loop')
        ->and($decoded['definition'])->toBe('harness')
        ->and($decoded['goal'])->toBe('Build feature X')
        ->and($decoded['roles'])->toBe(['plan', 'coder', 'reviewer'])
        ->and($decoded['termination'])->toBe('evaluation_bound')
        ->and($decoded['message'])->toContain('ready to start');
});

test('loop_start passes max_iterations override', function () {
    $tool = $this->toolkit->tools()[0];

    $result = $tool->execute(['definition' => 'harness', 'goal' => 'Test', 'max_iterations' => 20]);
    $decoded = json_decode($result->content, true);

    expect($decoded['max_iterations'])->toBe(20);
});

test('loop_start passes null max_iterations when not provided', function () {
    $tool = $this->toolkit->tools()[0];

    $result = $tool->execute(['definition' => 'harness', 'goal' => 'Test']);
    $decoded = json_decode($result->content, true);

    expect($decoded['max_iterations'])->toBeNull();
});

// ─── loop_list ───

test('loop_list returns empty message when no loops exist', function () {
    $tools = $this->toolkit->tools();
    $listTool = $tools[1];
    expect($listTool->name())->toBe('loop_list');

    $result = $listTool->execute([]);
    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('No loops found');
});

test('loop_list returns all loops', function () {
    $listTool = $this->toolkit->tools()[1];

    $id1 = $this->loopStore->createLoop('harness', 'Goal A', [], $this->sessionId, 'p1', 10);
    $this->loopStore->updateLoopStatus($id1, 'running');
    $id2 = $this->loopStore->createLoop('research', 'Goal B', [], $this->sessionId, 'p2', 5);
    $this->loopStore->updateLoopStatus($id2, 'completed');

    $result = $listTool->execute([]);
    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)
        ->toContain($id1)
        ->toContain('running')
        ->toContain('Goal A')
        ->toContain($id2)
        ->toContain('completed')
        ->toContain('Goal B');
});

test('loop_list filters by status', function () {
    $listTool = $this->toolkit->tools()[1];

    $id1 = $this->loopStore->createLoop('harness', 'Running loop', [], $this->sessionId, 'p1', 10);
    $this->loopStore->updateLoopStatus($id1, 'running');
    $id2 = $this->loopStore->createLoop('research', 'Done loop', [], $this->sessionId, 'p2', 5);
    $this->loopStore->updateLoopStatus($id2, 'completed');

    $result = $listTool->execute(['status' => 'running']);
    expect($result->content)
        ->toContain('Running loop')
        ->not->toContain('Done loop');
});

// ─── loop_status ───

test('loop_status returns error for nonexistent loop', function () {
    $statusTool = $this->toolkit->tools()[2];
    expect($statusTool->name())->toBe('loop_status');

    $result = $statusTool->execute(['id' => 'nonexistent']);
    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('not found');
});

test('loop_status returns structured JSON for valid loop', function () {
    $statusTool = $this->toolkit->tools()[2];

    $loopId = $this->loopStore->createLoop('harness', 'Test goal', [
        'type' => 'evaluation_bound',
        'value' => 'APPROVED',
    ], $this->sessionId, 'p1', 10);
    $this->loopStore->updateLoopStatus($loopId, 'running');

    $result = $statusTool->execute(['id' => $loopId]);
    expect($result->status)->toBe(ToolResultStatus::Success);

    $decoded = json_decode($result->content, true);
    expect($decoded)
        ->toBeArray()
        ->and($decoded['id'])->toBe($loopId)
        ->and($decoded['definition'])->toBe('harness')
        ->and($decoded['status'])->toBe('running')
        ->and($decoded['goal'])->toBe('Test goal')
        ->and($decoded['max_iterations'])->toBe(10);
});

test('loop_status includes iteration and stage details', function () {
    $statusTool = $this->toolkit->tools()[2];

    $loopId = $this->loopStore->createLoop('harness', 'Test goal', [], $this->sessionId, 'p1', 10);
    $this->loopStore->updateLoopStatus($loopId, 'running');

    $iterId = $this->loopStore->createIteration($loopId, 1, 'sprint-1');
    $this->loopStore->updateIterationStatus($iterId, 'running');

    $stageId = $this->loopStore->createStage($iterId, 0, 'plan');
    $this->loopStore->updateStage($stageId, 'completed', resultSummary: 'Stage result summary');

    $result = $statusTool->execute(['id' => $loopId]);
    $decoded = json_decode($result->content, true);

    expect($decoded['current_iteration_status'])->toBe('running');
    expect($decoded['stages'])->toHaveCount(1);
    expect($decoded['stages'][0]['role'])->toBe('plan');
    expect($decoded['stages'][0]['status'])->toBe('completed');
    expect($decoded['stages'][0]['summary'])->toBe('Stage result summary');
});

// ─── loop_pause ───

test('loop_pause pauses a running loop', function () {
    $pauseTool = $this->toolkit->tools()[3];
    expect($pauseTool->name())->toBe('loop_pause');

    $loopId = $this->loopStore->createLoop('harness', 'Test', [], $this->sessionId, 'p1', 10);
    $this->loopStore->updateLoopStatus($loopId, 'running');

    $result = $pauseTool->execute(['id' => $loopId]);
    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('paused');

    $loop = $this->loopStore->getLoop($loopId);
    expect($loop['status'])->toBe('paused');
});

test('loop_pause rejects nonexistent loop', function () {
    $pauseTool = $this->toolkit->tools()[3];

    $result = $pauseTool->execute(['id' => 'bad-id']);
    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('not found');
});

test('loop_pause rejects non-running loop', function () {
    $pauseTool = $this->toolkit->tools()[3];

    $loopId = $this->loopStore->createLoop('harness', 'Test', [], $this->sessionId, 'p1', 10);
    $this->loopStore->updateLoopStatus($loopId, 'completed');

    $result = $pauseTool->execute(['id' => $loopId]);
    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('Cannot pause');
    expect($result->content)->toContain('completed');
});

test('loop_pause pauses all running loops', function () {
    $pauseTool = $this->toolkit->tools()[3];

    $runningA = $this->loopStore->createLoop('harness', 'First', [], $this->sessionId, 'p1', 10);
    $runningB = $this->loopStore->createLoop('harness', 'Second', [], $this->sessionId, 'p1', 10);
    $done = $this->loopStore->createLoop('harness', 'Done', [], $this->sessionId, 'p1', 10);
    $this->loopStore->updateLoopStatus($runningA, 'running');
    $this->loopStore->updateLoopStatus($runningB, 'running');
    $this->loopStore->updateLoopStatus($done, 'completed');

    $result = $pauseTool->execute(['id' => 'all']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('Paused 2 loop(s)');
    expect($this->loopStore->getLoop($runningA)['status'])->toBe('paused');
    expect($this->loopStore->getLoop($runningB)['status'])->toBe('paused');
    expect($this->loopStore->getLoop($done)['status'])->toBe('completed');
});

// ─── loop_resume ───

test('loop_resume resumes a paused loop', function () {
    $resumeTool = $this->toolkit->tools()[4];
    expect($resumeTool->name())->toBe('loop_resume');

    $loopId = $this->loopStore->createLoop('harness', 'Test', [], $this->sessionId, 'p1', 10);
    $this->loopStore->updateLoopStatus($loopId, 'paused');

    $result = $resumeTool->execute(['id' => $loopId]);
    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('resumed');

    $loop = $this->loopStore->getLoop($loopId);
    expect($loop['status'])->toBe('running');
});

test('loop_resume rejects non-paused loop', function () {
    $resumeTool = $this->toolkit->tools()[4];

    $loopId = $this->loopStore->createLoop('harness', 'Test', [], $this->sessionId, 'p1', 10);
    $this->loopStore->updateLoopStatus($loopId, 'running');

    $result = $resumeTool->execute(['id' => $loopId]);
    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('Cannot resume');
    expect($result->content)->toContain('running');
});

test('loop_resume rejects nonexistent loop', function () {
    $resumeTool = $this->toolkit->tools()[4];

    $result = $resumeTool->execute(['id' => 'missing']);
    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('not found');
});

test('loop_resume resumes all paused loops', function () {
    $resumeTool = $this->toolkit->tools()[4];

    $pausedA = $this->loopStore->createLoop('harness', 'Paused A', [], $this->sessionId, 'p1', 10);
    $pausedB = $this->loopStore->createLoop('harness', 'Paused B', [], $this->sessionId, 'p1', 10);
    $running = $this->loopStore->createLoop('harness', 'Running', [], $this->sessionId, 'p1', 10);
    $this->loopStore->updateLoopStatus($pausedA, 'paused');
    $this->loopStore->updateLoopStatus($pausedB, 'paused');
    $this->loopStore->updateLoopStatus($running, 'running');

    $result = $resumeTool->execute(['id' => 'all']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('Resumed 2 loop(s)');
    expect($this->loopStore->getLoop($pausedA)['status'])->toBe('running');
    expect($this->loopStore->getLoop($pausedB)['status'])->toBe('running');
    expect($this->loopStore->getLoop($running)['status'])->toBe('running');
});

// ─── loop_stop ───

test('loop_stop cancels a running loop', function () {
    $stopTool = $this->toolkit->tools()[5];
    expect($stopTool->name())->toBe('loop_stop');

    $loopId = $this->loopStore->createLoop('harness', 'Test', [], $this->sessionId, 'p1', 10);
    $this->loopStore->updateLoopStatus($loopId, 'running');

    $result = $stopTool->execute(['id' => $loopId]);
    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('cancelled');

    $loop = $this->loopStore->getLoop($loopId);
    expect($loop['status'])->toBe('cancelled');
});

test('loop_stop cancels a paused loop', function () {
    $stopTool = $this->toolkit->tools()[5];

    $loopId = $this->loopStore->createLoop('harness', 'Test', [], $this->sessionId, 'p1', 10);
    $this->loopStore->updateLoopStatus($loopId, 'paused');

    $result = $stopTool->execute(['id' => $loopId]);
    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('cancelled');
});

test('loop_stop rejects completed loop', function () {
    $stopTool = $this->toolkit->tools()[5];

    $loopId = $this->loopStore->createLoop('harness', 'Test', [], $this->sessionId, 'p1', 10);
    $this->loopStore->updateLoopStatus($loopId, 'completed');

    $result = $stopTool->execute(['id' => $loopId]);
    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('Cannot stop');
});

test('loop_stop rejects nonexistent loop', function () {
    $stopTool = $this->toolkit->tools()[5];

    $result = $stopTool->execute(['id' => 'nope']);
    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('not found');
});

test('loop_stop cancels all active loops', function () {
    $stopTool = $this->toolkit->tools()[5];

    $running = $this->loopStore->createLoop('harness', 'Running', [], $this->sessionId, 'p1', 10);
    $paused = $this->loopStore->createLoop('harness', 'Paused', [], $this->sessionId, 'p1', 10);
    $completed = $this->loopStore->createLoop('harness', 'Completed', [], $this->sessionId, 'p1', 10);
    $this->loopStore->updateLoopStatus($running, 'running');
    $this->loopStore->updateLoopStatus($paused, 'paused');
    $this->loopStore->updateLoopStatus($completed, 'completed');

    $result = $stopTool->execute(['id' => 'all']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('Cancelled 2 loop(s)');
    expect($this->loopStore->getLoop($running)['status'])->toBe('cancelled');
    expect($this->loopStore->getLoop($paused)['status'])->toBe('cancelled');
    expect($this->loopStore->getLoop($completed)['status'])->toBe('completed');
});

// ─── loop_definitions ───

test('loop_definitions lists all available definitions', function () {
    $defsTool = $this->toolkit->tools()[6];
    expect($defsTool->name())->toBe('loop_definitions');

    $result = $defsTool->execute([]);
    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)
        ->toContain('harness')
        ->toContain('Generator-evaluator pattern')
        ->toContain('plan → coder → reviewer')
        ->toContain('evaluation_bound')
        ->toContain('research')
        ->toContain('Research-driven implementation')
        ->toContain('explorer → coder')
        ->toContain('iteration_bound');
});

test('loop_definitions returns message when no definitions exist', function () {
    // Create a toolkit with empty workspace (no loops subdir)
    $emptyDir = sys_get_temp_dir() . '/coqui-empty-ws-' . bin2hex(random_bytes(8));
    mkdir($emptyDir, 0755, true);

    $emptyDiscovery = new LoopDiscovery($emptyDir);
    $toolkit = new LoopToolkit($this->loopStore, $emptyDiscovery);
    $defsTool = $toolkit->tools()[6];

    $result = $defsTool->execute([]);
    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('No loop definitions found');

    @rmdir($emptyDir);
});

// ──────────────────────────────────────────────
//  Parameterized Templates
// ──────────────────────────────────────────────

test('loop_start passes parameters to executor', function () {
    // Create a parameterized definition
    file_put_contents($this->loopsDir . '/parameterized.json', json_encode([
        'name' => 'parameterized',
        'description' => 'Test parameterized loop',
        'roles' => [['role' => 'coder', 'prompt' => 'Work on {{topic}}.']],
        'termination_condition' => ['type' => 'manual'],
        'parameters' => [
            ['name' => 'topic', 'description' => 'Subject', 'required' => true],
        ],
    ]));

    $this->loopDiscovery->invalidateCache();

    // Build toolkit with executor
    $pdo = $this->storage->getPdo();
    $projectStore = new \CoquiBot\Coqui\Storage\ProjectStore($pdo);
    $artifactStore = new \CoquiBot\Coqui\Storage\ArtifactStore($pdo);
    $config = \CoquiBot\Coqui\Config\OpenClawConfig::fromArray([
        'agents' => ['defaults' => ['model' => ['primary' => 'test/model']]],
    ]);
    $roleResolver = new \CoquiBot\Coqui\Config\RoleResolver($config);
    $wsPath = sys_get_temp_dir() . '/coqui-param-ws-' . bin2hex(random_bytes(4));
    mkdir($wsPath . '/roles', 0755, true);
    $roleDiscovery = new \CoquiBot\Coqui\Config\RoleDiscovery($wsPath);

    $executor = new \CoquiBot\Coqui\Agent\LoopExecutor(
        loopStore: $this->loopStore,
        projectStore: $projectStore,
    );

    $toolkit = new LoopToolkit($this->loopStore, $this->loopDiscovery, $executor);
    $startTool = $toolkit->tools()[0];

    $result = $startTool->execute([
        'definition' => 'parameterized',
        'goal' => 'Test goal',
        'parameters' => '{"topic": "authentication"}',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $data = json_decode($result->content, true);
    expect($data['parameters'])->toBe(['topic' => 'authentication']);

    // Clean up
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($wsPath, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($it as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($wsPath);
});

test('loop_start rejects invalid parameters JSON', function () {
    $startTool = $this->toolkit->tools()[0];

    $result = $startTool->execute([
        'definition' => 'harness',
        'goal' => 'Test goal',
        'parameters' => 'not valid json',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('valid JSON object');
});

test('loop_definitions shows parameter info', function () {
    file_put_contents($this->loopsDir . '/parameterized.json', json_encode([
        'name' => 'parameterized',
        'description' => 'Test parameterized loop',
        'roles' => [['role' => 'coder', 'prompt' => 'Work on {{topic}}.']],
        'termination_condition' => ['type' => 'manual'],
        'parameters' => [
            ['name' => 'topic', 'description' => 'Subject', 'required' => true],
            ['name' => 'format', 'description' => 'Format', 'required' => false, 'default' => 'md'],
        ],
    ]));

    $this->loopDiscovery->invalidateCache();
    $toolkit = new LoopToolkit($this->loopStore, $this->loopDiscovery);
    $defsTool = $toolkit->tools()[6];

    $result = $defsTool->execute([]);
    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('Parameters: topic, format?');
});

// ──────────────────────────────────────────────
//  Session ID Propagation
// ──────────────────────────────────────────────

test('loop_start passes sessionId from toolkit to executor', function () {
    $pdo = $this->storage->getPdo();
    $projectStore = new \CoquiBot\Coqui\Storage\ProjectStore($pdo);
    $artifactStore = new \CoquiBot\Coqui\Storage\ArtifactStore($pdo);
    $config = \CoquiBot\Coqui\Config\OpenClawConfig::fromArray([
        'agents' => ['defaults' => ['model' => ['primary' => 'test/model']]],
    ]);
    $roleResolver = new \CoquiBot\Coqui\Config\RoleResolver($config);
    $wsPath = sys_get_temp_dir() . '/coqui-session-ws-' . bin2hex(random_bytes(4));
    mkdir($wsPath . '/roles', 0755, true);
    $roleDiscovery = new \CoquiBot\Coqui\Config\RoleDiscovery($wsPath);

    $executor = new \CoquiBot\Coqui\Agent\LoopExecutor(
        loopStore: $this->loopStore,
        projectStore: $projectStore,
    );

    // Create toolkit WITH sessionId
    $toolkit = new LoopToolkit($this->loopStore, $this->loopDiscovery, $executor, $this->sessionId);
    $startTool = $toolkit->tools()[0];

    $result = $startTool->execute([
        'definition' => 'harness',
        'goal' => 'Test session propagation',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $data = json_decode($result->content, true);

    // Verify loop was created with sessionId stored
    $loop = $this->loopStore->getLoop($data['loop_id']);
    expect($loop)->not->toBeNull();
    expect($loop['session_id'])->toBe($this->sessionId);

    // Clean up
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($wsPath, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($it as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($wsPath);
});

test('loop_start without sessionId stores null in loop record', function () {
    $pdo = $this->storage->getPdo();
    $projectStore = new \CoquiBot\Coqui\Storage\ProjectStore($pdo);
    $artifactStore = new \CoquiBot\Coqui\Storage\ArtifactStore($pdo);
    $config = \CoquiBot\Coqui\Config\OpenClawConfig::fromArray([
        'agents' => ['defaults' => ['model' => ['primary' => 'test/model']]],
    ]);
    $roleResolver = new \CoquiBot\Coqui\Config\RoleResolver($config);
    $wsPath = sys_get_temp_dir() . '/coqui-nosession-ws-' . bin2hex(random_bytes(4));
    mkdir($wsPath . '/roles', 0755, true);
    $roleDiscovery = new \CoquiBot\Coqui\Config\RoleDiscovery($wsPath);

    $executor = new \CoquiBot\Coqui\Agent\LoopExecutor(
        loopStore: $this->loopStore,
        projectStore: $projectStore,
    );

    // Create toolkit WITHOUT sessionId (null)
    $toolkit = new LoopToolkit($this->loopStore, $this->loopDiscovery, $executor);
    $startTool = $toolkit->tools()[0];

    $result = $startTool->execute([
        'definition' => 'harness',
        'goal' => 'Test no session',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $data = json_decode($result->content, true);

    $loop = $this->loopStore->getLoop($data['loop_id']);
    expect($loop)->not->toBeNull();
    expect($loop['session_id'])->toBeNull();

    // Clean up
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($wsPath, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($it as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($wsPath);
});

test('prepareNextStage propagates sessionId into LoopStageResult', function () {
    $pdo = $this->storage->getPdo();
    $projectStore = new \CoquiBot\Coqui\Storage\ProjectStore($pdo);
    $artifactStore = new \CoquiBot\Coqui\Storage\ArtifactStore($pdo);
    $config = \CoquiBot\Coqui\Config\OpenClawConfig::fromArray([
        'agents' => ['defaults' => ['model' => ['primary' => 'test/model']]],
    ]);
    $roleResolver = new \CoquiBot\Coqui\Config\RoleResolver($config);
    $wsPath = sys_get_temp_dir() . '/coqui-stage-ws-' . bin2hex(random_bytes(4));
    mkdir($wsPath . '/roles', 0755, true);
    $roleDiscovery = new \CoquiBot\Coqui\Config\RoleDiscovery($wsPath);

    $executor = new \CoquiBot\Coqui\Agent\LoopExecutor(
        loopStore: $this->loopStore,
        projectStore: $projectStore,
    );

    // Start loop with sessionId
    $toolkit = new LoopToolkit($this->loopStore, $this->loopDiscovery, $executor, $this->sessionId);
    $startTool = $toolkit->tools()[0];
    $result = $startTool->execute([
        'definition' => 'harness',
        'goal' => 'Test stage session propagation',
    ]);
    $data = json_decode($result->content, true);
    $loopId = $data['loop_id'];

    // Prepare the first stage and check sessionId
    $stageResult = $executor->prepareNextStage($loopId);
    expect($stageResult)->not->toBeNull();
    expect($stageResult->sessionId)->toBe($this->sessionId);
    expect($stageResult->role)->toBe('plan');

    // Clean up
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($wsPath, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($it as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($wsPath);
});
