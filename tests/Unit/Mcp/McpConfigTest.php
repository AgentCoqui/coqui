<?php

declare(strict_types=1);

use CoquiBot\Coqui\Mcp\Config\McpConfig;

// -- Loading --

test('load creates empty config when file does not exist', function () {
    $path = sys_get_temp_dir() . '/mcp-test-' . uniqid();
    mkdir($path, 0o755, true);

    $config = new McpConfig($path);
    $config->load();

    expect($config->listServers())->toBe([]);

    rmdir($path);
});

test('load reads valid mcp.json', function () {
    $path = sys_get_temp_dir() . '/mcp-test-' . uniqid();
    mkdir($path, 0o755, true);

    $data = [
        'mcpServers' => [
            'github' => [
                'command' => 'npx',
                'args' => ['-y', '@modelcontextprotocol/server-github'],
                'env' => ['GITHUB_TOKEN' => '${GITHUB_TOKEN}'],
            ],
        ],
    ];

    file_put_contents($path . '/mcp.json', json_encode($data));

    $config = new McpConfig($path);
    $config->load();

    expect($config->listServers())->toHaveKey('github')
        ->and($config->getCommand('github'))->toBe('npx')
        ->and($config->getArgs('github'))->toBe(['-y', '@modelcontextprotocol/server-github']);

    unlink($path . '/mcp.json');
    rmdir($path);
});

test('load handles malformed JSON gracefully', function () {
    $path = sys_get_temp_dir() . '/mcp-test-' . uniqid();
    mkdir($path, 0o755, true);

    file_put_contents($path . '/mcp.json', 'not json');

    $config = new McpConfig($path);
    $config->load();

    expect($config->listServers())->toBe([]);

    unlink($path . '/mcp.json');
    rmdir($path);
});

test('load is idempotent unless forced', function () {
    $path = sys_get_temp_dir() . '/mcp-test-' . uniqid();
    mkdir($path, 0o755, true);

    $data = ['mcpServers' => ['a' => ['command' => 'echo']]];
    file_put_contents($path . '/mcp.json', json_encode($data));

    $config = new McpConfig($path);
    $config->load();

    // Overwrite the file
    $data2 = ['mcpServers' => ['b' => ['command' => 'echo']]];
    file_put_contents($path . '/mcp.json', json_encode($data2));

    // Second load should not re-read
    $config->load();
    expect($config->listServers())->toHaveKey('a');

    // Force reload should pick up the change
    $config->load(force: true);
    expect($config->listServers())->toHaveKey('b')
        ->and($config->listServers())->not->toHaveKey('a');

    unlink($path . '/mcp.json');
    rmdir($path);
});

// -- Save --

test('save writes valid JSON to disk', function () {
    $path = sys_get_temp_dir() . '/mcp-test-' . uniqid();
    mkdir($path, 0o755, true);

    $config = new McpConfig($path);
    $config->load();
    $config->addServer('test', ['command' => 'echo', 'args' => ['hello']]);
    $config->save();

    $writtenJson = file_get_contents($path . '/mcp.json');

    if ($writtenJson === false) {
        throw new RuntimeException('Expected mcp.json to be readable after save().');
    }

    $written = json_decode($writtenJson, true);

    expect($written['mcpServers']['test']['command'])->toBe('echo')
        ->and($written['mcpServers']['test']['args'])->toBe(['hello']);

    unlink($path . '/mcp.json');
    rmdir($path);
});

test('configPath trims windows style trailing separators', function () {
    $config = new McpConfig('C:\\workspace\\');

    expect($config->configPath())->toBe('C:\\workspace/mcp.json');
});

// -- CRUD --

test('addServer and getServer', function () {
    $path = sys_get_temp_dir() . '/mcp-test-' . uniqid();
    mkdir($path, 0o755, true);

    $config = new McpConfig($path);
    $config->load();
    $config->addServer('demo', ['command' => 'node', 'args' => ['server.js']]);

    expect($config->getServer('demo'))->toBe(['command' => 'node', 'args' => ['server.js']])
        ->and($config->getServer('nonexistent'))->toBeNull();

    rmdir($path);
});

test('renameServer moves config entry and preserves payload', function () {
    $path = sys_get_temp_dir() . '/mcp-test-' . uniqid();
    mkdir($path, 0o755, true);

    $config = new McpConfig($path);
    $config->load();
    $config->addServer('demo', [
        'command' => 'node',
        'args' => ['server.js'],
        'description' => 'Demo server',
    ]);

    expect($config->renameServer('demo', 'renamed'))->toBeTrue()
        ->and($config->getServer('demo'))->toBeNull()
        ->and($config->getServer('renamed'))->toMatchArray([
            'command' => 'node',
            'args' => ['server.js'],
            'description' => 'Demo server',
        ]);

    rmdir($path);
});

test('removeServer returns true for existing and false for missing', function () {
    $path = sys_get_temp_dir() . '/mcp-test-' . uniqid();
    mkdir($path, 0o755, true);

    $config = new McpConfig($path);
    $config->load();
    $config->addServer('x', ['command' => 'echo']);

    expect($config->removeServer('x'))->toBeTrue()
        ->and($config->getServer('x'))->toBeNull()
        ->and($config->removeServer('x'))->toBeFalse();

    rmdir($path);
});

test('setServerEnv adds env entry to existing server', function () {
    $path = sys_get_temp_dir() . '/mcp-test-' . uniqid();
    mkdir($path, 0o755, true);

    $config = new McpConfig($path);
    $config->load();
    $config->addServer('srv', ['command' => 'echo']);
    $config->setServerEnv('srv', 'API_KEY', '${API_KEY}');

    $env = $config->getEnv('srv');

    expect($env)->toBe(['API_KEY' => '${API_KEY}']);

    rmdir($path);
});

test('setServerEnv does nothing for nonexistent server', function () {
    $path = sys_get_temp_dir() . '/mcp-test-' . uniqid();
    mkdir($path, 0o755, true);

    $config = new McpConfig($path);
    $config->load();
    $config->setServerEnv('ghost', 'KEY', 'value');

    expect($config->getServer('ghost'))->toBeNull();

    rmdir($path);
});

// -- Enable/Disable --

test('disableServer sets disabled flag', function () {
    $path = sys_get_temp_dir() . '/mcp-test-' . uniqid();
    mkdir($path, 0o755, true);

    $config = new McpConfig($path);
    $config->load();
    $config->addServer('srv', ['command' => 'echo']);

    expect($config->isDisabled('srv'))->toBeFalse();

    $config->disableServer('srv');

    expect($config->isDisabled('srv'))->toBeTrue();

    rmdir($path);
});

test('enableServer removes disabled flag', function () {
    $path = sys_get_temp_dir() . '/mcp-test-' . uniqid();
    mkdir($path, 0o755, true);

    $config = new McpConfig($path);
    $config->load();
    $config->addServer('srv', ['command' => 'echo', 'disabled' => true]);

    expect($config->isDisabled('srv'))->toBeTrue();

    $config->enableServer('srv');

    expect($config->isDisabled('srv'))->toBeFalse();

    rmdir($path);
});

test('listEnabledServers excludes disabled servers', function () {
    $path = sys_get_temp_dir() . '/mcp-test-' . uniqid();
    mkdir($path, 0o755, true);

    $config = new McpConfig($path);
    $config->load();
    $config->addServer('active', ['command' => 'echo']);
    $config->addServer('inactive', ['command' => 'echo', 'disabled' => true]);

    $enabled = $config->listEnabledServers();

    expect($enabled)->toHaveKey('active')
        ->and($enabled)->not->toHaveKey('inactive');

    rmdir($path);
});

test('disable and enable return false for nonexistent servers', function () {
    $path = sys_get_temp_dir() . '/mcp-test-' . uniqid();
    mkdir($path, 0o755, true);

    $config = new McpConfig($path);
    $config->load();

    expect($config->disableServer('nope'))->toBeFalse()
        ->and($config->enableServer('nope'))->toBeFalse();

    rmdir($path);
});

// -- Helper Accessors --

test('getCommand and getArgs with missing server', function () {
    $path = sys_get_temp_dir() . '/mcp-test-' . uniqid();
    mkdir($path, 0o755, true);

    $config = new McpConfig($path);
    $config->load();

    expect($config->getCommand('nope'))->toBeNull()
        ->and($config->getArgs('nope'))->toBe([])
        ->and($config->getDescription('nope'))->toBeNull()
        ->and($config->getEnv('nope'))->toBe([]);

    rmdir($path);
});

test('getDescription returns trimmed optional description', function () {
    $path = sys_get_temp_dir() . '/mcp-test-' . uniqid();
    mkdir($path, 0o755, true);

    $config = new McpConfig($path);
    $config->load();
    $config->addServer('demo', [
        'command' => 'echo',
        'description' => '  Demo server  ',
    ]);

    expect($config->getDescription('demo'))->toBe('Demo server');

    rmdir($path);
});

test('configPath returns expected path', function () {
    $config = new McpConfig('/some/workspace');

    expect($config->configPath())->toBe('/some/workspace/mcp.json');
});
