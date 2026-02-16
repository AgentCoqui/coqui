<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\SkillDiscovery;
use CoquiBot\Coqui\Contract\SkillProperties;
use CoquiBot\Coqui\Exception\SkillNotFoundException;

function createTempWorkspace(): string
{
    $workspace = sys_get_temp_dir() . '/coqui-discovery-' . uniqid();
    mkdir($workspace, 0755, true);

    return $workspace;
}

function cleanupWorkspace(string $workspace): void
{
    if (!is_dir($workspace)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($workspace, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($files as $file) {
        if ($file->isDir()) {
            rmdir($file->getRealPath());
        } else {
            unlink($file->getRealPath());
        }
    }

    rmdir($workspace);
}

function populateSkill(string $workspace, string $name, string $description = 'Test skill'): void
{
    $skillDir = $workspace . '/skills/' . $name;
    mkdir($skillDir, 0755, true);
    file_put_contents(
        $skillDir . '/SKILL.md',
        "---\nname: {$name}\ndescription: {$description}\n---\n\n# Instructions for {$name}\n\nDo the thing.\n",
    );
}

test('discovers skills in directory', function () {
    $workspace = createTempWorkspace();
    populateSkill($workspace, 'skill-one', 'First skill');
    populateSkill($workspace, 'skill-two', 'Second skill');

    $discovery = new SkillDiscovery($workspace);
    $skills = $discovery->discoverAll();

    expect($skills)->toHaveCount(2);
    expect(array_map(fn(SkillProperties $s) => $s->name, $skills))
        ->toContain('skill-one')
        ->toContain('skill-two');

    cleanupWorkspace($workspace);
});

test('returns empty array for empty skills directory', function () {
    $workspace = createTempWorkspace();
    mkdir($workspace . '/skills', 0755, true);

    $discovery = new SkillDiscovery($workspace);
    $skills = $discovery->discoverAll();

    expect($skills)->toBeEmpty();

    cleanupWorkspace($workspace);
});

test('creates skills directory via ensureSkillsDir', function () {
    $workspace = createTempWorkspace();

    $discovery = new SkillDiscovery($workspace);
    $discovery->ensureSkillsDir();

    expect(is_dir($workspace . '/skills'))->toBeTrue();

    cleanupWorkspace($workspace);
});

test('skips directories without SKILL.md', function () {
    $workspace = createTempWorkspace();
    populateSkill($workspace, 'valid-skill');

    // Create a directory without SKILL.md
    mkdir($workspace . '/skills/not-a-skill', 0755, true);
    file_put_contents($workspace . '/skills/not-a-skill/README.md', 'Not a skill');

    $discovery = new SkillDiscovery($workspace);
    $skills = $discovery->discoverAll();

    expect($skills)->toHaveCount(1);
    expect($skills[0]->name)->toBe('valid-skill');

    cleanupWorkspace($workspace);
});

test('buildPromptSummary generates valid XML', function () {
    $workspace = createTempWorkspace();
    populateSkill($workspace, 'test-skill', 'A test skill description');

    $discovery = new SkillDiscovery($workspace);
    $summary = $discovery->buildPromptSummary();

    expect($summary)->toContain('<available-skills>');
    expect($summary)->toContain('</available-skills>');
    expect($summary)->toContain('<name>test-skill</name>');
    expect($summary)->toContain('<description>A test skill description</description>');

    cleanupWorkspace($workspace);
});

test('buildPromptSummary returns fallback when no skills', function () {
    $workspace = createTempWorkspace();
    mkdir($workspace . '/skills', 0755, true);

    $discovery = new SkillDiscovery($workspace);
    $summary = $discovery->buildPromptSummary();

    expect($summary)->toBe('No skills installed.');

    cleanupWorkspace($workspace);
});

test('readBody returns full skill instructions', function () {
    $workspace = createTempWorkspace();
    populateSkill($workspace, 'body-skill');

    $discovery = new SkillDiscovery($workspace);
    $body = $discovery->readBody('body-skill');

    expect($body)->toContain('# Instructions for body-skill');
    expect($body)->toContain('Do the thing.');

    cleanupWorkspace($workspace);
});

test('getSkill throws SkillNotFoundException for unknown name', function () {
    $workspace = createTempWorkspace();
    mkdir($workspace . '/skills', 0755, true);

    $discovery = new SkillDiscovery($workspace);
    $discovery->getSkill('nonexistent');

    cleanupWorkspace($workspace);
})->throws(SkillNotFoundException::class);

test('invalidateCache forces re-discovery', function () {
    $workspace = createTempWorkspace();
    populateSkill($workspace, 'initial-skill');

    $discovery = new SkillDiscovery($workspace);
    $skills = $discovery->discoverAll();
    expect($skills)->toHaveCount(1);

    // Add another skill
    populateSkill($workspace, 'new-skill');

    // Without invalidation, cache returns old result
    $skills = $discovery->discoverAll();
    expect($skills)->toHaveCount(1);

    // After invalidation, new skill is found
    $discovery->invalidateCache();
    $skills = $discovery->discoverAll();
    expect($skills)->toHaveCount(2);

    cleanupWorkspace($workspace);
});

test('skillExists returns true for existing skill', function () {
    $workspace = createTempWorkspace();
    populateSkill($workspace, 'existing-skill');

    $discovery = new SkillDiscovery($workspace);

    expect($discovery->skillExists('existing-skill'))->toBeTrue();
    expect($discovery->skillExists('nonexistent'))->toBeFalse();

    cleanupWorkspace($workspace);
});

test('skillsDir returns correct path', function () {
    $workspace = createTempWorkspace();

    $discovery = new SkillDiscovery($workspace);

    expect($discovery->skillsDir())->toBe($workspace . '/skills');

    cleanupWorkspace($workspace);
});
