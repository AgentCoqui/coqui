<?php

declare(strict_types=1);

use CoquiBot\Coqui\Command\RunCommand;
use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Repl\Handler\SessionHandler;
use CoquiBot\Coqui\Storage\SessionStorage;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

function createRunCommandSessionFixture(): array
{
    $workspacePath = sys_get_temp_dir() . '/coqui-run-command-session-' . bin2hex(random_bytes(8));
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

    $boot = testBootManagerForRunCommand($workspacePath, $roleResolver);
    $output = new BufferedOutput();
    $command = new RunCommand();

    return [
        'workspacePath' => $workspacePath,
        'dbPath' => $dbPath,
        'storage' => $storage,
        'handler' => new SessionHandler($boot, $storage),
        'command' => $command,
        'io' => new SymfonyStyle(new ArrayInput([], $command->getDefinition()), $output),
        'output' => $output,
    ];
}

function cleanupRunCommandSessionFixture(array $fixture): void
{
    cleanupSqliteTestDb($fixture['dbPath']);
    cleanupTestTree($fixture['workspacePath']);
}

function testBootManagerForRunCommand(string $workspacePath, RoleResolver $roleResolver): BootManager
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

function setRunCommandProperty(RunCommand $command, string $property, mixed $value): void
{
    $reflection = new ReflectionProperty(RunCommand::class, $property);
    $reflection->setAccessible(true);
    $reflection->setValue($command, $value);
}

function invokeRunCommandStartupSelection(
    RunCommand $command,
    ArrayInput $input,
    SessionHandler $sessionHandler,
    ?SymfonyStyle $io,
    bool $headless,
): string {
    $reflection = new ReflectionMethod(RunCommand::class, 'resolveAutomaticStartupSessionId');
    $reflection->setAccessible(true);

    /** @var string $sessionId */
    $sessionId = $reflection->invoke($command, $input, $sessionHandler, $io, $headless);

    return $sessionId;
}

function setRunCommandSessionUpdatedAt(SessionStorage $storage, string $sessionId, string $timestamp): void
{
    $storage->getPdo()
        ->prepare('UPDATE sessions SET updated_at = :updated_at WHERE id = :id')
        ->execute([
            'updated_at' => $timestamp,
            'id' => $sessionId,
        ]);
}

test('run command startup selection resumes requested profile scope in terminal mode', function () {
    $fixture = createRunCommandSessionFixture();

    try {
        $profileSessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest', 'caelum');
        $unprofiledSessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');
        setRunCommandSessionUpdatedAt($fixture['storage'], $unprofiledSessionId, '2026-01-01T00:00:00+00:00');
        setRunCommandSessionUpdatedAt($fixture['storage'], $profileSessionId, '2026-01-03T00:00:00+00:00');

        $input = new ArrayInput(['--profile' => 'caelum'], $fixture['command']->getDefinition());
        $sessionId = invokeRunCommandStartupSelection($fixture['command'], $input, $fixture['handler'], $fixture['io'], false);

        expect($sessionId)->toBe($profileSessionId);
    } finally {
        cleanupRunCommandSessionFixture($fixture);
    }
});

test('run command startup selection resumes default unprofiled scope in terminal mode', function () {
    $fixture = createRunCommandSessionFixture();

    try {
        $profileSessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest', 'caelum');
        $unprofiledSessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');
        setRunCommandSessionUpdatedAt($fixture['storage'], $unprofiledSessionId, '2026-01-03T00:00:00+00:00');
        setRunCommandSessionUpdatedAt($fixture['storage'], $profileSessionId, '2026-01-04T00:00:00+00:00');

        $input = new ArrayInput([], $fixture['command']->getDefinition());
        $sessionId = invokeRunCommandStartupSelection($fixture['command'], $input, $fixture['handler'], $fixture['io'], false);

        expect($sessionId)->toBe($unprofiledSessionId);
    } finally {
        cleanupRunCommandSessionFixture($fixture);
    }
});

test('run command startup selection uses attached session for continue mode', function () {
    $fixture = createRunCommandSessionFixture();

    try {
        $attachedSessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest', 'caelum');
        $otherSessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');
        setRunCommandSessionUpdatedAt($fixture['storage'], $otherSessionId, '2026-01-04T00:00:00+00:00');
        file_put_contents($fixture['workspacePath'] . '/.coqui-session', $attachedSessionId);

        setRunCommandProperty($fixture['command'], 'continueMode', true);

        $input = new ArrayInput([], $fixture['command']->getDefinition());
        $sessionId = invokeRunCommandStartupSelection($fixture['command'], $input, $fixture['handler'], $fixture['io'], false);

        expect($sessionId)->toBe($attachedSessionId);
    } finally {
        cleanupRunCommandSessionFixture($fixture);
    }
});

test('run command startup selection uses configured default profile in headless mode', function () {
    $fixture = createRunCommandSessionFixture();

    try {
        $profileSessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest', 'caelum');
        $unprofiledSessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');
        setRunCommandSessionUpdatedAt($fixture['storage'], $unprofiledSessionId, '2026-01-01T00:00:00+00:00');
        setRunCommandSessionUpdatedAt($fixture['storage'], $profileSessionId, '2026-01-03T00:00:00+00:00');

        setRunCommandProperty($fixture['command'], 'configuredDefaultProfile', 'caelum');

        $input = new ArrayInput([], $fixture['command']->getDefinition());
        $sessionId = invokeRunCommandStartupSelection($fixture['command'], $input, $fixture['handler'], null, true);

        expect($sessionId)->toBe($profileSessionId);
    } finally {
        cleanupRunCommandSessionFixture($fixture);
    }
});
