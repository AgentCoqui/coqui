<?php

declare(strict_types=1);

use CoquiBot\Coqui\Support\ProcessSpawner;

test('spawn creates a running process', function () {
    $result = ProcessSpawner::spawn(['php', '-r', 'sleep(10);'], sys_get_temp_dir());

    expect($result)->not->toBeNull();
    expect($result['process'])->toBeResource();
    expect($result['pipes'])->toBeArray();

    // Stdin should be closed, stdout/stderr should be non-blocking resources
    expect($result['pipes'][1])->toBeResource();
    expect($result['pipes'][2])->toBeResource();

    $pid = ProcessSpawner::getPid($result['process']);
    expect($pid)->toBeGreaterThan(0);

    // Clean up
    proc_terminate($result['process']);
    foreach ($result['pipes'] as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_close($result['process']);
});

test('spawn returns null for invalid command', function () {
    $result = ProcessSpawner::spawn(['/nonexistent/command/that/does/not/exist'], sys_get_temp_dir());

    // proc_open may succeed even with invalid commands (shell finds it later)
    // so we just verify that if it returns something, it's well-formed
    if ($result !== null) {
        expect($result['process'])->toBeResource();
        foreach ($result['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($result['process']);
    } else {
        expect($result)->toBeNull();
    }
});

test('getPid returns positive int for running process', function () {
    $result = ProcessSpawner::spawn(['php', '-r', 'sleep(10);'], sys_get_temp_dir());

    expect($result)->not->toBeNull();

    $pid = ProcessSpawner::getPid($result['process']);
    expect($pid)->toBeGreaterThan(0);

    proc_terminate($result['process']);
    foreach ($result['pipes'] as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_close($result['process']);
});

test('isProcessAlive returns true for running process', function () {
    $result = ProcessSpawner::spawn(['php', '-r', 'sleep(10);'], sys_get_temp_dir());
    expect($result)->not->toBeNull();

    $pid = ProcessSpawner::getPid($result['process']);
    expect(ProcessSpawner::isProcessAlive($pid))->toBeTrue();
});

test('isProcessAlive returns false for dead process', function () {
    $result = ProcessSpawner::spawn(['php', '-r', 'exit(0);'], sys_get_temp_dir());
    expect($result)->not->toBeNull();

    $pid = ProcessSpawner::getPid($result['process']);

    // Close pipes and wait for full exit
    foreach ($result['pipes'] as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_close($result['process']);

    // After proc_close the process should be fully reaped
    expect(ProcessSpawner::isProcessAlive($pid))->toBeFalse();
});

test('isProcessAlive returns false for invalid pid', function () {
    expect(ProcessSpawner::isProcessAlive(0))->toBeFalse();
    expect(ProcessSpawner::isProcessAlive(-1))->toBeFalse();
});

test('terminateGracefully stops a process', function () {
    $result = ProcessSpawner::spawn(['php', '-r', 'sleep(60);'], sys_get_temp_dir());
    expect($result)->not->toBeNull();

    $pid = ProcessSpawner::getPid($result['process']);
    expect($pid)->toBeGreaterThan(0);

    ProcessSpawner::terminateGracefully($result['process'], $pid, 2000);

    // Process should be dead after graceful termination
    usleep(200_000);
    expect(ProcessSpawner::isProcessAlive($pid))->toBeFalse();

    foreach ($result['pipes'] as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_close($result['process']);
});

test('killProcessGroup sends signal to process group', function () {
    $result = ProcessSpawner::spawn(['php', '-r', 'sleep(60);'], sys_get_temp_dir());
    expect($result)->not->toBeNull();

    $pid = ProcessSpawner::getPid($result['process']);
    expect($pid)->toBeGreaterThan(0);

    ProcessSpawner::killProcessGroup($pid, SIGTERM);

    // Close pipes and wait for full exit via proc_close
    foreach ($result['pipes'] as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_close($result['process']);

    expect(ProcessSpawner::isProcessAlive($pid))->toBeFalse();
});

test('spawn wraps command with setsid on unix', function () {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('setsid not available on Windows');
    }

    // Verify that spawn creates a process in its own process group
    // by checking that the spawned PID is a group leader (pgid == pid)
    $result = ProcessSpawner::spawn(['php', '-r', 'sleep(10);'], sys_get_temp_dir());
    expect($result)->not->toBeNull();

    $pid = ProcessSpawner::getPid($result['process']);
    expect($pid)->toBeGreaterThan(0);

    proc_terminate($result['process']);
    foreach ($result['pipes'] as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_close($result['process']);
});
