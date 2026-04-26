<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Toolkit\ToolkitGeneratorToolkit;

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/coqui-toolkit-gen-' . bin2hex(random_bytes(4));
    mkdir($this->tmpDir . '/packages', 0755, true);
    $this->toolkit = new ToolkitGeneratorToolkit(workspacePath: $this->tmpDir);
    $this->tools = $this->toolkit->tools();
});

afterEach(function () {
    // Recursively remove temp dir
    $cleanup = function (string $dir) use (&$cleanup): void {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $cleanup($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    };
    $cleanup($this->tmpDir);
});

// ── Toolkit structure ───────────────────────────────────────────────

test('provides two tools', function () {
    expect($this->tools)->toHaveCount(2);
});

test('tool names are correct', function () {
    $names = array_map(fn($t) => $t->name(), $this->tools);

    expect($names)->toBe(['coqui_toolkit_create', 'coqui_toolkit_add']);
});

test('guidelines contain XML tags', function () {
    $guidelines = $this->toolkit->guidelines();

    expect($guidelines)->toContain('<TOOLKIT-GENERATOR-GUIDELINES>');
    expect($guidelines)->toContain('</TOOLKIT-GENERATOR-GUIDELINES>');
});

// ── Helper: find tool by name ───────────────────────────────────────

function findTool(array $tools, string $name): mixed
{
    foreach ($tools as $tool) {
        if ($tool->name() === $name) {
            return $tool;
        }
    }
    throw new RuntimeException("Tool not found: {$name}");
}

// ── coqui_toolkit_create ──────────────────────────────────────────────────

test('create rejects empty name', function () {
    $tool = findTool($this->tools, 'coqui_toolkit_create');
    $result = $tool->execute(['name' => '', 'description' => 'Test']);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('name');
});

test('create rejects empty description', function () {
    $tool = findTool($this->tools, 'coqui_toolkit_create');
    $result = $tool->execute(['name' => 'test-pkg', 'description' => '']);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('Description');
});

test('create generates correct directory structure', function () {
    $tool = findTool($this->tools, 'coqui_toolkit_create');
    $result = $tool->execute([
        'name' => 'test-toolkit',
        'description' => 'A test toolkit',
    ]);

    expect($result->status->value)->toBe('success');

    $pkgDir = $this->tmpDir . '/packages/test-toolkit';
    expect(is_dir($pkgDir))->toBeTrue();
    expect(is_dir($pkgDir . '/src'))->toBeTrue();
    expect(file_exists($pkgDir . '/composer.json'))->toBeTrue();
    expect(file_exists($pkgDir . '/README.md'))->toBeTrue();
    expect(file_exists($pkgDir . '/src/TestToolkitToolkit.php'))->toBeTrue();
});

test('create generates valid composer.json', function () {
    $tool = findTool($this->tools, 'coqui_toolkit_create');
    $tool->execute([
        'name' => 'my-api',
        'description' => 'My API toolkit',
    ]);

    $composerPath = $this->tmpDir . '/packages/my-api/composer.json';
    $data = json_decode(file_get_contents($composerPath), true);

    expect($data)->toBeArray();
    expect($data['name'])->toBe('coquibot/coqui-toolkit-my-api');
    expect($data['description'])->toBe('My API toolkit');
    expect($data['autoload']['psr-4'])->toHaveKey('CoquiBot\\Toolkits\\MyApi\\');
    expect($data['extra']['php-agents']['toolkits'])->toBe(['CoquiBot\\Toolkits\\MyApi\\MyApiToolkit']);
    expect($data['require']['php'])->toBe('^8.4');
    expect($data['require']['carmelosantana/php-agents'])->toBe('^0.2 || @dev');
});

test('create includes dependencies in composer.json', function () {
    $tool = findTool($this->tools, 'coqui_toolkit_create');
    $tool->execute([
        'name' => 'dep-test',
        'description' => 'Dependency test',
        'dependencies' => 'guzzlehttp/guzzle:^7.0,symfony/http-client:^7.0',
    ]);

    $composerPath = $this->tmpDir . '/packages/dep-test/composer.json';
    $data = json_decode(file_get_contents($composerPath), true);

    expect($data['require'])->toHaveKey('guzzlehttp/guzzle');
    expect($data['require']['guzzlehttp/guzzle'])->toBe('^7.0');
    expect($data['require'])->toHaveKey('symfony/http-client');
    expect($data['require']['symfony/http-client'])->toBe('^7.0');
});

test('create includes credentials in composer.json', function () {
    $tool = findTool($this->tools, 'coqui_toolkit_create');
    $tool->execute([
        'name' => 'cred-test',
        'description' => 'Credential test',
        'credentials' => '{"MY_API_KEY": "API key from https://example.com"}',
    ]);

    $composerPath = $this->tmpDir . '/packages/cred-test/composer.json';
    $data = json_decode(file_get_contents($composerPath), true);

    expect($data['extra']['php-agents']['credentials'])->toBe([
        'MY_API_KEY' => 'API key from https://example.com',
    ]);
});

test('create accepts native credential map', function () {
    $tool = findTool($this->tools, 'coqui_toolkit_create');
    $tool->execute([
        'name' => 'cred-map-test',
        'description' => 'Credential map test',
        'credentials' => ['MAP_API_KEY' => 'API key from native map'],
    ]);

    $composerPath = $this->tmpDir . '/packages/cred-map-test/composer.json';
    $data = json_decode(file_get_contents($composerPath), true);

    expect($data['extra']['php-agents']['credentials'])->toBe([
        'MAP_API_KEY' => 'API key from native map',
    ]);
});

test('create generates toolkit class with credentials support', function () {
    $tool = findTool($this->tools, 'coqui_toolkit_create');
    $tool->execute([
        'name' => 'cred-toolkit',
        'description' => 'Toolkit with credentials',
        'credentials' => '{"CRED_API_KEY": "The API key"}',
    ]);

    $classPath = $this->tmpDir . '/packages/cred-toolkit/src/CredToolkitToolkit.php';
    $content = file_get_contents($classPath);

    expect($content)->toContain('fromEnv()');
    expect($content)->toContain('resolveApiKey()');
    expect($content)->toContain('CRED_API_KEY');
    expect($content)->toContain('declare(strict_types=1)');
    expect($content)->toContain('namespace CoquiBot\\Toolkits\\CredToolkit');
    expect($content)->toContain('implements ToolkitInterface');
});

test('create generates valid PHP syntax', function () {
    $tool = findTool($this->tools, 'coqui_toolkit_create');
    $tool->execute([
        'name' => 'syntax-check',
        'description' => 'Syntax check test',
    ]);

    $classPath = $this->tmpDir . '/packages/syntax-check/src/SyntaxCheckToolkit.php';

    // Parse the file to check for syntax errors
    $output = [];
    $returnCode = 0;
    exec('php -l ' . escapeshellarg($classPath) . ' 2>&1', $output, $returnCode);

    expect($returnCode)->toBe(0);
});

test('create uses custom namespace when provided', function () {
    $tool = findTool($this->tools, 'coqui_toolkit_create');
    $tool->execute([
        'name' => 'custom-ns',
        'description' => 'Custom namespace test',
        'namespace' => 'Acme\\CustomToolkit',
    ]);

    $composerPath = $this->tmpDir . '/packages/custom-ns/composer.json';
    $data = json_decode(file_get_contents($composerPath), true);

    expect($data['autoload']['psr-4'])->toHaveKey('Acme\\CustomToolkit\\');
    expect($data['extra']['php-agents']['toolkits'][0])->toBe('Acme\\CustomToolkit\\CustomToolkitToolkit');
});

test('create rejects duplicate package', function () {
    $tool = findTool($this->tools, 'coqui_toolkit_create');
    $tool->execute(['name' => 'dupe-test', 'description' => 'First']);
    $result = $tool->execute(['name' => 'dupe-test', 'description' => 'Second']);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('already exists');
});

test('create sanitizes package name', function () {
    $tool = findTool($this->tools, 'coqui_toolkit_create');
    $tool->execute([
        'name' => 'My Special_Toolkit!!!',
        'description' => 'Name sanitization test',
    ]);

    $pkgDir = $this->tmpDir . '/packages/my-special-toolkit';
    expect(is_dir($pkgDir))->toBeTrue();
});

test('create strips coquibot/ prefix from name', function () {
    $tool = findTool($this->tools, 'coqui_toolkit_create');
    $tool->execute([
        'name' => 'coquibot/strip-prefix',
        'description' => 'Prefix strip test',
    ]);

    $pkgDir = $this->tmpDir . '/packages/strip-prefix';
    expect(is_dir($pkgDir))->toBeTrue();
});

test('create generates README with credential docs', function () {
    $tool = findTool($this->tools, 'coqui_toolkit_create');
    $tool->execute([
        'name' => 'readme-cred',
        'description' => 'README credential test',
        'credentials' => '{"README_KEY": "Key for readme test"}',
    ]);

    $readmePath = $this->tmpDir . '/packages/readme-cred/README.md';
    $content = file_get_contents($readmePath);

    expect($content)->toContain('README_KEY');
    expect($content)->toContain('Key for readme test');
    expect($content)->toContain('Configuration');
});

// ── coqui_toolkit_add ────────────────────────────────────────────────

test('add_tool rejects empty toolkit name', function () {
    $tool = findTool($this->tools, 'coqui_toolkit_add');
    $result = $tool->execute([
        'toolkit_name' => '',
        'tool_name' => 'test',
        'tool_description' => 'Test tool',
    ]);

    expect($result->status->value)->toBe('error');
});

test('add_tool rejects invalid snake_case name', function () {
    $tool = findTool($this->tools, 'coqui_toolkit_add');

    // First create a toolkit
    $createTool = findTool($this->tools, 'coqui_toolkit_create');
    $createTool->execute(['name' => 'add-test', 'description' => 'Test']);

    $result = $tool->execute([
        'toolkit_name' => 'add-test',
        'tool_name' => 'InvalidName',
        'tool_description' => 'Test tool',
    ]);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('snake_case');
});

test('add_tool rejects non-existent toolkit', function () {
    $tool = findTool($this->tools, 'coqui_toolkit_add');
    $result = $tool->execute([
        'toolkit_name' => 'nonexistent',
        'tool_name' => 'test',
        'tool_description' => 'Test tool',
    ]);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('not found');
});

test('add_tool inserts new tool into toolkit', function () {
    $createTool = findTool($this->tools, 'coqui_toolkit_create');
    $createTool->execute(['name' => 'addable', 'description' => 'Addable toolkit']);

    $addTool = findTool($this->tools, 'coqui_toolkit_add');
    $result = $addTool->execute([
        'toolkit_name' => 'addable',
        'tool_name' => 'fetch_data',
        'tool_description' => 'Fetches data from an API',
        'parameters' => '[{"name": "url", "type": "string", "description": "The URL to fetch", "required": true}]',
    ]);

    expect($result->status->value)->toBe('success');
    expect($result->content)->toContain('fetch_data');

    // Verify the file was modified
    $classPath = $this->tmpDir . '/packages/addable/src/AddableToolkit.php';
    $content = file_get_contents($classPath);

    expect($content)->toContain("'fetch_data'");
    expect($content)->toContain('fetchDataTool');
    expect($content)->toContain('The URL to fetch');
});

test('add_tool generates valid PHP after insertion', function () {
    $createTool = findTool($this->tools, 'coqui_toolkit_create');
    $createTool->execute(['name' => 'valid-add', 'description' => 'Valid add test']);

    $addTool = findTool($this->tools, 'coqui_toolkit_add');
    $addTool->execute([
        'toolkit_name' => 'valid-add',
        'tool_name' => 'my_action',
        'tool_description' => 'Does something',
    ]);

    $classPath = $this->tmpDir . '/packages/valid-add/src/ValidAddToolkit.php';

    $output = [];
    $returnCode = 0;
    exec('php -l ' . escapeshellarg($classPath) . ' 2>&1', $output, $returnCode);

    expect($returnCode)->toBe(0);
});

test('add_tool rejects duplicate tool name', function () {
    $createTool = findTool($this->tools, 'coqui_toolkit_create');
    $createTool->execute(['name' => 'dup-tool', 'description' => 'Dup test']);

    $addTool = findTool($this->tools, 'coqui_toolkit_add');
    $addTool->execute([
        'toolkit_name' => 'dup-tool',
        'tool_name' => 'my_tool',
        'tool_description' => 'First tool',
    ]);

    $result = $addTool->execute([
        'toolkit_name' => 'dup-tool',
        'tool_name' => 'my_tool',
        'tool_description' => 'Duplicate tool',
    ]);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('already exists');
});

test('add_tool supports multiple parameter types', function () {
    $createTool = findTool($this->tools, 'coqui_toolkit_create');
    $createTool->execute(['name' => 'multi-param', 'description' => 'Multi param test']);

    $addTool = findTool($this->tools, 'coqui_toolkit_add');
    $result = $addTool->execute([
        'toolkit_name' => 'multi-param',
        'tool_name' => 'complex_tool',
        'tool_description' => 'A complex tool',
        'parameters' => json_encode([
            ['name' => 'query', 'type' => 'string', 'description' => 'Search query', 'required' => true],
            ['name' => 'count', 'type' => 'number', 'description' => 'Result count', 'required' => false],
            ['name' => 'verbose', 'type' => 'bool', 'description' => 'Verbose output', 'required' => false],
            ['name' => 'format', 'type' => 'enum', 'description' => 'Output format', 'required' => true, 'values' => ['json', 'text', 'csv']],
        ]),
    ]);

    expect($result->status->value)->toBe('success');

    $classPath = $this->tmpDir . '/packages/multi-param/src/MultiParamToolkit.php';
    $content = file_get_contents($classPath);

    expect($content)->toContain('StringParameter');
    expect($content)->toContain('NumberParameter');
    expect($content)->toContain('BoolParameter');
    expect($content)->toContain('EnumParameter');
});

test('add_tool accepts native parameter definitions', function () {
    $createTool = findTool($this->tools, 'coqui_toolkit_create');
    $createTool->execute(['name' => 'native-param', 'description' => 'Native param test']);

    $addTool = findTool($this->tools, 'coqui_toolkit_add');
    $result = $addTool->execute([
        'toolkit_name' => 'native-param',
        'tool_name' => 'typed_tool',
        'tool_description' => 'Uses native parameter definitions',
        'parameters' => [
            ['name' => 'query', 'type' => 'string', 'description' => 'Search query', 'required' => true],
            ['name' => 'verbose', 'type' => 'bool', 'description' => 'Verbose output', 'required' => false],
        ],
    ]);

    expect($result->status->value)->toBe('success');

    $classPath = $this->tmpDir . '/packages/native-param/src/NativeParamToolkit.php';
    $content = file_get_contents($classPath);

    expect($content)->toContain('typed_tool');
    expect($content)->toContain('Search query');
    expect($content)->toContain('BoolParameter');
});

// ── coqui_toolkits ────────────────────────────────────────────────────

test('list returns empty message when no packages exist', function () {
    // Clean packages dir
    rmdir($this->tmpDir . '/packages');

    $listTool = new \CoquiBot\Coqui\Tool\CoquiToolkitsTool(workspacePath: $this->tmpDir);
    $result = $listTool->tool()->execute(['action' => 'list']);

    expect($result->status->value)->toBe('success');
    expect($result->content)->toContain('No toolkit');
});

test('list shows created packages', function () {
    $createTool = findTool($this->tools, 'coqui_toolkit_create');
    $createTool->execute(['name' => 'listed-one', 'description' => 'First listed toolkit']);
    $createTool->execute(['name' => 'listed-two', 'description' => 'Second listed toolkit']);

    $listTool = new \CoquiBot\Coqui\Tool\CoquiToolkitsTool(workspacePath: $this->tmpDir);
    $result = $listTool->tool()->execute(['action' => 'list']);

    expect($result->status->value)->toBe('success');
    expect($result->content)->toContain('coquibot/coqui-toolkit-listed-one');
    expect($result->content)->toContain('coquibot/coqui-toolkit-listed-two');
    expect($result->content)->toContain('First listed toolkit');
    expect($result->content)->toContain('Second listed toolkit');
});

test('list shows credential requirements', function () {
    $createTool = findTool($this->tools, 'coqui_toolkit_create');
    $createTool->execute([
        'name' => 'cred-listed',
        'description' => 'Credential listed toolkit',
        'credentials' => '{"LISTED_KEY": "A listed credential"}',
    ]);

    $listTool = new \CoquiBot\Coqui\Tool\CoquiToolkitsTool(workspacePath: $this->tmpDir);
    $result = $listTool->tool()->execute(['action' => 'list']);

    expect($result->status->value)->toBe('success');
    expect($result->content)->toContain('LISTED_KEY');
});

// ── Schema validation ───────────────────────────────────────────────

test('all tools produce valid function schemas', function () {
    foreach ($this->tools as $tool) {
        $schema = $tool->toFunctionSchema();

        expect($schema)->toBeArray();
        expect($schema['type'])->toBe('function');
        expect($schema['function'])->toBeArray();
        expect($schema['function']['name'])->toBe($tool->name());
        expect($schema['function']['parameters'])->toBeArray();
        expect($schema['function']['parameters']['type'])->toBe('object');
    }
});
