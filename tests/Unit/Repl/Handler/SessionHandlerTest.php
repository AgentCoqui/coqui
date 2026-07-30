<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\PersonaDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Repl\Handler\SessionHandler;
use CoquiBot\Coqui\Memory\MemoryStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Support\PersonaSessionLifecycleManager;
use CarmeloSantana\PHPAgents\Provider\ProviderFactory;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

function createReplSessionHandlerFixture(): array
{
    $workspacePath = sys_get_temp_dir() . '/coqui-repl-session-handler-' . bin2hex(random_bytes(8));
    mkdir($workspacePath, 0755, true);
    mkdir($workspacePath . '/profiles', 0755, true);

    $dbPath = $workspacePath . '/coqui.db';
    $storage = new SessionStorage($dbPath);
    $config = OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'ollama/qwen3:latest'],
                'roles' => [
                    'orchestrator' => 'ollama/qwen3:latest',
                ],
            ],
        ],
    ]);
    $roleResolver = new RoleResolver($config);
    $lifecycleManager = new PersonaSessionLifecycleManager(
        storage: $storage,
        providerFactory: new ProviderFactory($config),
        roleResolver: $roleResolver,
        memoryStore: new MemoryStore($workspacePath . '/memory.db'),
    );
    $boot = testBootManagerForSessionHandler($workspacePath, $roleResolver, new PersonaDiscovery($workspacePath));
    $output = new BufferedOutput();

    return [
        'workspacePath' => $workspacePath,
        'dbPath' => $dbPath,
        'storage' => $storage,
        'handler' => new SessionHandler($boot, $storage, $lifecycleManager),
        'io' => new SymfonyStyle(new ArrayInput([]), $output),
        'output' => $output,
    ];
}

function cleanupReplSessionHandlerFixture(array $fixture): void
{
    cleanupSqliteTestDb($fixture['dbPath']);
    cleanupTestTree($fixture['workspacePath']);
}

function testBootManagerForSessionHandler(string $workspacePath, RoleResolver $roleResolver, PersonaDiscovery $profileDiscovery): BootManager
{
    $reflection = new ReflectionClass(BootManager::class);
    /** @var BootManager $boot */
    $boot = $reflection->newInstanceWithoutConstructor();

    $initializer = function () use ($workspacePath, $roleResolver, $profileDiscovery): void {
        $this->workspacePath = $workspacePath;
        $this->roleResolver = $roleResolver;
        $this->profileDiscovery = $profileDiscovery;
    };

    \Closure::bind($initializer, $boot, BootManager::class)();

    return $boot;
}

function setSessionUpdatedAt(SessionStorage $storage, string $sessionId, string $timestamp): void
{
    $storage->getPdo()
        ->prepare('UPDATE sessions SET updated_at = :updated_at WHERE id = :id')
        ->execute([
            'updated_at' => $timestamp,
            'id' => $sessionId,
        ]);
}

function attachBackgroundTaskToSession(SessionStorage $storage, string $sessionId): void
{
    $storage->createTask($sessionId, 'Background task');
}

test('session handler creates and attaches a new default profile session when none exists', function () {
    $fixture = createReplSessionHandlerFixture();

    try {
        $sessionId = $fixture['handler']->loadOrCreateProfileSession($fixture['io'], 'caelum');
        $session = $fixture['storage']->getSession($sessionId);
        $sessionFile = $fixture['workspacePath'] . '/.coqui-session';

        expect($session)->not->toBeNull();
        expect($session['profile'])->toBe('caelum');
        expect($session['session_type'])->toBe('interactive');
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

test('session handler falls back to the latest matching profile session when attached session is out of scope', function () {
    $fixture = createReplSessionHandlerFixture();

    try {
        $attachedSessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');
        $targetSessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest', 'caelum');
        $olderSessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest', 'caelum');

        setSessionUpdatedAt($fixture['storage'], $olderSessionId, '2026-01-01T00:00:00+00:00');
        setSessionUpdatedAt($fixture['storage'], $targetSessionId, '2026-01-03T00:00:00+00:00');
        setSessionUpdatedAt($fixture['storage'], $attachedSessionId, '2026-01-04T00:00:00+00:00');

        file_put_contents($fixture['workspacePath'] . '/.coqui-session', $attachedSessionId);

        $resolvedSessionId = $fixture['handler']->loadOrCreateProfileSession($fixture['io'], 'caelum');

        expect($resolvedSessionId)->toBe($targetSessionId);
        expect(trim((string) file_get_contents($fixture['workspacePath'] . '/.coqui-session')))->toBe($targetSessionId);
        expect($fixture['output']->fetch())->toContain('Resumed latest profile session "caelum"');
    } finally {
        cleanupReplSessionHandlerFixture($fixture);
    }
});

test('session handler resumes the latest unprofiled session for plain startup', function () {
    $fixture = createReplSessionHandlerFixture();

    try {
        $profileSessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest', 'trinity');
        $unprofiledSessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');
        $olderUnprofiledSessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');

        setSessionUpdatedAt($fixture['storage'], $olderUnprofiledSessionId, '2026-01-01T00:00:00+00:00');
        setSessionUpdatedAt($fixture['storage'], $unprofiledSessionId, '2026-01-03T00:00:00+00:00');
        setSessionUpdatedAt($fixture['storage'], $profileSessionId, '2026-01-04T00:00:00+00:00');

        file_put_contents($fixture['workspacePath'] . '/.coqui-session', $profileSessionId);

        $resolvedSessionId = $fixture['handler']->loadOrCreateSession($fixture['io']);

        expect($resolvedSessionId)->toBe($unprofiledSessionId);
        expect(trim((string) file_get_contents($fixture['workspacePath'] . '/.coqui-session')))->toBe($unprofiledSessionId);
        expect($fixture['output']->fetch())->toContain('Resumed latest unprofiled session');
    } finally {
        cleanupReplSessionHandlerFixture($fixture);
    }
});

test('session handler ignores attached and latest background task sessions for unprofiled resume', function () {
    $fixture = createReplSessionHandlerFixture();

    try {
        $interactiveSessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');
        $backgroundSessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest', visibility: 'hidden');

        setSessionUpdatedAt($fixture['storage'], $interactiveSessionId, '2026-01-02T00:00:00+00:00');
        setSessionUpdatedAt($fixture['storage'], $backgroundSessionId, '2026-01-05T00:00:00+00:00');

        file_put_contents($fixture['workspacePath'] . '/.coqui-session', $backgroundSessionId);

        $resolvedSessionId = $fixture['handler']->loadOrCreateSession($fixture['io']);

        expect($resolvedSessionId)->toBe($interactiveSessionId);
        expect($fixture['output']->fetch())->toContain('Resumed latest unprofiled session');
    } finally {
        cleanupReplSessionHandlerFixture($fixture);
    }
});

test('session handler ignores background task sessions for profile resume', function () {
    $fixture = createReplSessionHandlerFixture();

    try {
        $interactiveSessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest', 'caelum');
        $backgroundSessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest', 'caelum', visibility: 'hidden');

        setSessionUpdatedAt($fixture['storage'], $interactiveSessionId, '2026-01-02T00:00:00+00:00');
        setSessionUpdatedAt($fixture['storage'], $backgroundSessionId, '2026-01-05T00:00:00+00:00');

        file_put_contents($fixture['workspacePath'] . '/.coqui-session', $backgroundSessionId);

        $resolvedSessionId = $fixture['handler']->loadOrCreateProfileSession($fixture['io'], 'caelum');

        expect($resolvedSessionId)->toBe($interactiveSessionId);
        expect($fixture['output']->fetch())->toContain('Resumed latest profile session "caelum"');
    } finally {
        cleanupReplSessionHandlerFixture($fixture);
    }
});

test('session handler keeps latest active profile session and closes older duplicates', function () {
    $fixture = createReplSessionHandlerFixture();

    try {
        $olderSessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest', 'caelum');
        $latestSessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest', 'caelum');

        setSessionUpdatedAt($fixture['storage'], $olderSessionId, '2026-01-01T00:00:00+00:00');
        setSessionUpdatedAt($fixture['storage'], $latestSessionId, '2026-01-03T00:00:00+00:00');

        file_put_contents($fixture['workspacePath'] . '/.coqui-session', $olderSessionId);

        $resolvedSessionId = $fixture['handler']->loadOrCreateProfileSession($fixture['io'], 'caelum');
        $olderSession = $fixture['storage']->getSession($olderSessionId);
        $visibleSessions = $fixture['storage']->listSessions(10, true, true);

        expect($resolvedSessionId)->toBe($latestSessionId);
        expect($olderSession['is_closed'])->toBe(1);
        expect($olderSession['is_archived'])->toBe(1);
        expect($visibleSessions)->toHaveCount(1);
        expect($visibleSessions[0]['id'])->toBe($latestSessionId);
        $output = preg_replace('/\s+/', ' ', $fixture['output']->fetch()) ?? '';

        expect($output)->toContain('Archived 1 older active session(s) for profile "caelum".');
    } finally {
        cleanupReplSessionHandlerFixture($fixture);
    }
});

test('session handler starts fresh profiled session by closing the current one', function () {
    $fixture = createReplSessionHandlerFixture();

    try {
        $currentSessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest', 'caelum');
        $fixture['storage']->addMessage($currentSessionId, 'user', 'Keep this continuity');
        $fixture['storage']->addMessage($currentSessionId, 'assistant', 'Acknowledged');

        $newSessionId = $fixture['handler']->startFreshSession(null, $currentSessionId, 'caelum');
        $currentSession = $fixture['storage']->getSession($currentSessionId);
        $newSession = $fixture['storage']->getSession((string) $newSessionId);

        expect($newSessionId)->not->toBeNull();
        expect($newSessionId)->not->toBe($currentSessionId);
        expect($currentSession['is_closed'])->toBe(1);
        expect($currentSession['closure_reason'])->toBe('repl_new_profile_session:caelum');
        expect($newSession)->not->toBeNull();
        expect($newSession['profile'])->toBe('caelum');
        expect($newSession['model_role'])->toBe('orchestrator');
        expect($newSession['session_type'])->toBe('interactive');
    } finally {
        cleanupReplSessionHandlerFixture($fixture);
    }
});

test('session handler rejects resume for closed sessions', function () {
    $fixture = createReplSessionHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');
        $fixture['storage']->closeSession($sessionId, 'test-close');

        $resolvedSessionId = $fixture['handler']->resume($fixture['io'], $sessionId);

        expect($resolvedSessionId)->toBeNull();
        $output = preg_replace('/\s+/', ' ', $fixture['output']->fetch()) ?? '';

        expect($output)->toContain('is closed');
    } finally {
        cleanupReplSessionHandlerFixture($fixture);
    }
});

test('session handler rejects resume for hidden internal sessions', function () {
    $fixture = createReplSessionHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('learner', 'background-task', visibility: 'hidden');

        $resolvedSessionId = $fixture['handler']->resume($fixture['io'], $sessionId);
        $output = preg_replace('/\s+/', ' ', $fixture['output']->fetch()) ?? '';

        expect($resolvedSessionId)->toBeNull();
        expect($output)->toContain('internal and cannot be resumed from the REPL');
    } finally {
        cleanupReplSessionHandlerFixture($fixture);
    }
});