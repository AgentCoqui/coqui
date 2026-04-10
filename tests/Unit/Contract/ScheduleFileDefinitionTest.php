<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\ScheduleFileDefinition;

test('fromFile parses a valid schedule JSON', function () {
    $path = sys_get_temp_dir() . '/coqui-test-' . bin2hex(random_bytes(4)) . '/daily-report.json';
    mkdir(dirname($path), 0755, true);
    file_put_contents($path, json_encode([
        'schedule_expression' => '0 9 * * *',
        'prompt' => 'Generate daily report',
        'role' => 'coder',
        'max_iterations' => 30,
        'description' => 'Runs every day at 9am',
        'timezone' => 'America/New_York',
    ]));

    $def = ScheduleFileDefinition::fromFile($path);

    expect($def->name)->toBe('daily-report');
    expect($def->expression)->toBe('0 9 * * *');
    expect($def->prompt)->toBe('Generate daily report');
    expect($def->role)->toBe('coder');
    expect($def->maxIterations)->toBe(30);
    expect($def->description)->toBe('Runs every day at 9am');
    expect($def->timezone)->toBe('America/New_York');
    expect($def->sourcePath)->toBe($path);

    unlink($path);
    rmdir(dirname($path));
});

test('fromFile supports expression alias', function () {
    $path = sys_get_temp_dir() . '/coqui-test-' . bin2hex(random_bytes(4)) . '/my-task.json';
    mkdir(dirname($path), 0755, true);
    file_put_contents($path, json_encode([
        'expression' => '*/5 * * * *',
        'prompt' => 'Run my task',
    ]));

    $def = ScheduleFileDefinition::fromFile($path);
    expect($def->expression)->toBe('*/5 * * * *');

    unlink($path);
    rmdir(dirname($path));
});

test('fromFile supports cron alias', function () {
    $path = sys_get_temp_dir() . '/coqui-test-' . bin2hex(random_bytes(4)) . '/another-task.json';
    mkdir(dirname($path), 0755, true);
    file_put_contents($path, json_encode([
        'cron' => '0 */6 * * *',
        'prompt' => 'Run another task',
    ]));

    $def = ScheduleFileDefinition::fromFile($path);
    expect($def->expression)->toBe('0 */6 * * *');

    unlink($path);
    rmdir(dirname($path));
});

test('fromFile derives name from filename stem', function () {
    $path = sys_get_temp_dir() . '/coqui-test-' . bin2hex(random_bytes(4)) . '/my-cool-schedule.json';
    mkdir(dirname($path), 0755, true);
    file_put_contents($path, json_encode([
        'schedule_expression' => '* * * * *',
        'prompt' => 'Do stuff',
    ]));

    $def = ScheduleFileDefinition::fromFile($path);
    expect($def->name)->toBe('my-cool-schedule');

    unlink($path);
    rmdir(dirname($path));
});

test('fromFile throws on missing expression', function () {
    $path = sys_get_temp_dir() . '/coqui-test-' . bin2hex(random_bytes(4)) . '/bad.json';
    mkdir(dirname($path), 0755, true);
    file_put_contents($path, json_encode(['prompt' => 'test']));

    try {
        ScheduleFileDefinition::fromFile($path);
        expect(false)->toBeTrue(); // Should not reach
    } catch (\InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('missing required field');
    } finally {
        unlink($path);
        rmdir(dirname($path));
    }
});

test('fromFile throws on missing prompt', function () {
    $path = sys_get_temp_dir() . '/coqui-test-' . bin2hex(random_bytes(4)) . '/bad.json';
    mkdir(dirname($path), 0755, true);
    file_put_contents($path, json_encode(['schedule_expression' => '* * * * *']));

    try {
        ScheduleFileDefinition::fromFile($path);
        expect(false)->toBeTrue();
    } catch (\InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('missing required field: prompt');
    } finally {
        unlink($path);
        rmdir(dirname($path));
    }
});

test('fromFile throws on invalid cron expression', function () {
    $path = sys_get_temp_dir() . '/coqui-test-' . bin2hex(random_bytes(4)) . '/bad-cron.json';
    mkdir(dirname($path), 0755, true);
    file_put_contents($path, json_encode([
        'schedule_expression' => 'not-a-cron',
        'prompt' => 'test',
    ]));

    try {
        ScheduleFileDefinition::fromFile($path);
        expect(false)->toBeTrue();
    } catch (\InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('bad-cron.json');
    } finally {
        unlink($path);
        rmdir(dirname($path));
    }
});

test('fromFile throws on malformed JSON', function () {
    $path = sys_get_temp_dir() . '/coqui-test-' . bin2hex(random_bytes(4)) . '/malformed.json';
    mkdir(dirname($path), 0755, true);
    file_put_contents($path, '{not valid json');

    ScheduleFileDefinition::fromFile($path);

    unlink($path);
    rmdir(dirname($path));
})->throws(\JsonException::class);

test('fromFile uses defaults for optional fields', function () {
    $path = sys_get_temp_dir() . '/coqui-test-' . bin2hex(random_bytes(4)) . '/minimal.json';
    mkdir(dirname($path), 0755, true);
    file_put_contents($path, json_encode([
        'schedule_expression' => '0 0 * * *',
        'prompt' => 'Minimal schedule',
    ]));

    $def = ScheduleFileDefinition::fromFile($path);

    expect($def->role)->toBe('orchestrator');
    expect($def->maxIterations)->toBe(48);
    expect($def->timezone)->toBe('UTC');
    expect($def->maxFailures)->toBe(3);
    expect($def->enabled)->toBeTrue();
    expect($def->description)->toBeNull();
    expect($def->metadata)->toBeNull();

    unlink($path);
    rmdir(dirname($path));
});

test('fromFile supports @once expression', function () {
    $path = sys_get_temp_dir() . '/coqui-test-' . bin2hex(random_bytes(4)) . '/one-shot.json';
    mkdir(dirname($path), 0755, true);
    file_put_contents($path, json_encode([
        'schedule_expression' => '@once',
        'prompt' => 'Run this once',
    ]));

    $def = ScheduleFileDefinition::fromFile($path);
    expect($def->expression)->toBe('@once');

    unlink($path);
    rmdir(dirname($path));
});

test('fromFile supports enabled false', function () {
    $path = sys_get_temp_dir() . '/coqui-test-' . bin2hex(random_bytes(4)) . '/disabled.json';
    mkdir(dirname($path), 0755, true);
    file_put_contents($path, json_encode([
        'schedule_expression' => '* * * * *',
        'prompt' => 'Disabled schedule',
        'enabled' => false,
    ]));

    $def = ScheduleFileDefinition::fromFile($path);
    expect($def->enabled)->toBeFalse();

    unlink($path);
    rmdir(dirname($path));
});
