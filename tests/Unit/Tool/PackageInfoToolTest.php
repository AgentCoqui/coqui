<?php

declare(strict_types=1);

use Coqui\Tool\PackageInfoTool;

beforeEach(function () {
    // Use the real coqui project root so we can inspect actual installed packages
    $this->projectRoot = dirname(__DIR__, 3);
    $this->tool = new PackageInfoTool(projectRoot: $this->projectRoot);
});

test('has correct name', function () {
    expect($this->tool->name())->toBe('package_info');
});

test('has required parameters', function () {
    $params = $this->tool->parameters();

    expect($params)->toHaveCount(4);
    expect($params[0]->name)->toBe('action');
    expect($params[1]->name)->toBe('package');
});

test('returns error for missing package', function () {
    $result = $this->tool->execute([
        'action' => 'readme',
        'package' => 'nonexistent/package',
    ]);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('not found');
});

test('requires package name', function () {
    $result = $this->tool->execute([
        'action' => 'readme',
        'package' => '',
    ]);

    expect($result->status->value)->toBe('error');
});

test('reads readme for installed package', function () {
    $result = $this->tool->execute([
        'action' => 'readme',
        'package' => 'symfony/console',
    ]);

    // symfony/console should be installed (it's in coqui's require)
    if ($result->status->value === 'error') {
        $this->markTestSkipped('symfony/console not installed');
    }

    expect($result->content)->toContain('Console');
});

test('lists classes for installed package', function () {
    $result = $this->tool->execute([
        'action' => 'classes',
        'package' => 'psr/log',
    ]);

    if ($result->status->value === 'error') {
        $this->markTestSkipped('psr/log not installed');
    }

    expect($result->content)->toContain('LoggerInterface');
});

test('inspects class methods', function () {
    $result = $this->tool->execute([
        'action' => 'methods',
        'package' => 'psr/log',
        'class' => 'Psr\\Log\\LoggerInterface',
    ]);

    if ($result->status->value === 'error') {
        $this->markTestSkipped('Psr\\Log\\LoggerInterface not available');
    }

    expect($result->content)->toContain('interface');
    expect($result->content)->toContain('log');
});

test('reads source file with path sandboxing', function () {
    $result = $this->tool->execute([
        'action' => 'source',
        'package' => 'psr/log',
        'file' => '../../etc/passwd',
    ]);

    // Should be denied — directory traversal
    expect($result->status->value)->toBe('error');
});

test('rejects path traversal in source action', function () {
    $result = $this->tool->execute([
        'action' => 'source',
        'package' => 'psr/log',
        'file' => '../../../composer.json',
    ]);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('traversal');
});

test('returns error for unknown action', function () {
    $result = $this->tool->execute([
        'action' => 'invalid',
        'package' => 'psr/log',
    ]);

    expect($result->status->value)->toBe('error');
});

test('generates valid function schema', function () {
    $schema = $this->tool->toFunctionSchema();

    expect($schema['type'])->toBe('function');
    expect($schema['function']['name'])->toBe('package_info');
    expect($schema['function']['parameters']['properties'])->toHaveKeys(['action', 'package', 'class', 'file']);
});
