#!/usr/bin/env php
<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$pestBinary = $projectRoot . '/vendor/bin/pest';

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

$environment = $_ENV;
$command = [PHP_BINARY];

if (extension_loaded('pcov')) {
    $command[] = '-d';
    $command[] = 'pcov.enabled=1';
    $command[] = '-d';
    $command[] = 'pcov.directory=' . $projectRoot . '/src';
} elseif (extension_loaded('xdebug')) {
    $environment['XDEBUG_MODE'] = 'coverage';
} else {
    fwrite(STDERR, "Coverage runner error: install PCOV or Xdebug to collect coverage.\n");
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