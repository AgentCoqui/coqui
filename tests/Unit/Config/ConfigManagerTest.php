<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\ConfigManager;
use CoquiBot\Coqui\Config\ConfigValidator;
use CoquiBot\Coqui\Config\DefaultsLoader;

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/coqui-config-test-' . bin2hex(random_bytes(4));
    mkdir($this->tmpDir, 0755, true);
    mkdir($this->tmpDir . '/workspace', 0755, true);
    mkdir($this->tmpDir . '/project', 0755, true);

    $this->defaultsLoader = new DefaultsLoader();
    $this->validator = new ConfigValidator();

    $this->manager = new ConfigManager(
        workspacePath: $this->tmpDir . '/workspace',
        projectRoot: $this->tmpDir . '/project',
        defaultsLoader: $this->defaultsLoader,
        validator: $this->validator,
    );
});

afterEach(function () {
    $cleanup = function (string $dir) use (&$cleanup): void {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $cleanup($path) : unlink($path);
        }
        rmdir($dir);
    };
    $cleanup($this->tmpDir);
});

test('path returns workspace openclaw.json path', function () {
    expect($this->manager->path())->toBe($this->tmpDir . '/workspace/openclaw.json');
});

test('workspacePath returns workspace directory', function () {
    expect($this->manager->workspacePath())->toBe($this->tmpDir . '/workspace');
});

test('load creates default config when none exists', function () {
    $config = $this->manager->load();

    expect($config)->toBeInstanceOf(\CarmeloSantana\PHPAgents\Config\OpenClawConfig::class);
    expect(file_exists($this->manager->path()))->toBeTrue();
});

test('load seeds from project root config', function () {
    $projectConfig = [
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'openai/gpt-4o'],
            ],
        ],
    ];
    file_put_contents(
        $this->tmpDir . '/project/openclaw.json',
        json_encode($projectConfig, JSON_PRETTY_PRINT),
    );

    $config = $this->manager->load();

    expect($config->getPrimaryModel())->toBe('openai/gpt-4o');
});

test('load uses explicit path when provided', function () {
    $explicitPath = $this->tmpDir . '/custom-config.json';
    $data = [
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'anthropic/claude-sonnet-4-20250514'],
            ],
        ],
    ];
    file_put_contents($explicitPath, json_encode($data));

    $config = $this->manager->load($explicitPath);

    expect($config->getPrimaryModel())->toBe('anthropic/claude-sonnet-4-20250514');
    expect($this->manager->path())->toBe(realpath($explicitPath));
});

test('load throws for missing explicit path', function () {
    $this->manager->load('/nonexistent/path/config.json');
})->throws(\RuntimeException::class, 'Config file not found');

test('save writes valid config to disk', function () {
    $this->manager->load();

    $data = [
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'openai/gpt-4o'],
            ],
        ],
    ];

    $errors = $this->manager->save($data);

    expect($errors)->toBeEmpty();
    expect(file_exists($this->manager->path()))->toBeTrue();

    $written = json_decode(file_get_contents($this->manager->path()), true);
    expect($written['agents']['defaults']['model']['primary'])->toBe('openai/gpt-4o');
});

test('save returns validation errors for invalid config', function () {
    $this->manager->load();

    $data = [
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'invalid-no-slash'],
            ],
        ],
    ];

    $errors = $this->manager->save($data);

    expect($errors)->not->toBeEmpty();
    expect($errors[0])->toContain('model.primary');
});

test('set updates a single dot-notation key', function () {
    $this->manager->load();
    $data = [
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'openai/gpt-4o'],
            ],
        ],
    ];
    $this->manager->save($data);

    $errors = $this->manager->set('agents.defaults.model.primary', 'anthropic/claude-sonnet-4-20250514');

    expect($errors)->toBeEmpty();

    $written = json_decode(file_get_contents($this->manager->path()), true);
    expect($written['agents']['defaults']['model']['primary'])->toBe('anthropic/claude-sonnet-4-20250514');
});

test('hasChanged detects file modifications', function () {
    $this->manager->load();

    expect($this->manager->hasChanged())->toBeFalse();

    // Modify the file externally
    sleep(1); // Ensure different mtime
    file_put_contents($this->manager->path(), json_encode(['agents' => ['defaults' => ['model' => ['primary' => 'openai/gpt-4o']]]]));

    expect($this->manager->hasChanged())->toBeTrue();
});

test('reload refreshes config from disk', function () {
    $data = [
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'openai/gpt-4o'],
            ],
        ],
    ];
    $this->manager->load();
    $this->manager->save($data);

    // Externally change the file
    $data['agents']['defaults']['model']['primary'] = 'anthropic/claude-sonnet-4-20250514';
    file_put_contents($this->manager->path(), json_encode($data));

    $result = $this->manager->reload();

    expect($result)->toBeTrue();
    expect($this->manager->config()->getPrimaryModel())->toBe('anthropic/claude-sonnet-4-20250514');
});

test('toArray returns raw config data', function () {
    $data = [
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'openai/gpt-4o'],
            ],
        ],
    ];
    $this->manager->load();
    $this->manager->save($data);

    $array = $this->manager->toArray();

    expect($array['agents']['defaults']['model']['primary'])->toBe('openai/gpt-4o');
});

test('getSanitized masks API key fields', function () {
    $data = [
        'models' => [
            'providers' => [
                'openai' => [
                    'apiKey' => 'sk-secret123',
                    'models' => [],
                ],
            ],
        ],
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'openai/gpt-4o'],
            ],
        ],
    ];
    $this->manager->load();
    $this->manager->save($data);

    // Reload to pick up the saved data
    $this->manager->reload();

    $sanitized = $this->manager->getSanitized('models.providers.openai.apiKey');

    expect($sanitized)->toBe('***');
});

test('config throws when not loaded', function () {
    $manager = new ConfigManager(
        workspacePath: $this->tmpDir . '/workspace',
        projectRoot: $this->tmpDir . '/project',
        defaultsLoader: $this->defaultsLoader,
    );
    $manager->config();
})->throws(\RuntimeException::class, 'Config not loaded');

test('save without validator skips validation', function () {
    $manager = new ConfigManager(
        workspacePath: $this->tmpDir . '/workspace',
        projectRoot: $this->tmpDir . '/project',
        defaultsLoader: $this->defaultsLoader,
        validator: null,
    );
    $manager->load();

    // Even invalid data should be saved without a validator
    $errors = $manager->save(['foo' => 'bar']);
    expect($errors)->toBeEmpty();
    expect(file_exists($manager->path()))->toBeTrue();
});
