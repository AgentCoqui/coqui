<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Config\ConfigManager;
use CoquiBot\Coqui\Config\DefaultsLoader;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Repl\Handler\ThinkingHandler;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

function createThinkingHandlerFixture(string $roleModel = 'ollama/qwen3:8b'): array
{
    $workspacePath = sys_get_temp_dir() . '/coqui-thinking-handler-' . bin2hex(random_bytes(8));
    mkdir($workspacePath, 0755, true);

    $configData = [
        'agents' => [
            'defaults' => [
                'model' => ['primary' => $roleModel],
                'roles' => ['orchestrator' => $roleModel],
            ],
        ],
        'models' => [
            'providers' => [
                'ollama' => [
                    'models' => [
                        ['id' => 'qwen3:8b', 'name' => 'Qwen 3', 'thinking' => true],
                    ],
                ],
            ],
        ],
    ];

    file_put_contents(
        $workspacePath . '/openclaw.json',
        json_encode($configData, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
    );

    $configManager = new ConfigManager($workspacePath, $workspacePath, new DefaultsLoader());
    $config = $configManager->load();
    $roleResolver = new RoleResolver($config);

    $reflection = new ReflectionClass(BootManager::class);
    /** @var BootManager $boot */
    $boot = $reflection->newInstanceWithoutConstructor();

    $initializer = function () use ($workspacePath, $config, $configManager, $roleResolver): void {
        $this->workspacePath = $workspacePath;
        $this->config = $config;
        $this->configManager = $configManager;
        $this->roleResolver = $roleResolver;
    };
    \Closure::bind($initializer, $boot, BootManager::class)();

    $output = new BufferedOutput();

    return [
        'workspacePath' => $workspacePath,
        'boot' => $boot,
        'handler' => new ThinkingHandler($boot),
        'io' => new SymfonyStyle(new ArrayInput([]), $output),
        'output' => $output,
    ];
}

function cleanupThinkingHandlerFixture(array $fixture): void
{
    cleanupTestTree($fixture['workspacePath']);
}

function thinkingFixtureConfigEntry(array $fixture): array
{
    $data = json_decode(
        (string) file_get_contents($fixture['workspacePath'] . '/openclaw.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    return $data['models']['providers']['ollama']['models'][0];
}

test('thinking off persists reasoningEffort none and updates the live config', function () {
    $fixture = createThinkingHandlerFixture();

    try {
        $fixture['handler']->handle($fixture['io'], 'off', 'orchestrator');

        expect(thinkingFixtureConfigEntry($fixture)['reasoningEffort'] ?? null)->toBe('none');

        $definition = $fixture['boot']->config()->getModelDefinition('ollama/qwen3:8b');
        expect($definition->extras['reasoningEffort'] ?? null)->toBe('none');
    } finally {
        cleanupThinkingHandlerFixture($fixture);
    }
});

test('thinking level persists the given reasoning effort', function () {
    $fixture = createThinkingHandlerFixture();

    try {
        $fixture['handler']->handle($fixture['io'], 'low', 'orchestrator');

        expect(thinkingFixtureConfigEntry($fixture)['reasoningEffort'] ?? null)->toBe('low');
    } finally {
        cleanupThinkingHandlerFixture($fixture);
    }
});

test('thinking clear removes the reasoningEffort key', function () {
    $fixture = createThinkingHandlerFixture();

    try {
        $fixture['handler']->handle($fixture['io'], 'high', 'orchestrator');
        $fixture['handler']->handle($fixture['io'], 'clear', 'orchestrator');

        expect(thinkingFixtureConfigEntry($fixture))->not->toHaveKey('reasoningEffort');

        $definition = $fixture['boot']->config()->getModelDefinition('ollama/qwen3:8b');
        expect($definition->extras)->not->toHaveKey('reasoningEffort');
    } finally {
        cleanupThinkingHandlerFixture($fixture);
    }
});

test('thinking warns without writing when the model has no config entry', function () {
    $fixture = createThinkingHandlerFixture(roleModel: 'ollama/unlisted:1b');

    try {
        $fixture['handler']->handle($fixture['io'], 'off', 'orchestrator');

        $display = $fixture['output']->fetch();
        expect($display)->toContain('has no entry')
            ->and(thinkingFixtureConfigEntry($fixture))->not->toHaveKey('reasoningEffort');
    } finally {
        cleanupThinkingHandlerFixture($fixture);
    }
});

test('thinking rejects invalid levels', function () {
    $fixture = createThinkingHandlerFixture();

    try {
        $fixture['handler']->handle($fixture['io'], 'turbo', 'orchestrator');

        $display = $fixture['output']->fetch();
        expect($display)->toContain('Usage: /thinking')
            ->and(thinkingFixtureConfigEntry($fixture))->not->toHaveKey('reasoningEffort');
    } finally {
        cleanupThinkingHandlerFixture($fixture);
    }
});

test('thinking with no argument shows status', function () {
    $fixture = createThinkingHandlerFixture();

    try {
        $fixture['handler']->handle($fixture['io'], '', 'orchestrator');

        $display = $fixture['output']->fetch();
        expect($display)->toContain('ollama/qwen3:8b')
            ->and($display)->toContain('model default');
    } finally {
        cleanupThinkingHandlerFixture($fixture);
    }
});
