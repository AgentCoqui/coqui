<?php

declare(strict_types=1);

use CoquiBot\Coqui\Prompt\PromptLoader;

function makePersonaWithContext(): string {
    $dir = sys_get_temp_dir() . '/persona_' . uniqid();
    mkdir($dir . '/context', 0777, true);
    file_put_contents($dir . '/soul.md', "# Soul\nBe kind.");
    file_put_contents($dir . '/context/github.md', "# GitHub\nuser: carmelo");
    return $dir;
}

it('builds context content from the persona context dir', function () {
    $persona = makePersonaWithContext();
    $loader = new PromptLoader(
        promptsDir: dirname(__DIR__, 3) . '/prompts',
        placeholders: [],
        workspacePath: sys_get_temp_dir(),
        personaPath: $persona,
    );

    expect($loader->buildContextContent())->toContain('## Context')->toContain('GitHub');
});

it('emits a context section right after backstory in system prompt sections', function () {
    $persona = makePersonaWithContext();
    file_put_contents($persona . '/backstory.md', "# Backstory\nBorn in a repo.");
    $loader = new PromptLoader(
        promptsDir: dirname(__DIR__, 3) . '/prompts',
        placeholders: [],
        workspacePath: sys_get_temp_dir(),
        personaPath: $persona,
    );

    $ids = array_column($loader->buildSystemPromptSections(), 'id');
    $backstoryPos = array_search('backstory', $ids, true);
    $contextPos = array_search('context', $ids, true);

    expect($contextPos)->not->toBeFalse();
    expect($contextPos)->toBe($backstoryPos + 1);
});

it('omits context when the persona has no context dir', function () {
    $dir = sys_get_temp_dir() . '/persona_' . uniqid();
    mkdir($dir, 0777, true);
    file_put_contents($dir . '/soul.md', '# Soul');
    $loader = new PromptLoader(
        promptsDir: dirname(__DIR__, 3) . '/prompts',
        placeholders: [],
        workspacePath: sys_get_temp_dir(),
        personaPath: $dir,
    );

    expect($loader->buildContextContent())->toBeNull();
    expect(array_column($loader->buildSystemPromptSections(), 'id'))->not->toContain('context');
});

it('renders ## Context after backstory and before base in the composed prompt sections', function () {
    $persona = makePersonaWithContext();
    file_put_contents($persona . '/backstory.md', "# Backstory\nBorn in a repo.");
    $loader = new PromptLoader(
        promptsDir: dirname(__DIR__, 3) . '/prompts',
        placeholders: [],
        workspacePath: sys_get_temp_dir(),
        personaPath: $persona,
    );

    $sections = $loader->buildSystemPromptSections();
    $ids = array_column($sections, 'id');

    $backstoryPos = array_search('backstory', $ids, true);
    $contextPos = array_search('context', $ids, true);
    $basePos = array_search('base', $ids, true);

    expect($backstoryPos)->not->toBeFalse();
    expect($contextPos)->not->toBeFalse();
    expect($basePos)->not->toBeFalse();
    expect($contextPos)->toBeGreaterThan($backstoryPos);
    expect($basePos)->toBeGreaterThan($contextPos);

    $contextSection = $sections[$contextPos];
    expect($contextSection['content'])->toStartWith('## Context');
});

it('applies labels.context to the context heading', function () {
    $persona = sys_get_temp_dir() . '/persona_' . uniqid();
    mkdir($persona . '/context', 0777, true);
    file_put_contents($persona . '/soul.md', '# Soul');
    file_put_contents($persona . '/context/note.md', '# Note');
    file_put_contents($persona . '/preferences.json', json_encode([
        'prompts' => ['labels' => ['context' => 'Reference']],
    ]));

    $loader = new PromptLoader(
        promptsDir: dirname(__DIR__, 3) . '/prompts',
        placeholders: [],
        workspacePath: sys_get_temp_dir(),
        personaPath: $persona,
    );

    expect($loader->buildContextContent())->toStartWith('## Reference');
});
