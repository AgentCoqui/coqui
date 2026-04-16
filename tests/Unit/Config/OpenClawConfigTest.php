<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Exception\ConfigNotFoundException;

test('fromFile throws ConfigNotFoundException for missing file', function () {
    OpenClawConfig::fromFile('/nonexistent/path.json');
})->throws(ConfigNotFoundException::class);

test('fromArray creates config from array', function () {
    $config = OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'model' => [
                    'primary' => 'ollama/llama3.2:latest',
                    'fallbacks' => ['ollama/qwen3:latest'],
                ],
            ],
        ],
    ]);

    expect($config->getPrimaryModel())->toBe('ollama/llama3.2:latest');
    expect($config->getFallbacks())->toBe(['ollama/qwen3:latest']);
});

test('get retrieves nested values with dot notation', function () {
    $config = OpenClawConfig::fromArray([
        'models' => [
            'providers' => [
                'ollama' => [
                    'baseUrl' => 'http://localhost:11434/v1',
                ],
            ],
        ],
    ]);

    expect($config->get('models.providers.ollama.baseUrl'))->toBe('http://localhost:11434/v1');
});

test('get returns default for missing keys', function () {
    $config = OpenClawConfig::fromArray([]);

    expect($config->get('missing.key', 'default'))->toBe('default');
});

test('has returns true for existing nested keys', function () {
    $config = OpenClawConfig::fromArray(['a' => ['b' => 'c']]);

    expect($config->has('a.b'))->toBeTrue();
    expect($config->has('a.x'))->toBeFalse();
});

test('resolveModel returns alias target', function () {
    $config = OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'models' => [
                    'ollama/llama3.2:latest' => ['alias' => 'llama'],
                ],
            ],
        ],
    ]);

    expect($config->resolveModel('llama'))->toBe('ollama/llama3.2:latest');
    expect($config->resolveModel('unknown'))->toBe('unknown');
});

test('getPrimaryModel returns empty string when not configured', function () {
    $config = OpenClawConfig::fromArray([]);

    expect($config->getPrimaryModel())->toBe('');
});

test('getImageModel returns null when not configured', function () {
    $config = OpenClawConfig::fromArray([]);

    expect($config->getImageModel())->toBeNull();
});

test('getDefaultProfile returns normalized configured profile', function () {
    $config = OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'profile' => ' Caelum ',
            ],
        ],
    ]);

    expect($config->getDefaultProfile())->toBe('caelum');
});
