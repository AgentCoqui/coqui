<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\BackgroundTaskManager;
use CoquiBot\Coqui\Storage\SessionStorage;

/**
 * Reap-race regression tests for {@see BackgroundTaskManager::reapFinishedProcesses}.
 *
 * The manager spawns `task:run` children through {@see \CoquiBot\Coqui\Support\ProcessSpawner},
 * which wraps the command with `setsid --fork` on Linux. That wrapper detaches
 * the real child into its own session and then exits almost immediately, so
 * `proc_get_status` reports the WRAPPER's clean exit — never the real child's.
 *
 * These tests spawn a fake `task:run` binary through the real manager so the
 * setsid wrapping is exercised end to end, and assert the reap distinguishes
 * "wrapper exited, real child still running" (must NOT fail the task) from a
 * genuine crash (must fail the task).
 */
beforeEach(function () {
    $this->workspace = sys_get_temp_dir() . '/coqui-task-reap-' . bin2hex(random_bytes(8));
    mkdir($this->workspace . '/data', 0775, true);
    $this->dbPath = $this->workspace . '/data/coqui.db';
    $this->storage = new SessionStorage($this->dbPath);
    $this->sessionId = $this->storage->createSession('orchestrator', 'test/model');

    $this->autoload = dirname((new ReflectionClass(SessionStorage::class))->getFileName(), 3) . '/vendor/autoload.php';
    $this->sentinel = $this->workspace . '/sentinel';
    $this->ready = $this->workspace . '/ready';
});

afterEach(function () {
    releaseTestObjectProperties($this);
    cleanupSqliteTestDb($this->dbPath);
    cleanupTestTree($this->workspace);
});

/**
 * Write a fake `task:run` binary. Modes:
 *  - 'complete_after_sentinel': record real PID, stay alive until the sentinel
 *    file is removed, then emit a real `completed` event + `completed` status.
 *  - 'crash_after_ready': record real PID, then exit 1 with no terminal status.
 *  - 'die_before_pid': exit 1 immediately, never recording its own PID.
 */
function writeFakeTaskBin(string $path, string $autoload, string $dbPath, string $mode, string $sentinel, string $ready): void
{
    $src = '<?php' . "\n"
        . "require " . var_export($autoload, true) . ";\n"
        . "use CoquiBot\\Coqui\\Storage\\SessionStorage;\n"
        . "\$argv = \$_SERVER['argv'];\n"
        . "\$id = '';\n"
        . "foreach (\$argv as \$i => \$a) { if (\$a === 'task:run') { \$id = \$argv[\$i + 1] ?? ''; break; } }\n"
        . "\$mode = " . var_export($mode, true) . ";\n"
        . "if (\$mode === 'die_before_pid') { exit(1); }\n"
        . "\$storage = new SessionStorage(" . var_export($dbPath, true) . ");\n"
        . "\$storage->updateTaskStatus(\$id, 'running', ['pid' => getmypid()]);\n"
        . "file_put_contents(" . var_export($ready, true) . ", '1');\n"
        . "if (\$mode === 'crash_after_ready') { exit(1); }\n"
        . "while (file_exists(" . var_export($sentinel, true) . ")) { usleep(50000); }\n"
        . "\$storage->updateTaskStatus(\$id, 'completed', ['result' => 'PONG']);\n"
        . "\$storage->appendTaskEvent(\$id, 'completed', ['duration_ms' => 1, 'iterations' => 1, 'total_tokens' => 5, 'tools_used' => []]);\n"
        . "exit(0);\n";

    file_put_contents($path, $src);
}

/**
 * Tick the manager repeatedly (mirroring the 1s reap timer, but faster) until
 * $predicate returns true or the timeout elapses. Returns the predicate result.
 */
function tickTaskUntil(BackgroundTaskManager $manager, callable $predicate, float $timeoutSeconds = 20.0): bool
{
    $deadline = microtime(true) + $timeoutSeconds;
    while (microtime(true) < $deadline) {
        $manager->tick();
        if ($predicate()) {
            return true;
        }
        usleep(100000);
    }

    return $predicate();
}

/**
 * @return int Number of task events of the given type.
 */
function countTaskEvents(SessionStorage $storage, string $taskId, string $type): int
{
    $count = 0;
    foreach ($storage->getTaskEvents($taskId, limit: 500) as $event) {
        if (($event['event_type'] ?? '') === $type) {
            $count++;
        }
    }

    return $count;
}

test('reap does not fail a task whose detached child is still running', function () {
    $bin = $this->workspace . '/fake-coqui';
    writeFakeTaskBin($bin, $this->autoload, $this->dbPath, 'complete_after_sentinel', $this->sentinel, $this->ready);
    file_put_contents($this->sentinel, '1'); // keep the child alive

    $manager = new BackgroundTaskManager(
        storage: $this->storage,
        coquiBinPath: $bin,
        configPath: '',
        workDir: $this->workspace,
        workspacePath: $this->workspace,
    );

    $taskId = $this->storage->createTask($this->sessionId, 'Say PONG');
    expect($manager->start($taskId))->toBeTrue();

    // Tick through the whole window in which the setsid wrapper has already
    // exited but the real child is still running (blocked on the sentinel).
    $ready = $this->ready;
    tickTaskUntil($manager, fn() => file_exists($ready));
    // Keep ticking a bit past "ready" so any premature reap would have fired.
    for ($i = 0; $i < 5; $i++) {
        $manager->tick();
        usleep(100000);
    }

    // The task genuinely worked; the reap must NOT have synthesized a failure.
    expect(countTaskEvents($this->storage, $taskId, 'failed'))->toBe(0);
    expect(countTaskEvents($this->storage, $taskId, 'completed'))->toBe(0);

    // Let the child finish and observe the real, single completion.
    unlink($this->sentinel);
    $storage = $this->storage;
    $ok = tickTaskUntil($manager, function () use ($storage, $taskId) {
        $task = $storage->getTask($taskId);
        return $task !== null && $task['status'] === 'completed';
    });

    expect($ok)->toBeTrue();
    expect(countTaskEvents($this->storage, $taskId, 'failed'))->toBe(0);
    expect(countTaskEvents($this->storage, $taskId, 'completed'))->toBe(1);
});

test('reap fails a task whose child crashed after recording its PID', function () {
    $bin = $this->workspace . '/fake-coqui';
    writeFakeTaskBin($bin, $this->autoload, $this->dbPath, 'crash_after_ready', $this->sentinel, $this->ready);

    $manager = new BackgroundTaskManager(
        storage: $this->storage,
        coquiBinPath: $bin,
        configPath: '',
        workDir: $this->workspace,
        workspacePath: $this->workspace,
    );

    $taskId = $this->storage->createTask($this->sessionId, 'Say PONG');
    expect($manager->start($taskId))->toBeTrue();

    $storage = $this->storage;
    $ok = tickTaskUntil($manager, function () use ($storage, $taskId) {
        $task = $storage->getTask($taskId);
        return $task !== null && $task['status'] === 'failed';
    });

    expect($ok)->toBeTrue();
    expect(countTaskEvents($this->storage, $taskId, 'failed'))->toBe(1);
});

test('reap fails a task whose child died before recording a PID, after the grace window', function () {
    $bin = $this->workspace . '/fake-coqui';
    writeFakeTaskBin($bin, $this->autoload, $this->dbPath, 'die_before_pid', $this->sentinel, $this->ready);

    $manager = new BackgroundTaskManager(
        storage: $this->storage,
        coquiBinPath: $bin,
        configPath: '',
        workDir: $this->workspace,
        workspacePath: $this->workspace,
        childLivenessGraceSeconds: 0.5,
    );

    $taskId = $this->storage->createTask($this->sessionId, 'Say PONG');
    expect($manager->start($taskId))->toBeTrue();

    // The child never records its PID; the reap must wait out the grace window,
    // so an immediate tick must not fail the task.
    $manager->tick();
    $immediate = $this->storage->getTask($taskId);
    expect($immediate['status'])->toBe('running');

    $storage = $this->storage;
    $ok = tickTaskUntil($manager, function () use ($storage, $taskId) {
        $task = $storage->getTask($taskId);
        return $task !== null && $task['status'] === 'failed';
    });

    expect($ok)->toBeTrue();
    expect(countTaskEvents($this->storage, $taskId, 'failed'))->toBe(1);
});
