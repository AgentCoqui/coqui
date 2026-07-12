<?php

declare(strict_types=1);

use CoquiBot\Coqui\Prompt\PersonaContextReader;

afterEach(function () {
    // Clean up temp directories created by tests
    $cleanup = function (string $dir) use (&$cleanup): void {
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $cleanup($path) : unlink($path);
        }
        rmdir($dir);
    };

    // Remove all persona_* temp directories
    $tempDir = sys_get_temp_dir();
    foreach (scandir($tempDir) as $entry) {
        if (strpos($entry, 'persona_') === 0) {
            $path = $tempDir . '/' . $entry;
            if (is_dir($path)) {
                $cleanup($path);
            }
        }
    }
});

it('returns null when no context dir exists', function () {
    $dir = sys_get_temp_dir() . '/persona_' . uniqid();
    mkdir($dir, 0777, true);

    expect((new PersonaContextReader())->read($dir))->toBeNull();
});

it('reads and orders context files numbered-first', function () {
    $dir = sys_get_temp_dir() . '/persona_' . uniqid();
    mkdir($dir . '/context', 0777, true);
    file_put_contents($dir . '/context/stack.md', "# Stack\nPHP 8.4");
    file_put_contents($dir . '/context/01-github.md', "# GitHub\nuser: carmelo");

    $out = (new PersonaContextReader())->read($dir);

    expect($out)->toStartWith('## Context');
    // Numbered file sorts before the unnumbered one.
    expect(strpos($out, 'GitHub'))->toBeLessThan(strpos($out, 'Stack'));
    // Per-file subheading derived from filename.
    expect($out)->toContain('### 01-github')->toContain('### stack');
});

it('returns null when context dir is empty of markdown', function () {
    $dir = sys_get_temp_dir() . '/persona_' . uniqid();
    mkdir($dir . '/context', 0777, true);
    file_put_contents($dir . '/context/notes.txt', 'ignored');

    expect((new PersonaContextReader())->read($dir))->toBeNull();
});

it('uses a custom heading when provided', function () {
    $dir = sys_get_temp_dir() . '/persona_' . uniqid();
    mkdir($dir . '/context', 0777, true);
    file_put_contents($dir . '/context/note.md', '# Note');

    $out = (new PersonaContextReader())->read($dir, 'Reference');

    expect($out)->toStartWith('## Reference');
});
