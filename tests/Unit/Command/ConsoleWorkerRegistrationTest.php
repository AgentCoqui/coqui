<?php

declare(strict_types=1);

test('session title worker command is registered in coqui-console', function () {
    $bin = dirname(__DIR__, 3) . '/bin/coqui-console';
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bin) . ' session-title:run --help 2>&1';

    $output = shell_exec($command);

    expect($output)->toBeString();
    expect($output)->toContain('Execute a queued session title job');
    expect($output)->toContain('session-title:run');
});