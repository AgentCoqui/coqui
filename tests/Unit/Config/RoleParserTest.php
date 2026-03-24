<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\RoleParser;
use CoquiBot\Coqui\Exception\RoleParseException;

beforeEach(function () {
    $this->parser = new RoleParser();
    $this->tmpDir = sys_get_temp_dir() . '/coqui-role-parser-test-' . bin2hex(random_bytes(8));
    mkdir($this->tmpDir, 0755, true);
});

afterEach(function () {
    $files = glob($this->tmpDir . '/*');
    if ($files !== false) {
        foreach ($files as $file) {
            unlink($file);
        }
    }
    if (is_dir($this->tmpDir)) {
        rmdir($this->tmpDir);
    }
});

test('parses readonly-shell access level', function () {
    $path = $this->tmpDir . '/explorer.md';
    file_put_contents($path, <<<'MD'
---
name: explorer
display_name: Explorer
description: Read-only codebase exploration with shell access
access_level: readonly-shell
---
Instructions here.
MD);

    $props = $this->parser->readProperties($path);

    expect($props->accessLevel)->toBe('readonly-shell');
    expect($props->name)->toBe('explorer');
});

test('parses full access level', function () {
    $path = $this->tmpDir . '/coder.md';
    file_put_contents($path, <<<'MD'
---
name: coder
display_name: Coder
description: Full access code writer
access_level: full
---
Instructions here.
MD);

    $props = $this->parser->readProperties($path);

    expect($props->accessLevel)->toBe('full');
});

test('parses readonly access level', function () {
    $path = $this->tmpDir . '/reviewer.md';
    file_put_contents($path, <<<'MD'
---
name: reviewer
display_name: Reviewer
description: Read-only review agent
access_level: readonly
---
Instructions here.
MD);

    $props = $this->parser->readProperties($path);

    expect($props->accessLevel)->toBe('readonly');
});

test('parses minimal access level', function () {
    $path = $this->tmpDir . '/minimal.md';
    file_put_contents($path, <<<'MD'
---
name: minimal-agent
display_name: Minimal
description: Minimal access agent
access_level: minimal
---
Instructions here.
MD);

    $props = $this->parser->readProperties($path);

    expect($props->accessLevel)->toBe('minimal');
});

test('defaults to readonly when access_level is omitted', function () {
    $path = $this->tmpDir . '/default.md';
    file_put_contents($path, <<<'MD'
---
name: default-agent
display_name: Default
description: No access level specified
---
Instructions here.
MD);

    $props = $this->parser->readProperties($path);

    expect($props->accessLevel)->toBe('readonly');
});

test('throws on invalid access level', function () {
    $path = $this->tmpDir . '/invalid.md';
    file_put_contents($path, <<<'MD'
---
name: bad-agent
display_name: Bad
description: Invalid access level
access_level: super-admin
---
Instructions here.
MD);

    $this->parser->readProperties($path);
})->throws(RoleParseException::class);

test('parses toolkits field from frontmatter', function () {
    $path = $this->tmpDir . '/evaluator.md';
    file_put_contents($path, <<<'MD'
---
name: evaluator
display_name: Evaluator
description: Grades past sessions
access_level: readonly
toolkits: "-*, +SessionEvaluationToolkit, +ProjectSourceToolkit"
---
Instructions here.
MD);

    $props = $this->parser->readProperties($path);

    expect($props->toolkits)->toBe('-*, +SessionEvaluationToolkit, +ProjectSourceToolkit');
});

test('falls back to allowed-tools when toolkits is absent', function () {
    $path = $this->tmpDir . '/legacy.md';
    file_put_contents($path, <<<'MD'
---
name: legacy-agent
display_name: Legacy
description: Uses old allowed-tools field
access_level: readonly
allowed-tools: "+*, -ShellToolkit"
---
Instructions here.
MD);

    $props = $this->parser->readProperties($path);

    expect($props->toolkits)->toBe('+*, -ShellToolkit');
});

test('toolkits is null when neither field is present', function () {
    $path = $this->tmpDir . '/plain.md';
    file_put_contents($path, <<<'MD'
---
name: plain-agent
display_name: Plain
description: No toolkit restrictions
access_level: full
---
Instructions here.
MD);

    $props = $this->parser->readProperties($path);

    expect($props->toolkits)->toBeNull();
});

test('parses orchestrator role file from config/roles', function () {
    $path = dirname(__DIR__, 3) . '/config/roles/orchestrator.md';

    if (!file_exists($path)) {
        $this->markTestSkipped('orchestrator.md not found in config/roles/');
    }

    $props = $this->parser->readProperties($path);

    expect($props->name)->toBe('orchestrator');
    expect($props->accessLevel)->toBe('full');
    expect($props->isBuiltin)->toBeTrue();
    expect($props->toolkits)->toBe('+*, -SessionEvaluationToolkit, -LearningToolkit, -ToolkitGeneratorToolkit');
});
