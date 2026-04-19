<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Config\ConfigManager;
use CoquiBot\Coqui\Config\ConfigValidator;
use CoquiBot\Coqui\Config\DefaultsLoader;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\ProfileDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Repl\Handler\ProfileHandler;
use CoquiBot\Coqui\Repl\Handler\SessionHandler;
use CoquiBot\Coqui\Storage\SessionStorage;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

function createProfileHandlerFixture(): array
{
    $workspacePath = sys_get_temp_dir() . '/coqui-profile-handler-' . bin2hex(random_bytes(8));
    mkdir($workspacePath, 0755, true);
    mkdir($workspacePath . '/profiles', 0755, true);
    mkdir($workspacePath . '/profiles/caelum', 0755, true);
    file_put_contents($workspacePath . '/profiles/caelum/soul.md', "# Caelum\n\nA calm companion.");

    $dbPath = $workspacePath . '/coqui.db';
    $storage = new SessionStorage($dbPath);
    $profileDiscovery = new ProfileDiscovery($workspacePath);
    $projectRoot = $workspacePath . '/project';
    mkdir($projectRoot, 0755, true);
    $configManager = new ConfigManager(
        workspacePath: $workspacePath,
        projectRoot: $projectRoot,
        defaultsLoader: new DefaultsLoader(),
        validator: new ConfigValidator(),
    );
    $config = $configManager->load();
    $roleResolver = new RoleResolver(OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'ollama/qwen3:latest'],
                'roles' => [
                    'orchestrator' => 'ollama/qwen3:latest',
                ],
            ],
        ],
    ]));
    $boot = testBootManagerForProfiles($workspacePath, $profileDiscovery, $roleResolver, $configManager, $config);
    $sessionHandler = new SessionHandler($boot, $storage);
    $output = new BufferedOutput();

    return [
        'workspacePath' => $workspacePath,
        'dbPath' => $dbPath,
        'projectRoot' => $projectRoot,
        'storage' => $storage,
        'configManager' => $configManager,
        'handler' => new ProfileHandler($boot, $sessionHandler),
        'io' => new SymfonyStyle(new ArrayInput([]), $output),
        'output' => $output,
    ];
}

function cleanupProfileHandlerFixture(array $fixture): void
{
    cleanupSqliteTestDb($fixture['dbPath']);
    cleanupTestTree($fixture['workspacePath']);
}

function testBootManagerForProfiles(string $workspacePath, ProfileDiscovery $profileDiscovery, RoleResolver $roleResolver, ConfigManager $configManager, OpenClawConfig $config): BootManager
{
    $reflection = new ReflectionClass(BootManager::class);
    /** @var BootManager $boot */
    $boot = $reflection->newInstanceWithoutConstructor();

    $initializer = function () use ($workspacePath, $profileDiscovery, $roleResolver, $configManager, $config): void {
        $this->workspacePath = $workspacePath;
        $this->profileDiscovery = $profileDiscovery;
        $this->roleResolver = $roleResolver;
        $this->configManager = $configManager;
        $this->config = $config;
    };

    \Closure::bind($initializer, $boot, BootManager::class)();

    return $boot;
}

test('profile handler shows configured default profile state', function () {
    $fixture = createProfileHandlerFixture();

    try {
        $existingSession = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest', 'caelum');

        $result = $fixture['handler']->handleProfile($fixture['io'], 'default', 'orchestrator', 'caelum');
        $sessions = $fixture['storage']->listSessions(10, false);

        expect($result->shouldContinue)->toBeTrue();
        expect($result->newSessionId)->toBeNull();
        expect($result->newActiveProfile)->toBeNull();
        expect($sessions)->toHaveCount(1);
        expect($sessions[0]['id'])->toBe($existingSession);
        expect($fixture['output']->fetch())->toContain('Configured default profile:');
    } finally {
        cleanupProfileHandlerFixture($fixture);
    }
});

test('profile handler can set the configured default profile', function () {
    $fixture = createProfileHandlerFixture();

    try {
        $result = $fixture['handler']->handleProfile($fixture['io'], 'default caelum', 'orchestrator', null);

        expect($result->shouldContinue)->toBeTrue();
        expect($fixture['configManager']->config()->getDefaultProfile())->toBe('caelum');
        expect($fixture['output']->fetch())->toContain('Default profile set to "caelum"');
    } finally {
        cleanupProfileHandlerFixture($fixture);
    }
});

test('profile handler can clear the configured default profile', function () {
    $fixture = createProfileHandlerFixture();

    try {
        $fixture['configManager']->set('agents.defaults.profile', 'caelum');

        $result = $fixture['handler']->handleProfile($fixture['io'], 'default none', 'orchestrator', null);

        expect($result->shouldContinue)->toBeTrue();
        expect($fixture['configManager']->config()->getDefaultProfile())->toBeNull();
        expect($fixture['output']->fetch())->toContain('Default profile cleared');
    } finally {
        cleanupProfileHandlerFixture($fixture);
    }
});

test('profile handler creates a new profiled session when switching profiles', function () {
    $fixture = createProfileHandlerFixture();

    try {
        $result = $fixture['handler']->handleProfile($fixture['io'], 'caelum', 'orchestrator', null);
        $session = $result->newSessionId !== null ? $fixture['storage']->getSession($result->newSessionId) : null;

        expect($result->shouldContinue)->toBeTrue();
        expect($result->newSessionId)->not->toBeNull();
        expect($result->newActiveProfile)->toBe('caelum');
        expect($session)->not->toBeNull();
        expect($session['profile'])->toBe('caelum');
    } finally {
        cleanupProfileHandlerFixture($fixture);
    }
});