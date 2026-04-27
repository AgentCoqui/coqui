#!/usr/bin/env php
<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$pestBinary = $projectRoot . '/vendor/bin/pest';
$profileMemoryLimit = getenv('COQUI_TEST_PROFILE_MEMORY_LIMIT');
$profileOutputDirectory = getenv('COQUI_TEST_PROFILE_OUTPUT_DIR');
$profileOutputName = getenv('COQUI_TEST_PROFILE_OUTPUT_NAME');
$includePerformanceTests = getenv('COQUI_TEST_PROFILE_INCLUDE_PERFORMANCE');

if ($profileMemoryLimit === false || $profileMemoryLimit === '') {
    $profileMemoryLimit = '512M';
}

if ($profileOutputDirectory === false || $profileOutputDirectory === '') {
    $profileOutputDirectory = $projectRoot . '/build/profiles/tests';
} elseif (!str_starts_with($profileOutputDirectory, DIRECTORY_SEPARATOR)) {
    $profileOutputDirectory = $projectRoot . '/' . $profileOutputDirectory;
}

if ($profileOutputName === false || $profileOutputName === '') {
    $profileOutputName = 'cachegrind.out.%p';
}

if ($includePerformanceTests === false || $includePerformanceTests === '') {
    $includePerformanceTests = '0';
}

if (!extension_loaded('xdebug')) {
    fwrite(STDERR, "Profile runner error: the xdebug extension is not loaded. Install or enable Xdebug for the active PHP CLI first.\n");
    exit(1);
}

putenv('COQUI_TEST_PROFILE_ACTIVE=1');

if (!file_exists($pestBinary)) {
    fwrite(STDERR, "Profile runner error: vendor/bin/pest not found. Run composer install first.\n");
    exit(1);
}

if (!is_dir($profileOutputDirectory) && !mkdir($profileOutputDirectory, 0755, true) && !is_dir($profileOutputDirectory)) {
    fwrite(STDERR, sprintf("Profile runner error: could not create directory %s.\n", $profileOutputDirectory));
    exit(1);
}

fwrite(STDOUT, sprintf("Writing Xdebug profiles to %s using %s\n", $profileOutputDirectory, $profileOutputName));

$command = [
    PHP_BINARY,
    '-d',
    'memory_limit=' . $profileMemoryLimit,
    '-d',
    'xdebug.mode=profile',
    '-d',
    'xdebug.start_with_request=yes',
    '-d',
    'xdebug.output_dir=' . $profileOutputDirectory,
    '-d',
    'xdebug.profiler_output_name=' . $profileOutputName,
    $pestBinary,
];

if (!in_array(strtolower($includePerformanceTests), ['1', 'true', 'yes', 'on'], true)) {
    $command[] = '--exclude-group=performance';
}

foreach (array_slice($argv, 1) as $argument) {
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
    null,
    ['bypass_shell' => true],
);

if (!is_resource($process)) {
    fwrite(STDERR, "Profile runner error: failed to start Pest.\n");
    exit(1);
}

$exitCode = proc_close($process);

exit(is_int($exitCode) ? $exitCode : 1);