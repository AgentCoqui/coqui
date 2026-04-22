<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\ProfileDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Repl\Handler\GroupHandler;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Support\GroupSessionService;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

function createGroupHandlerFixture(): array
{
    $workspacePath = sys_get_temp_dir() . '/coqui-group-handler-' . bin2hex(random_bytes(8));
    mkdir($workspacePath, 0755, true);
    mkdir($workspacePath . '/profiles', 0755, true);

    foreach (['caelum', 'nova', 'iris'] as $profile) {
        mkdir($workspacePath . '/profiles/' . $profile, 0755, true);
        file_put_contents($workspacePath . '/profiles/' . $profile . '/soul.md', '# ' . ucfirst($profile));
    }

    $dbPath = $workspacePath . '/coqui.db';
    $storage = new SessionStorage($dbPath);
    $profileDiscovery = new ProfileDiscovery($workspacePath);
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

    $output = new BufferedOutput();

    return [
        'workspacePath' => $workspacePath,
        'dbPath' => $dbPath,
        'storage' => $storage,
        'handler' => new GroupHandler(
            new GroupSessionService($storage, $roleResolver, $profileDiscovery),
            $storage,
        ),
        'io' => new SymfonyStyle(new ArrayInput([]), $output),
        'output' => $output,
    ];
}

function cleanupGroupHandlerFixture(array $fixture): void
{
    cleanupSqliteTestDb($fixture['dbPath']);
    cleanupTestTree($fixture['workspacePath']);
}

test('group handler starts a group session and returns a session state change', function () {
    $fixture = createGroupHandlerFixture();

    try {
        $currentSessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');

        $result = $fixture['handler']->handle($fixture['io'], 'start caelum,nova --rounds=4', $currentSessionId);
        $session = $result->newSessionId !== null ? $fixture['storage']->getSession($result->newSessionId) : null;

        expect($result->shouldContinue)->toBeTrue();
        expect($result->newSessionId)->not->toBeNull();
        expect($result->newActiveRole)->toBe('orchestrator');
        expect($session)->not->toBeNull();
        expect($session['group_enabled'])->toBe(1);
        expect($session['group_max_rounds'])->toBe(4);
        expect(array_column($session['group_members'], 'profile'))->toBe(['caelum', 'nova']);
        expect($fixture['output']->fetch())->toContain('Started group session');
    } finally {
        cleanupGroupHandlerFixture($fixture);
    }
});

test('group handler reuses an active group session with the same composition', function () {
    $fixture = createGroupHandlerFixture();

    try {
        $currentSessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');
        $existingGroupId = $fixture['storage']->createGroupSession('orchestrator', 'ollama/qwen3:latest', ['caelum', 'nova'], 3);

        $result = $fixture['handler']->handle($fixture['io'], 'start nova caelum', $currentSessionId);

        expect($result->newSessionId)->toBe($existingGroupId);
        expect($fixture['output']->fetch())->toContain('Resumed group session');
    } finally {
        cleanupGroupHandlerFixture($fixture);
    }
});

test('group handler updates membership and rounds for the current group session', function () {
    $fixture = createGroupHandlerFixture();

    try {
        $groupSessionId = $fixture['storage']->createGroupSession('orchestrator', 'ollama/qwen3:latest', ['caelum', 'nova'], 3);

        $fixture['handler']->handle($fixture['io'], 'add iris', $groupSessionId);
        $afterAdd = $fixture['storage']->getSession($groupSessionId);
        expect(array_column($afterAdd['group_members'], 'profile'))->toBe(['caelum', 'iris', 'nova']);

        $fixture['handler']->handle($fixture['io'], 'rounds 5', $groupSessionId);
        $afterRounds = $fixture['storage']->getSession($groupSessionId);
        expect($afterRounds['group_max_rounds'])->toBe(5);

        $fixture['handler']->handle($fixture['io'], 'remove nova', $groupSessionId);
        $afterRemove = $fixture['storage']->getSession($groupSessionId);
        expect(array_column($afterRemove['group_members'], 'profile'))->toBe(['caelum', 'iris']);
    } finally {
        cleanupGroupHandlerFixture($fixture);
    }
});

test('group handler shows status for the current group session', function () {
    $fixture = createGroupHandlerFixture();

    try {
        $groupSessionId = $fixture['storage']->createGroupSession('orchestrator', 'ollama/qwen3:latest', ['caelum', 'nova'], 3);

        $result = $fixture['handler']->handle($fixture['io'], 'status', $groupSessionId);
        $display = $fixture['output']->fetch();

        expect($result->shouldContinue)->toBeTrue();
        expect($display)->toContain('Group Session');
        expect($display)->toContain('@caelum, @nova');
        expect($display)->toContain('3');
        expect($display)->toContain('@everyone/@group');
    } finally {
        cleanupGroupHandlerFixture($fixture);
    }
});