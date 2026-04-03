<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CoquiBot\Coqui\Tool\ComposerTool;
use CoquiBot\Coqui\Toolkit\ComposerToolkit;

// ---------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------

function composerDir(): string
{
    return sys_get_temp_dir() . '/coqui-composer-test-' . getmypid();
}

function composerSetup(): string
{
    $dir = composerDir();

    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    file_put_contents($dir . '/composer.json', json_encode([
        'name' => 'test/workspace',
        'description' => 'Test workspace',
        'require' => [
            'php' => '^8.4',
        ],
        'config' => [
            'sort-packages' => true,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

    return $dir;
}

function composerCleanup(): void
{
    $dir = composerDir();
    if (!is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($dir);
}

beforeEach(function () {
    $this->workspacePath = composerSetup();
});

afterEach(function () {
    composerCleanup();
});

// ---------------------------------------------------------------
// Toolkit registration
// ---------------------------------------------------------------

test('ComposerToolkit registers composer tool', function () {
    $toolkit = new ComposerToolkit($this->workspacePath);
    $tools = $toolkit->tools();

    expect($tools)->toHaveCount(1);
    expect($tools[0]->name())->toBe('composer');
});

test('ComposerToolkit has guidelines', function () {
    $toolkit = new ComposerToolkit($this->workspacePath);

    expect($toolkit->guidelines())->toContain('composer');
    expect($toolkit->guidelines())->toContain('packagist');
    expect($toolkit->guidelines())->not->toBeEmpty();
});

// ---------------------------------------------------------------
// ComposerTool — schema
// ---------------------------------------------------------------

test('composer tool has correct function schema', function () {
    $tool = new ComposerTool($this->workspacePath);
    $schema = $tool->toFunctionSchema();

    expect($schema['function']['name'])->toBe('composer');
    expect($schema['function']['parameters']['properties'])->toHaveKey('action');
    expect($schema['function']['parameters']['properties'])->toHaveKey('package');
    expect($schema['function']['parameters']['properties'])->toHaveKey('version');
    expect($schema['function']['parameters']['properties'])->toHaveKey('dev');
    expect($schema['function']['parameters']['properties'])->toHaveKey('repository_type');
    expect($schema['function']['parameters']['properties'])->toHaveKey('repository_url');
    expect($schema['function']['parameters']['required'])->toContain('action');
});

// ---------------------------------------------------------------
// show action
// ---------------------------------------------------------------

test('show displays composer.json contents', function () {
    $tool = new ComposerTool($this->workspacePath);
    $result = $tool->execute(['action' => 'show']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('test/workspace');
    expect($result->content)->toContain('composer.json');
});

// ---------------------------------------------------------------
// validate action
// ---------------------------------------------------------------

test('validate checks valid composer.json', function () {
    $tool = new ComposerTool($this->workspacePath);
    $result = $tool->execute(['action' => 'validate']);

    // May have warnings about minimum-stability but shouldn't fully error
    expect($result->content)->toContain('Composer Validate');
});

// ---------------------------------------------------------------
// search action
// ---------------------------------------------------------------

test('search requires query parameter', function () {
    $tool = new ComposerTool($this->workspacePath);
    $result = $tool->execute(['action' => 'search']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('query');
});

// ---------------------------------------------------------------
// show-package action
// ---------------------------------------------------------------

test('show-package requires package parameter', function () {
    $tool = new ComposerTool($this->workspacePath);
    $result = $tool->execute(['action' => 'show-package']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('Package name');
});

// ---------------------------------------------------------------
// add action — denylist
// ---------------------------------------------------------------

test('add blocks denylisted packages', function () {
    $tool = new ComposerTool($this->workspacePath);
    $result = $tool->execute([
        'action' => 'add',
        'package' => 'laravel/framework',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('blocked');
    expect($result->content)->toContain('denylist');
});

test('add blocks illuminate packages', function () {
    $tool = new ComposerTool($this->workspacePath);
    $result = $tool->execute([
        'action' => 'add',
        'package' => 'illuminate/database',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('blocked');
});

test('add blocks symfony framework bundle', function () {
    $tool = new ComposerTool($this->workspacePath);
    $result = $tool->execute([
        'action' => 'add',
        'package' => 'symfony/framework-bundle',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('blocked');
});

// ---------------------------------------------------------------
// add action — requires package name
// ---------------------------------------------------------------

test('add requires package name', function () {
    $tool = new ComposerTool($this->workspacePath);
    $result = $tool->execute(['action' => 'add']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('Package name');
});

// ---------------------------------------------------------------
// remove action — requires package name
// ---------------------------------------------------------------

test('remove requires package name', function () {
    $tool = new ComposerTool($this->workspacePath);
    $result = $tool->execute(['action' => 'remove']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('Package name');
});

// ---------------------------------------------------------------
// add action — path repository validation
// ---------------------------------------------------------------

test('add with path repository validates directory exists', function () {
    $tool = new ComposerTool($this->workspacePath);
    $result = $tool->execute([
        'action' => 'add',
        'package' => 'vendor/package',
        'repository_type' => 'path',
        'repository_url' => '/nonexistent/path/to/package',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('does not exist');
});

test('add with invalid repository type returns error', function () {
    $tool = new ComposerTool($this->workspacePath);
    $result = $tool->execute([
        'action' => 'add',
        'package' => 'vendor/package',
        'repository_type' => 'invalid',
        'repository_url' => '/some/path',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('Unsupported repository type');
});

// ---------------------------------------------------------------
// doctor action
// ---------------------------------------------------------------

test('doctor runs diagnostic checks', function () {
    $tool = new ComposerTool($this->workspacePath);
    $result = $tool->execute(['action' => 'doctor']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('Workspace Doctor');
});

// ---------------------------------------------------------------
// unknown action
// ---------------------------------------------------------------

test('unknown action returns error', function () {
    $tool = new ComposerTool($this->workspacePath);
    $result = $tool->execute(['action' => 'foobar']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('Unknown action');
});

// ---------------------------------------------------------------
// missing composer.json
// ---------------------------------------------------------------

test('returns error when composer.json is missing', function () {
    $emptyDir = sys_get_temp_dir() . '/coqui-empty-' . getmypid();
    mkdir($emptyDir, 0755, true);

    $tool = new ComposerTool($emptyDir);
    $result = $tool->execute(['action' => 'show']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('composer.json not found');

    rmdir($emptyDir);
});
