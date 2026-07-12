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
        profilePath: $persona,
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
        profilePath: $persona,
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
        profilePath: $dir,
    );

    expect($loader->buildContextContent())->toBeNull();
    expect(array_column($loader->buildSystemPromptSections(), 'id'))->not->toContain('context');
});
