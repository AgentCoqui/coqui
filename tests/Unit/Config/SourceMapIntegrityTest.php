<?php

declare(strict_types=1);

/**
 * Structural-integrity guard for config/source.json.
 *
 * This is the durable guard against the B-series tech debt: dead file
 * entries, layers referenced but never declared, and duplicate entries.
 * It exercises the real, committed map — not a fixture.
 */

use PHPUnit\Framework\Assert;

/**
 * @return array{root: string, map: array<string, mixed>, files: list<array<string, mixed>>}
 */
function loadSourceMap(): array
{
    $root = dirname(__DIR__, 3);
    $raw = file_get_contents($root . '/config/source.json');
    Assert::assertIsString($raw, 'config/source.json is unreadable');

    $map = json_decode($raw, true);
    Assert::assertIsArray($map, 'config/source.json does not parse to an array');
    Assert::assertArrayHasKey('files', $map);
    Assert::assertIsArray($map['files']);

    return ['root' => $root, 'map' => $map, 'files' => array_values($map['files'])];
}

test('config/source.json parses as a JSON object with files and layers', function () {
    ['map' => $map] = loadSourceMap();

    expect($map)->toHaveKey('layers');
    expect($map['layers'])->toBeArray();
    expect($map['files'])->toBeArray()->not->toBeEmpty();
});

test('every source.json entry points at a file that exists on disk', function () {
    ['root' => $root, 'files' => $files] = loadSourceMap();

    $missing = [];
    foreach ($files as $entry) {
        expect($entry)->toHaveKey('path');
        $path = (string) $entry['path'];
        if (!is_file($root . '/' . $path)) {
            $missing[] = $path;
        }
    }

    expect($missing)->toBe([], 'Dead source.json entries (path not on disk): ' . implode(', ', $missing));
});

test('every layer referenced by an entry is declared in the top-level layers object', function () {
    ['map' => $map, 'files' => $files] = loadSourceMap();

    $declared = array_keys($map['layers']);
    $undeclared = [];
    foreach ($files as $entry) {
        expect($entry)->toHaveKey('layer');
        $layer = (string) $entry['layer'];
        if (!in_array($layer, $declared, true)) {
            $undeclared[$entry['path'] ?? '?'] = $layer;
        }
    }

    expect($undeclared)->toBe(
        [],
        'Entries reference undeclared layers: ' . json_encode($undeclared),
    );
});

test('all source.json path values are unique', function () {
    ['files' => $files] = loadSourceMap();

    $paths = array_map(static fn(array $e): string => (string) $e['path'], $files);
    $counts = array_count_values($paths);
    $duplicates = array_keys(array_filter($counts, static fn(int $n): bool => $n > 1));

    expect($duplicates)->toBe([], 'Duplicate path entries in source.json: ' . implode(', ', $duplicates));
});

test('all source.json fqcn values are unique', function () {
    ['files' => $files] = loadSourceMap();

    $fqcns = [];
    foreach ($files as $entry) {
        expect($entry)->toHaveKey('fqcn');
        $fqcns[] = (string) $entry['fqcn'];
    }
    $counts = array_count_values($fqcns);
    $duplicates = array_keys(array_filter($counts, static fn(int $n): bool => $n > 1));

    expect($duplicates)->toBe([], 'Duplicate fqcn entries in source.json: ' . implode(', ', $duplicates));
});
