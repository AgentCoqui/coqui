<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Repl\Handler\SessionHandler;
use CoquiBot\Coqui\Storage\SessionStorage;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

function createReplSessionHandlerFixture(): array
{
    $workspacePath = sys_get_temp_dir() . '/coqui-repl-session-handler-' . bin2hex(random_bytes(8));
    mkdir($workspacePath, 0755, true);

    $dbPath = $workspacePath . '/coqui.db';
    $storage = new SessionStorage($dbPath);
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
    $boot = testBootManagerForSessionHandler($workspacePath, $roleResolver);
    $output = new BufferedOutput();

    return [
        'workspacePath' => $workspacePath,
        'dbPath' => $dbPath,
        'storage' => $storage,
        'handler' => new SessionHandler($boot, $storage),
        'io' => new SymfonyStyle(new ArrayInput([]), $output),
        'output' => $output,
    ];
}

function cleanupReplSessionHandlerFixture(array $fixture): void
{
    cleanupSqliteTestDb($fixture['dbPath']);
    cleanupTestTree($fixture['workspacePath']);
}

function testBootManagerForSessionHandler(string $workspacePath, RoleResolver $roleResolver): BootManager
{
    $reflection = new ReflectionClass(BootManager::class);
    /** @var BootManager $boot */
    $boot = $reflection->newInstanceWithoutConstructor();

    $initializer = function () use ($workspacePath, $roleResolver): void {
        $this->workspacePath = $workspacePath;
        $this->roleResolver = $roleResolver;
    };

    \Closure::bind($initializer, $boot, BootManager::class)();

    return $boot;
}

test('session handler creates and attaches a new default profile session when none exists', function () {
    $fixture = createReplSessionHandlerFixture();

    try {
        $sessionId = $fixture['handler']->loadOrCreateProfileSession($fixture['io'], 'caelum');
        $session = $fixture['storage']->getSession($sessionId);
        $sessionFile = $fixture['workspacePath'] . '/.coqui-session';

        expect($session)->not->toBeNull();
        expect($session['profile'])->toBe('caelum');
        expect(trim((string) file_get_contents($sessionFile)))->toBe($sessionId);
    } finally {
        cleanupReplSessionHandlerFixture($fixture);
    }
});

test('session handler reuses the attached session when it matches the default profile', function () {
    $fixture = createReplSessionHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest', 'caelum');
        file_put_contents($fixture['workspacePath'] . '/.coqui-session', $sessionId);

        $resolvedSessionId = $fixture['handler']->loadOrCreateProfileSession($fixture['io'], 'caelum');

        expect($resolvedSessionId)->toBe($sessionId);
        expect($fixture['output']->fetch())->toContain('Resumed attached profile session "caelum"');
    } finally {
        cleanupReplSessionHandlerFixture($fixture);
    }
});