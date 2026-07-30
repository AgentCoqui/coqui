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

test('resolveSoulPath ignores workspace root soul.md when prompts override is absent', function () {
    $this->workspacePath = sys_get_temp_dir() . '/coqui-ws-' . bin2hex(random_bytes(4));
    mkdir($this->workspacePath, 0755, true);
    file_put_contents($this->workspacePath . '/soul.md', '# Root soul');

    $loader = new PromptLoader(
        promptsDir: $this->promptsDir,
        workspacePath: $this->workspacePath,
    );

    expect($loader->resolveSoulPath())->toBe($this->promptsDir . '/soul.md');
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
    mkdir($this->workspacePath . '/prompts', 0755, true);
    file_put_contents($this->workspacePath . '/prompts/soul.md', '# My Custom Soul');

    $loader = new PromptLoader(
        promptsDir: $this->promptsDir,
        workspacePath: $this->workspacePath,
    );

    $prompt = $loader->buildSystemPrompt();

    expect($prompt)->toContain('# My Custom Soul');
    expect($prompt)->not->toContain('# Default Soul');
});

test('buildSystemPrompt strips persona soul frontmatter from rendered output', function () {
    $this->workspacePath = sys_get_temp_dir() . '/coqui-ws-' . bin2hex(random_bytes(4));
    $personaPath = $this->workspacePath . '/personas/artist';
    mkdir($personaPath, 0755, true);
    file_put_contents($personaPath . '/soul.md', "---\nmodel: anthropic/claude-sonnet-4-20250514\n---\n# Artist Soul\n\nCreate with style.");

    $loader = new PromptLoader(
        promptsDir: $this->promptsDir,
        workspacePath: $this->workspacePath,
        personaPath: $personaPath,
    );

    $prompt = $loader->buildSystemPrompt();
    $sections = $loader->buildSystemPromptSections();

    expect($prompt)->toContain('# Artist Soul');
    expect($prompt)->not->toContain('model: anthropic/claude-sonnet-4-20250514');
    expect($sections[0]['content'])->toContain('# Artist Soul');
    expect($sections[0]['content'])->not->toContain('model: anthropic/claude-sonnet-4-20250514');
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
    mkdir($this->workspacePath . '/prompts', 0755, true);
    file_put_contents($this->workspacePath . '/prompts/soul.md', '# Workspace Soul');

    $loader = new PromptLoader(
        promptsDir: $this->promptsDir,
        workspacePath: $this->workspacePath,
    );

    $sections = $loader->buildSystemPromptSections();
    $soulSection = $sections[0];

    expect($soulSection['source'])->toBe($this->workspacePath . '/prompts/soul.md');
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

// --- Persona 3-tier soul resolution ---

test('persona soul.md wins over workspace and default in 3-tier resolution', function () {
    // Tier 3: default soul already exists from beforeEach
    // Tier 2: workspace soul
    $this->workspacePath = sys_get_temp_dir() . '/coqui-ws-' . bin2hex(random_bytes(4));
    mkdir($this->workspacePath . '/prompts', 0755, true);
    file_put_contents($this->workspacePath . '/prompts/soul.md', '# Workspace Soul' . "\n\nWorkspace identity.");

    // Tier 1: persona soul (should win)
    $personaPath = $this->workspacePath . '/personas/winner';
    mkdir($personaPath, 0755, true);
    file_put_contents($personaPath . '/soul.md', '# Persona Soul' . "\n\nPersona identity wins.");

    $loader = new PromptLoader(
        promptsDir: $this->promptsDir,
        workspacePath: $this->workspacePath,
        personaPath: $personaPath,
    );

    $soulPath = $loader->resolveSoulPath();
    $prompt = $loader->buildSystemPrompt();
    $sections = $loader->buildSystemPromptSections();

    expect($soulPath)->toBe($personaPath . '/soul.md');
    expect($prompt)->toContain('# Persona Soul');
    expect($prompt)->toContain('Persona identity wins.');
    expect($prompt)->not->toContain('# Workspace Soul');
    expect($prompt)->not->toContain('# Default Soul');
    expect($sections[0]['source'])->toBe($personaPath . '/soul.md');
});

test('persona overrides specific prompt files independently', function () {
    // Persona overrides base.md but NOT security.md — security falls back
    $this->workspacePath = sys_get_temp_dir() . '/coqui-ws-' . bin2hex(random_bytes(4));
    mkdir($this->workspacePath, 0755, true);
    $personaPath = $this->workspacePath . '/personas/partial';
    mkdir($personaPath, 0755, true);
    file_put_contents($personaPath . '/soul.md', '# Partial Persona');
    file_put_contents($personaPath . '/base.md', '## Custom Base' . "\n\nPersona-specific base rules.");

    $loader = new PromptLoader(
        promptsDir: $this->promptsDir,
        workspacePath: $this->workspacePath,
        personaPath: $personaPath,
    );

    $prompt = $loader->buildSystemPrompt();

    // Persona overrides take effect for soul and base
    expect($prompt)->toContain('# Partial Persona');
    expect($prompt)->toContain('## Custom Base');
    expect($prompt)->not->toContain('## Base Instructions'); // default base replaced

    // Security falls through to default (no persona or workspace override)
    expect($prompt)->toContain('## Security');
});

test('buildBackstoryContent returns null when no backstory.md exists', function () {
    $this->workspacePath = sys_get_temp_dir() . '/coqui-workspace-' . bin2hex(random_bytes(4));
    mkdir($this->workspacePath, 0755, true);

    $loader = new PromptLoader(
        promptsDir: $this->promptsDir,
        workspacePath: $this->workspacePath,
    );

    expect($loader->buildBackstoryContent())->toBeNull();
});

test('buildBackstoryContent resolves from persona directory', function () {
    $this->workspacePath = sys_get_temp_dir() . '/coqui-workspace-' . bin2hex(random_bytes(4));
    mkdir($this->workspacePath, 0755, true);

    $personaPath = sys_get_temp_dir() . '/coqui-persona-backstory-' . bin2hex(random_bytes(4));
    mkdir($personaPath, 0755, true);
    file_put_contents($personaPath . '/backstory.md', '# Origin Story' . "\n\nI emerged from curiosity.");

    $loader = new PromptLoader(
        promptsDir: $this->promptsDir,
        workspacePath: $this->workspacePath,
        personaPath: $personaPath,
    );

    $backstory = $loader->buildBackstoryContent();

    expect($backstory)->toContain('Origin Story');
    expect($backstory)->toContain('I emerged from curiosity');
});

test('buildSystemPrompt includes backstory between soul and body', function () {
    $this->workspacePath = sys_get_temp_dir() . '/coqui-workspace-' . bin2hex(random_bytes(4));
    mkdir($this->workspacePath, 0755, true);

    $personaPath = sys_get_temp_dir() . '/coqui-persona-backstory2-' . bin2hex(random_bytes(4));
    mkdir($personaPath, 0755, true);
    file_put_contents($personaPath . '/soul.md', '# Test Soul' . "\n\nI am a test agent.");
    file_put_contents($personaPath . '/backstory.md', '# Backstory' . "\n\nMy origin story.");

    $loader = new PromptLoader(
        promptsDir: $this->promptsDir,
        workspacePath: $this->workspacePath,
        personaPath: $personaPath,
    );

    $prompt = $loader->buildSystemPrompt();

    expect($prompt)->toContain('Test Soul');
    expect($prompt)->toContain('My origin story');

    // Backstory should appear after soul but before base
    $soulPos = strpos($prompt, 'Test Soul');
    $backstoryPos = strpos($prompt, 'My origin story');
    $basePos = strpos($prompt, 'Base Instructions');

    expect($backstoryPos)->toBeGreaterThan($soulPos);
    expect($basePos)->toBeGreaterThan($backstoryPos);
});

test('buildSystemPrompt omits disabled prompt sections from persona preferences', function () {
    $this->workspacePath = sys_get_temp_dir() . '/coqui-workspace-' . bin2hex(random_bytes(4));
    $personaPath = $this->workspacePath . '/personas/minimal';
    mkdir($personaPath, 0755, true);

    file_put_contents($personaPath . '/preferences.json', json_encode([
        'prompts' => [
            'prompt_sections' => [
                'backstory' => false,
                'done' => false,
            ],
        ],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($personaPath . '/backstory.md', '# Backstory' . "\n\nHidden history.");

    $loader = new PromptLoader(
        promptsDir: $this->promptsDir,
        workspacePath: $this->workspacePath,
        personaPath: $personaPath,
    );

    $prompt = $loader->buildSystemPrompt();
    $sectionIds = array_column($loader->buildSystemPromptSections(), 'id');

    expect($prompt)->not->toContain('Hidden history.');
    expect($prompt)->not->toContain('## Done');
    expect($sectionIds)->not->toContain('backstory');
    expect($sectionIds)->not->toContain('done');
});

test('buildSystemPrompt renders stubbed tool prompts from persona preferences', function () {
    $this->workspacePath = sys_get_temp_dir() . '/coqui-workspace-' . bin2hex(random_bytes(4));
    $personaPath = $this->workspacePath . '/personas/stubbed';
    mkdir($personaPath, 0755, true);

    file_put_contents($personaPath . '/preferences.json', json_encode([
        'prompts' => [
            'prompt_sections' => [
                'tools' => 'stub',
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    $loader = new PromptLoader(
        promptsDir: $this->promptsDir,
        workspacePath: $this->workspacePath,
        personaPath: $personaPath,
    );

    $prompt = $loader->buildSystemPrompt();
    $sections = $loader->buildSystemPromptSections();

    expect($prompt)->toContain('Tool guidance is intentionally condensed for this persona.');
    expect($prompt)->not->toContain('## Workspace');
    expect(array_column($sections, 'id'))->toContain('tools.stub');
});

test('empty persona security override falls back to default security prompt', function () {
    $this->workspacePath = sys_get_temp_dir() . '/coqui-workspace-' . bin2hex(random_bytes(4));
    $personaPath = $this->workspacePath . '/personas/secure';
    mkdir($personaPath, 0755, true);
    file_put_contents($personaPath . '/security.md', '');

    $loader = new PromptLoader(
        promptsDir: $this->promptsDir,
        workspacePath: $this->workspacePath,
        personaPath: $personaPath,
    );

    $prompt = $loader->buildSystemPrompt();
    $securitySection = null;
    foreach ($loader->buildSystemPromptSections() as $section) {
        if (($section['id'] ?? null) === 'security') {
            $securitySection = $section;
            break;
        }
    }

    expect($prompt)->toContain('## Security');
    expect($securitySection)->not->toBeNull();
    expect($securitySection['source'])->toBe($this->promptsDir . '/security.md');
});
