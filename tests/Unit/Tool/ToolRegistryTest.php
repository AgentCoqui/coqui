<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Coqui\Tool\ToolRegistry;

function makeTool(string $name, string $description): Tool
{
    return new Tool(
        name: $name,
        description: $description,
        parameters: [new StringParameter('input', 'Input', required: false)],
        callback: fn(array $args): ToolResult => ToolResult::success('ok'),
    );
}

test('count starts at zero', function () {
    $registry = new ToolRegistry();

    expect($registry->count())->toBe(0);
});

test('register increments count', function () {
    $registry = new ToolRegistry();
    $registry->register(makeTool('write_file', 'Write text to a file'));

    expect($registry->count())->toBe(1);
});

test('all returns every registered tool', function () {
    $registry = new ToolRegistry();
    $registry->register(makeTool('write_file', 'Write text to a file'));
    $registry->register(makeTool('read_file', 'Read a file from disk'));

    $all = $registry->all();

    expect($all)->toHaveCount(2)
        ->and(array_column($all, 'name'))->toContain('write_file')
        ->and(array_column($all, 'name'))->toContain('read_file');
});

test('search returns empty array when registry is empty', function () {
    $registry = new ToolRegistry();

    expect($registry->search('file'))->toBeEmpty();
});

test('search returns empty array for empty query', function () {
    $registry = new ToolRegistry();
    $registry->register(makeTool('write_file', 'Write content to a file'));

    expect($registry->search(''))->toBeEmpty();
});

test('search returns relevant tool for matching query', function () {
    $registry = new ToolRegistry();
    $registry->register(makeTool('write_file', 'Write content to a file on the filesystem'));
    $registry->register(makeTool('shell_exec', 'Execute a shell command'));
    $registry->register(makeTool('memory_save', 'Save information to long-term memory'));

    $results = $registry->search('file write');

    expect($results)->not->toBeEmpty()
        ->and($results[0]['name'])->toBe('write_file');
});

test('search respects topN limit', function () {
    $registry = new ToolRegistry();

    for ($i = 1; $i <= 10; $i++) {
        $registry->register(makeTool("tool_{$i}", "Tool number {$i} for general use"));
    }

    $results = $registry->search('tool general', topN: 3);

    expect(count($results))->toBeLessThanOrEqual(3);
});

test('search ranks name match above description match', function () {
    $registry = new ToolRegistry();
    // This tool has "database" only in description
    $registry->register(makeTool('query_runner', 'Execute queries against the database'));
    // This tool has "database" in the name
    $registry->register(makeTool('database_connect', 'Establish a connection to a data store'));

    $results = $registry->search('database');

    // database_connect should rank higher because the name matches the query term
    expect($results[0]['name'])->toBe('database_connect');
});

test('duplicate registration overwrites previous entry', function () {
    $registry = new ToolRegistry();
    $registry->register(makeTool('my_tool', 'Original description'));
    $registry->register(makeTool('my_tool', 'Updated description'));

    expect($registry->count())->toBe(1)
        ->and($registry->all()[0]['description'])->toBe('Updated description');
});

test('search result contains name and description keys', function () {
    $registry = new ToolRegistry();
    $registry->register(makeTool('shell_exec', 'Run a shell command in the workspace'));

    $results = $registry->search('shell command');

    expect($results[0])->toHaveKey('name')
        ->and($results[0])->toHaveKey('description')
        ->and($results[0])->toHaveKey('package')
        ->and($results[0]['name'])->toBe('shell_exec');
});

test('register stores package name when provided', function () {
    $registry = new ToolRegistry();
    $registry->register(makeTool('brave_search', 'Search the web'), 'coqui/brave-search');

    $all = $registry->all();
    expect($all[0]['package'])->toBe('coqui/brave-search');
});

test('register defaults package to empty string', function () {
    $registry = new ToolRegistry();
    $registry->register(makeTool('read_file', 'Read a file'));

    $all = $registry->all();
    expect($all[0]['package'])->toBe('');
});

test('search results include package name', function () {
    $registry = new ToolRegistry();
    $registry->register(makeTool('brave_search', 'Search the web via Brave'), 'coqui/brave-search');
    $registry->register(makeTool('read_file', 'Read a file'));

    $results = $registry->search('brave search');
    expect($results[0]['package'])->toBe('coqui/brave-search');
});
