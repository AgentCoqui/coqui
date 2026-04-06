#!/usr/bin/env php
<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$pestBinary = $projectRoot . '/vendor/bin/pest';
$coverageMemoryLimit = getenv('COQUI_TEST_COVERAGE_MEMORY_LIMIT');
$coverageDriver = getenv('COQUI_TEST_COVERAGE_DRIVER');

if ($coverageMemoryLimit === false || $coverageMemoryLimit === '') {
    $coverageMemoryLimit = '512M';
}

if ($coverageDriver === false || $coverageDriver === '') {
    $coverageDriver = 'auto';
}

if (!in_array($coverageDriver, ['auto', 'pcov', 'xdebug'], true)) {
    fwrite(STDERR, "Coverage runner error: COQUI_TEST_COVERAGE_DRIVER must be one of auto, pcov, or xdebug.\n");
    exit(1);
}

if (!file_exists($pestBinary)) {
    fwrite(STDERR, "Coverage runner error: vendor/bin/pest not found. Run composer install first.\n");
    exit(1);
}

$arguments = array_slice($argv, 1);
$coverageArgs = ['--coverage'];

while ($arguments !== []) {
    $argument = array_shift($arguments);

    if ($argument === '--clover') {
        $path = array_shift($arguments);
        if ($path === null || $path === '') {
            fwrite(STDERR, "Coverage runner error: --clover requires a path.\n");
            exit(1);
        }

        $fullPath = str_starts_with($path, DIRECTORY_SEPARATOR)
            ? $path
            : $projectRoot . '/' . $path;
        $directory = dirname($fullPath);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            fwrite(STDERR, sprintf("Coverage runner error: could not create directory %s.\n", $directory));
            exit(1);
        }

        $coverageArgs[] = '--coverage-clover';
        $coverageArgs[] = $fullPath;

        continue;
    }

    $coverageArgs[] = $argument;
}

$environment = null;
$command = [PHP_BINARY];

$command[] = '-d';
$command[] = 'memory_limit=' . $coverageMemoryLimit;

if (($coverageDriver === 'auto' || $coverageDriver === 'pcov') && extension_loaded('pcov')) {
    $command[] = '-d';
    $command[] = 'pcov.enabled=1';
    $command[] = '-d';
    $command[] = 'pcov.directory=' . $projectRoot . '/src';
} elseif (($coverageDriver === 'auto' || $coverageDriver === 'xdebug') && extension_loaded('xdebug')) {
    putenv('XDEBUG_MODE=coverage');
} elseif ($coverageDriver === 'pcov') {
    fwrite(STDERR, "Coverage runner error: COQUI_TEST_COVERAGE_DRIVER=pcov was requested, but the pcov extension is not loaded.\n");
    exit(1);
} elseif ($coverageDriver === 'xdebug') {
    fwrite(STDERR, "Coverage runner error: COQUI_TEST_COVERAGE_DRIVER=xdebug was requested, but the xdebug extension is not loaded.\n");
    exit(1);
} else {
    fwrite(STDERR, "Coverage runner error: install PCOV or Xdebug to collect coverage, or run plain composer test without coverage.\n");
    exit(1);
}

$command[] = $pestBinary;

foreach ($coverageArgs as $argument) {
    $command[] = $argument;
}

$process = proc_open(
    $command,
    [
        0 => STDIN,
        1 => STDOUT,
        2 => STDERR,
    ],
    $pipes,
    $projectRoot,
    $environment,
    ['bypass_shell' => true],
);

if (!is_resource($process)) {
    fwrite(STDERR, "Coverage runner error: failed to start Pest.\n");
    exit(1);
}

$exitCode = proc_close($process);

exit(is_int($exitCode) ? $exitCode : 1);