<?php

declare(strict_types=1);

use CoquiBot\Coqui\Tool\CoquiToolkitsTool;

beforeEach(function () {
    $this->workspace = sys_get_temp_dir() . '/coqui-toolkits-tool-' . bin2hex(random_bytes(8));
    mkdir($this->workspace . '/packages', 0755, true);
});

afterEach(function () {
    cleanupTestTree($this->workspace);
});

function writeWorkspaceToolkitPackage(string $workspace, string $dirName, string $description, array $credentials = []): void
{
    $packageDir = $workspace . '/packages/' . $dirName;
    mkdir($packageDir . '/src', 0755, true);

    $composer = [
        'name' => 'coquibot/coqui-toolkit-' . $dirName,
        'description' => $description,
        'autoload' => [
            'psr-4' => [
                'CoquiBot\\Toolkits\\' . str_replace(' ', '', ucwords(str_replace('-', ' ', $dirName))) . '\\' => 'src/',
            ],
        ],
        'require' => [
            'php' => '^8.4',
            'carmelosantana/php-agents' => '^0.13',
        ],
        'extra' => [
            'php-agents' => [
                'toolkits' => ['CoquiBot\\Toolkits\\Fixture\\' . str_replace(' ', '', ucwords(str_replace('-', ' ', $dirName))) . 'Toolkit'],
            ],
        ],
    ];

    if ($credentials !== []) {
        $composer['extra']['php-agents']['credentials'] = $credentials;
    }

    file_put_contents($packageDir . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

test('list returns empty message when no packages exist', function () {
    cleanupTestTree($this->workspace . '/packages');

    $result = (new CoquiToolkitsTool(workspacePath: $this->workspace))->tool()->execute(['action' => 'list']);

    expect($result->status->value)->toBe('success');
    expect($result->content)->toContain('No toolkits found');
});

test('list shows created workspace packages', function () {
    writeWorkspaceToolkitPackage($this->workspace, 'listed-one', 'First listed toolkit');
    writeWorkspaceToolkitPackage($this->workspace, 'listed-two', 'Second listed toolkit');

    $result = (new CoquiToolkitsTool(workspacePath: $this->workspace))->tool()->execute(['action' => 'list']);

    expect($result->status->value)->toBe('success');
    expect($result->content)->toContain('coquibot/coqui-toolkit-listed-one');
    expect($result->content)->toContain('coquibot/coqui-toolkit-listed-two');
    expect($result->content)->toContain('First listed toolkit');
    expect($result->content)->toContain('Second listed toolkit');
});

test('list shows credential requirements for workspace packages', function () {
    writeWorkspaceToolkitPackage($this->workspace, 'cred-listed', 'Credential listed toolkit', [
        'LISTED_KEY' => 'A listed credential',
    ]);

    $result = (new CoquiToolkitsTool(workspacePath: $this->workspace))->tool()->execute(['action' => 'list']);

    expect($result->status->value)->toBe('success');
    expect($result->content)->toContain('LISTED_KEY');
});
