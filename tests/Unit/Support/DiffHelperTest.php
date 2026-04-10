<?php

declare(strict_types=1);

use CoquiBot\Coqui\Support\DiffHelper;

// ---------------------------------------------------------------
// unifiedDiff
// ---------------------------------------------------------------

test('unifiedDiff produces correct output for simple change', function () {
    $original = "line1\nline2\nline3\nline4\nline5";
    $modified = "line1\nline2\nchanged\nline4\nline5";

    $diff = DiffHelper::unifiedDiff($original, $modified);

    expect($diff)->toContain('-line3');
    expect($diff)->toContain('+changed');
    expect($diff)->toContain('@@');
});

test('unifiedDiff returns no-changes string for identical content', function () {
    $content = "line1\nline2\nline3";

    expect(DiffHelper::unifiedDiff($content, $content))->toBe("No changes.\n");
});

test('unifiedDiff handles empty original', function () {
    $diff = DiffHelper::unifiedDiff('', "line1\nline2");

    expect($diff)->toContain('+line1');
    expect($diff)->toContain('+line2');
});

test('unifiedDiff handles empty modified', function () {
    $diff = DiffHelper::unifiedDiff("line1\nline2", '');

    expect($diff)->toContain('-line1');
    expect($diff)->toContain('-line2');
});

test('unifiedDiff respects context_lines parameter', function () {
    $lines = [];
    for ($i = 1; $i <= 20; $i++) {
        $lines[] = "line{$i}";
    }
    $original = implode("\n", $lines);

    $lines[9] = 'changed10'; // Change line 10
    $modified = implode("\n", $lines);

    // 0 context lines — only the changed line
    $diff0 = DiffHelper::unifiedDiff($original, $modified, 'original', 'modified', 0);
    expect($diff0)->toContain('-line10');
    expect($diff0)->toContain('+changed10');
    expect($diff0)->not->toContain('line9');

    // 1 context line
    $diff1 = DiffHelper::unifiedDiff($original, $modified, 'original', 'modified', 1);
    expect($diff1)->toContain(' line9');
    expect($diff1)->toContain(' line11');
});

// ---------------------------------------------------------------
// preview
// ---------------------------------------------------------------

test('preview includes file label and diff content', function () {
    $original = "hello\nworld";
    $modified = "hello\nearth";

    $result = DiffHelper::preview($original, $modified, 'test.txt');

    expect($result)->toContain('--- Preview: test.txt ---');
    expect($result)->toContain('a/test.txt');
    expect($result)->toContain('b/test.txt');
    expect($result)->toContain('-world');
    expect($result)->toContain('+earth');
});

test('preview returns no-changes message when identical', function () {
    $result = DiffHelper::preview('same', 'same', 'file.txt');

    expect($result)->toContain('No changes.');
});

test('preview accepts metadata', function () {
    $result = DiffHelper::preview("a", "b", 'file.txt', ['key' => 'value']);

    expect($result)->toContain('key: value');
    expect($result)->toContain('-a');
    expect($result)->toContain('+b');
});

// ---------------------------------------------------------------
// myersDiff
// ---------------------------------------------------------------

test('myersDiff returns correct operations', function () {
    $old = ['a', 'b', 'c'];
    $new = ['a', 'x', 'c'];

    $ops = DiffHelper::myersDiff($old, $new);

    expect($ops)->toBeArray();

    // Find the operations for 'b' (removed) and 'x' (added)
    $hasRemove = false;
    $hasAdd = false;
    foreach ($ops as $op) {
        if ($op['op'] === 'remove' && ($op['old'] ?? '') === 'b') {
            $hasRemove = true;
        }
        if ($op['op'] === 'add' && ($op['new'] ?? '') === 'x') {
            $hasAdd = true;
        }
    }

    expect($hasRemove)->toBeTrue();
    expect($hasAdd)->toBeTrue();
});

test('myersDiff handles empty arrays', function () {
    $ops = DiffHelper::myersDiff([], ['a', 'b']);

    expect(count(array_filter($ops, fn($o) => $o['op'] === 'add')))->toBe(2);
});

// ---------------------------------------------------------------
// Edge cases
// ---------------------------------------------------------------

test('unifiedDiff handles single line files', function () {
    $diff = DiffHelper::unifiedDiff('old', 'new');

    expect($diff)->toContain('-old');
    expect($diff)->toContain('+new');
});

test('unifiedDiff handles trailing newlines', function () {
    $diff = DiffHelper::unifiedDiff("line1\nline2\n", "line1\nline2\nline3\n");

    expect($diff)->toContain('+line3');
});
