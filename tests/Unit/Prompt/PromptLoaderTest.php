<?php

declare(strict_types=1);

use CoquiBot\Coqui\Prompt\PromptLoader;
use CoquiBot\Coqui\Prompt\PromptNotFoundException;

beforeEach(function () {
    $this->promptsDir = sys_get_temp_dir() . '/coqui-prompt-test-' . bin2hex(random_bytes(4));
    mkdir($this->promptsDir, 0755, true);
    mkdir($this->promptsDir . '/tools', 0755, true);

    // Create minimal prompt files for testing
    file_put_contents($this->promptsDir . '/soul.md', '# Default Soul' . "\n\nDefault identity.");
    file_put_contents($this->promptsDir . '/base.md', '## Base Instructions' . "\n\nOperational rules.");
    file_put_contents($this->promptsDir . '/security.md', '## Security' . "\n\nSecurity rules.");
    file_put_contents($this->promptsDir . '/done.md', '## Done' . "\n\nCompletion rules.");
    file_put_contents($this->promptsDir . '/tools/workspace.md', '## Workspace' . "\n\nWorkspace tools.");
});

afterEach(function () {
    // Clean up temp directories
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

    if (is_dir($this->promptsDir)) {
        $cleanup($this->promptsDir);
    }

    if (isset($this->workspacePath) && is_dir($this->workspacePath)) {
        $cleanup($this->workspacePath);
    }
});

// --- resolveSoulPath() ---

test('resolveSoulPath returns default soul.md when no workspace path', function () {
    $loader = new PromptLoader(promptsDir: $this->promptsDir);

    expect($loader->resolveSoulPath())->toBe($this->promptsDir . '/soul.md');
});

test('resolveSoulPath returns default soul.md when workspace has no override', function () {
    $this->workspacePath = sys_get_temp_dir() . '/coqui-ws-' . bin2hex(random_bytes(4));
    mkdir($this->workspacePath, 0755, true);

    $loader = new PromptLoader(
        promptsDir: $this->promptsDir,
        workspacePath: $this->workspacePath,
    );

    expect($loader->resolveSoulPath())->toBe($this->promptsDir . '/soul.md');
});

test('resolveSoulPath returns workspace root soul.md override', function () {
    $this->workspacePath = sys_get_temp_dir() . '/coqui-ws-' . bin2hex(random_bytes(4));
    mkdir($this->workspacePath, 0755, true);
    file_put_contents($this->workspacePath . '/soul.md', '# Custom Soul');

    $loader = new PromptLoader(
        promptsDir: $this->promptsDir,
        workspacePath: $this->workspacePath,
    );

    expect($loader->resolveSoulPath())->toBe($this->workspacePath . '/soul.md');
});

test('resolveSoulPath finds uppercase SOUL.md in workspace root', function () {
    $this->workspacePath = sys_get_temp_dir() . '/coqui-ws-' . bin2hex(random_bytes(4));
    mkdir($this->workspacePath, 0755, true);
    file_put_contents($this->workspacePath . '/SOUL.md', '# Custom Soul (uppercase)');

    $loader = new PromptLoader(
        promptsDir: $this->promptsDir,
        workspacePath: $this->workspacePath,
    );

    expect($loader->resolveSoulPath())->toBe($this->workspacePath . '/SOUL.md');
});

test('resolveSoulPath finds title-case Soul.md in workspace root', function () {
    $this->workspacePath = sys_get_temp_dir() . '/coqui-ws-' . bin2hex(random_bytes(4));
    mkdir($this->workspacePath, 0755, true);
    file_put_contents($this->workspacePath . '/Soul.md', '# Custom Soul (title case)');

    $loader = new PromptLoader(
        promptsDir: $this->promptsDir,
        workspacePath: $this->workspacePath,
    );

    expect($loader->resolveSoulPath())->toBe($this->workspacePath . '/Soul.md');
});

test('resolveSoulPath returns workspace/prompts soul.md override', function () {
    $this->workspacePath = sys_get_temp_dir() . '/coqui-ws-' . bin2hex(random_bytes(4));
    mkdir($this->workspacePath . '/prompts', 0755, true);
    file_put_contents($this->workspacePath . '/prompts/soul.md', '# Custom Soul in prompts/');

    $loader = new PromptLoader(
        promptsDir: $this->promptsDir,
        workspacePath: $this->workspacePath,
    );

    expect($loader->resolveSoulPath())->toBe($this->workspacePath . '/prompts/soul.md');
});

test('resolveSoulPath prefers workspace root over workspace/prompts', function () {
    $this->workspacePath = sys_get_temp_dir() . '/coqui-ws-' . bin2hex(random_bytes(4));
    mkdir($this->workspacePath . '/prompts', 0755, true);
    file_put_contents($this->workspacePath . '/soul.md', '# Root soul');
    file_put_contents($this->workspacePath . '/prompts/soul.md', '# Prompts soul');

    $loader = new PromptLoader(
        promptsDir: $this->promptsDir,
        workspacePath: $this->workspacePath,
    );

    expect($loader->resolveSoulPath())->toBe($this->workspacePath . '/soul.md');
});

test('resolveSoulPath returns null when no soul.md exists anywhere', function () {
    $this->workspacePath = sys_get_temp_dir() . '/coqui-ws-' . bin2hex(random_bytes(4));
    mkdir($this->workspacePath, 0755, true);
    unlink($this->promptsDir . '/soul.md');

    $loader = new PromptLoader(
        promptsDir: $this->promptsDir,
        workspacePath: $this->workspacePath,
    );

    expect($loader->resolveSoulPath())->toBeNull();
});

// --- buildSystemPrompt() ---

test('buildSystemPrompt loads soul.md before base.md', function () {
    $loader = new PromptLoader(promptsDir: $this->promptsDir);

    $prompt = $loader->buildSystemPrompt();

    // Soul content appears before base content
    $soulPos = strpos($prompt, '# Default Soul');
    $basePos = strpos($prompt, '## Base Instructions');
    expect($soulPos)->toBeLessThan($basePos);
});

test('buildSystemPrompt uses workspace soul.md override', function () {
    $this->workspacePath = sys_get_temp_dir() . '/coqui-ws-' . bin2hex(random_bytes(4));
    mkdir($this->workspacePath, 0755, true);
    file_put_contents($this->workspacePath . '/soul.md', '# My Custom Soul');

    $loader = new PromptLoader(
        promptsDir: $this->promptsDir,
        workspacePath: $this->workspacePath,
    );

    $prompt = $loader->buildSystemPrompt();

    expect($prompt)->toContain('# My Custom Soul');
    expect($prompt)->not->toContain('# Default Soul');
});

test('buildSystemPrompt works without soul.md', function () {
    unlink($this->promptsDir . '/soul.md');

    $loader = new PromptLoader(promptsDir: $this->promptsDir);

    $prompt = $loader->buildSystemPrompt();

    expect($prompt)->toContain('## Base Instructions');
    expect($prompt)->not->toContain('Default Soul');
});

test('buildSystemPrompt maintains full composition order', function () {
    $loader = new PromptLoader(promptsDir: $this->promptsDir);

    $prompt = $loader->buildSystemPrompt();

    $soulPos = strpos($prompt, '# Default Soul');
    $basePos = strpos($prompt, '## Base Instructions');
    $toolsPos = strpos($prompt, '## Workspace');
    $securityPos = strpos($prompt, '## Security');
    $donePos = strpos($prompt, '## Done');

    expect($soulPos)->toBeLessThan($basePos);
    expect($basePos)->toBeLessThan($toolsPos);
    expect($toolsPos)->toBeLessThan($securityPos);
    expect($securityPos)->toBeLessThan($donePos);
});

// --- buildSystemPromptSections() ---

test('buildSystemPromptSections includes soul section before base', function () {
    $loader = new PromptLoader(promptsDir: $this->promptsDir);

    $sections = $loader->buildSystemPromptSections();

    $ids = array_column($sections, 'id');
    expect($ids[0])->toBe('soul');
    expect($ids[1])->toBe('base');
});

test('buildSystemPromptSections soul section has correct metadata', function () {
    $loader = new PromptLoader(promptsDir: $this->promptsDir);

    $sections = $loader->buildSystemPromptSections();
    $soulSection = $sections[0];

    expect($soulSection['id'])->toBe('soul');
    expect($soulSection['title'])->toBe('Soul');
    expect($soulSection['content'])->toContain('# Default Soul');
    expect($soulSection['source'])->toBe($this->promptsDir . '/soul.md');
});

test('buildSystemPromptSections uses workspace override source path', function () {
    $this->workspacePath = sys_get_temp_dir() . '/coqui-ws-' . bin2hex(random_bytes(4));
    mkdir($this->workspacePath, 0755, true);
    file_put_contents($this->workspacePath . '/soul.md', '# Workspace Soul');

    $loader = new PromptLoader(
        promptsDir: $this->promptsDir,
        workspacePath: $this->workspacePath,
    );

    $sections = $loader->buildSystemPromptSections();
    $soulSection = $sections[0];

    expect($soulSection['source'])->toBe($this->workspacePath . '/soul.md');
    expect($soulSection['content'])->toContain('# Workspace Soul');
});

test('buildSystemPromptSections omits soul when no soul.md exists', function () {
    $this->workspacePath = sys_get_temp_dir() . '/coqui-ws-' . bin2hex(random_bytes(4));
    mkdir($this->workspacePath, 0755, true);
    unlink($this->promptsDir . '/soul.md');

    $loader = new PromptLoader(
        promptsDir: $this->promptsDir,
        workspacePath: $this->workspacePath,
    );

    $sections = $loader->buildSystemPromptSections();
    $ids = array_column($sections, 'id');

    expect($ids)->not->toContain('soul');
    expect($ids[0])->toBe('base');
});

// --- Placeholder substitution in soul.md ---

test('soul.md supports placeholder substitution', function () {
    file_put_contents($this->promptsDir . '/soul.md', '# Soul for {{workspace_path}}');

    $loader = new PromptLoader(
        promptsDir: $this->promptsDir,
        placeholders: ['workspace_path' => '/my/workspace'],
    );

    $prompt = $loader->buildSystemPrompt();

    expect($prompt)->toContain('# Soul for /my/workspace');
});
