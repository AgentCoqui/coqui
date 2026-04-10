<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\SkillDiscovery;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Storage\SkillLifecycleStore;
use CoquiBot\Coqui\Toolkit\SkillToolkit;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;

function createToolkitWorkspace(): string
{
    $workspace = sys_get_temp_dir() . '/coqui-toolkit-' . uniqid();
    mkdir($workspace . '/skills', 0755, true);

    return $workspace;
}

function addToolkitSkill(string $workspace, string $name, string $description = 'Test skill', string $body = "# Instructions\n\nDo the thing."): void
{
    $skillDir = $workspace . '/skills/' . $name;
    mkdir($skillDir, 0755, true);
    file_put_contents(
        $skillDir . '/SKILL.md',
        "---\nname: {$name}\ndescription: {$description}\n---\n\n{$body}\n",
    );
}

function cleanupToolkitWorkspace(string $workspace): void
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

function createSkillLifecycleFixture(): array
{
    $dbPath = sys_get_temp_dir() . '/coqui-skill-lifecycle-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    return [
        'dbPath' => $dbPath,
        'storage' => $storage,
        'store' => new SkillLifecycleStore($storage->getPdo()),
        'sessionId' => $storage->createSession('orchestrator', 'test-model'),
        'turnId' => 'turn-test',
    ];
}

function cleanupSkillLifecycleFixture(array $fixture): void
{
    if (file_exists($fixture['dbPath'])) {
        unlink($fixture['dbPath']);
    }
}

function findToolkitTool(SkillToolkit $toolkit, string $name): object
{
    foreach ($toolkit->tools() as $tool) {
        if ($tool->name() === $name) {
            return $tool;
        }
    }

    throw new RuntimeException("Tool not found: {$name}");
}

test('skill_list returns all discovered skills', function () {
    $workspace = createToolkitWorkspace();
    addToolkitSkill($workspace, 'skill-a', 'First skill');
    addToolkitSkill($workspace, 'skill-b', 'Second skill');

    $discovery = new SkillDiscovery($workspace);
    $toolkit = new SkillToolkit($discovery);

    $tools = $toolkit->tools();
    $listTool = null;
    foreach ($tools as $tool) {
        if ($tool->name() === 'skill_list') {
            $listTool = $tool;
            break;
        }
    }

    expect($listTool)->not->toBeNull();

    $result = $listTool->execute([]);
    expect($result->content)->toContain('skill-a');
    expect($result->content)->toContain('skill-b');
    expect($result->content)->toContain('2 skill(s)');

    cleanupToolkitWorkspace($workspace);
});

test('skill_read returns skill body content', function () {
    $workspace = createToolkitWorkspace();
    addToolkitSkill($workspace, 'readable-skill', 'A readable skill', "# Hello World\n\nThis is the content.");
    $fixture = createSkillLifecycleFixture();

    $discovery = new SkillDiscovery($workspace);
    $toolkit = new SkillToolkit(
        $discovery,
        $fixture['store'],
        $fixture['sessionId'],
        $fixture['turnId'],
        'orchestrator',
    );

    try {
        $result = findToolkitTool($toolkit, 'skill_read')->execute(['name' => 'readable-skill']);
        expect($result->content)->toContain('# Hello World');
        expect($result->content)->toContain('This is the content.');

        $events = $fixture['store']->listSkillUsage(sessionId: $fixture['sessionId']);
        expect($events)->toHaveCount(1);
        expect($events[0]['skill_name'])->toBe('readable-skill');
        expect($events[0]['action'])->toBe('read');
        expect($events[0]['turn_id'])->toBe($fixture['turnId']);
    } finally {
        cleanupToolkitWorkspace($workspace);
        cleanupSkillLifecycleFixture($fixture);
    }
});

test('skill_read returns error for unknown skill', function () {
    $workspace = createToolkitWorkspace();

    $discovery = new SkillDiscovery($workspace);
    $toolkit = new SkillToolkit($discovery);

    $tools = $toolkit->tools();
    $readTool = null;
    foreach ($tools as $tool) {
        if ($tool->name() === 'skill_read') {
            $readTool = $tool;
            break;
        }
    }

    $result = $readTool->execute(['name' => 'nonexistent']);
    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('not found');

    cleanupToolkitWorkspace($workspace);
});

test('skill_create scaffolds valid skill directory', function () {
    $workspace = createToolkitWorkspace();
    $fixture = createSkillLifecycleFixture();

    $discovery = new SkillDiscovery($workspace);
    $toolkit = new SkillToolkit($discovery, $fixture['store'], $fixture['sessionId']);

    try {
        $result = findToolkitTool($toolkit, 'skill_create')->execute([
            'name' => 'new-skill',
            'description' => 'A brand new skill for testing.',
            'instructions' => "# New Skill\n\nFollow these steps.",
            'license' => 'MIT',
        ]);

        expect($result->content)->toContain('created successfully');

        $skillDir = $workspace . '/skills/new-skill';
        expect(is_dir($skillDir))->toBeTrue();
        expect(file_exists($skillDir . '/SKILL.md'))->toBeTrue();

        $content = file_get_contents($skillDir . '/SKILL.md');
        expect($content)->toContain('name: new-skill');
        expect($content)->toContain('description: A brand new skill for testing.');
        expect($content)->toContain('license: MIT');
        expect($content)->toContain('# New Skill');

        $skills = $discovery->discoverAll();
        expect(count($skills))->toBe(1);
        expect($skills[0]->name)->toBe('new-skill');

        $events = $fixture['store']->listSkillUsage(skillName: 'new-skill', sessionId: $fixture['sessionId']);
        expect($events)->toHaveCount(1);
        expect($events[0]['action'])->toBe('create');
    } finally {
        cleanupToolkitWorkspace($workspace);
        cleanupSkillLifecycleFixture($fixture);
    }
});

test('skill_update modifies instructions and records usage event', function () {
    $workspace = createToolkitWorkspace();
    addToolkitSkill($workspace, 'update-skill', 'Original description', "# Original\n\nBase body.");
    $fixture = createSkillLifecycleFixture();

    $discovery = new SkillDiscovery($workspace);
    $toolkit = new SkillToolkit($discovery, $fixture['store'], $fixture['sessionId'], $fixture['turnId']);

    try {
        $result = findToolkitTool($toolkit, 'skill_update')->execute([
            'name' => 'update-skill',
            'instructions' => 'Appended guidance.',
            'append' => true,
        ]);

        expect($result->content)->toContain('updated successfully');
        expect(file_get_contents($workspace . '/skills/update-skill/SKILL.md'))->toContain('Appended guidance.');

        $events = $fixture['store']->listSkillUsage(skillName: 'update-skill', sessionId: $fixture['sessionId']);
        expect($events)->toHaveCount(1);
        expect($events[0]['action'])->toBe('update');
        expect($events[0]['source_tool'])->toBe('skill_update');
    } finally {
        cleanupToolkitWorkspace($workspace);
        cleanupSkillLifecycleFixture($fixture);
    }
});

test('skill_create validates name format', function () {
    $workspace = createToolkitWorkspace();

    $discovery = new SkillDiscovery($workspace);
    $toolkit = new SkillToolkit($discovery);

    $tools = $toolkit->tools();
    $createTool = null;
    foreach ($tools as $tool) {
        if ($tool->name() === 'skill_create') {
            $createTool = $tool;
            break;
        }
    }

    $result = $createTool->execute([
        'name' => 'INVALID_NAME',
        'description' => 'Test',
        'instructions' => 'Test',
    ]);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('Invalid skill name');

    cleanupToolkitWorkspace($workspace);
});

test('guidelines returns non-empty string', function () {
    $workspace = createToolkitWorkspace();

    $discovery = new SkillDiscovery($workspace);
    $toolkit = new SkillToolkit($discovery);

    expect($toolkit->guidelines())->not->toBeEmpty();
    expect($toolkit->guidelines())->toContain('SKILL-GUIDELINES');

    cleanupToolkitWorkspace($workspace);
});

test('toolkit implements ToolkitInterface', function () {
    $workspace = createToolkitWorkspace();

    $discovery = new SkillDiscovery($workspace);
    $toolkit = new SkillToolkit($discovery);

    expect($toolkit)->toBeInstanceOf(ToolkitInterface::class);

    cleanupToolkitWorkspace($workspace);
});

test('toolkit returns three tools', function () {
    $workspace = createToolkitWorkspace();

    $discovery = new SkillDiscovery($workspace);
    $toolkit = new SkillToolkit($discovery);

    $tools = $toolkit->tools();
    expect($tools)->toHaveCount(4);

    $names = array_map(fn($t) => $t->name(), $tools);
    expect($names)->toContain('skill_list');
    expect($names)->toContain('skill_read');
    expect($names)->toContain('skill_create');
    expect($names)->toContain('skill_update');

    cleanupToolkitWorkspace($workspace);
});
