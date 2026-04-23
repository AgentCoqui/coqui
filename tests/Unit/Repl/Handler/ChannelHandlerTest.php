<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\ChannelManager;
use CoquiBot\Coqui\Channel\ChannelConfigurationEditor;
use CoquiBot\Coqui\Channel\ChannelDiscovery;
use CoquiBot\Coqui\Config\ConfigManager;
use CoquiBot\Coqui\Config\ConfigValidator;
use CoquiBot\Coqui\Config\DefaultsLoader;
use CoquiBot\Coqui\Config\ProfileDiscovery;
use CoquiBot\Coqui\Repl\Handler\ChannelHandler;
use CoquiBot\Coqui\Storage\ChannelStore;
use CoquiBot\Coqui\Storage\RuntimeStateStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @return array{workspacePath: string, projectRoot: string, dbPath: string, configManager: ConfigManager, store: ChannelStore, manager: ChannelManager, handler: ChannelHandler, io: SymfonyStyle, output: BufferedOutput}
 */
function makeReplChannelHandlerFixture(): array
{
    $workspacePath = sys_get_temp_dir() . '/coqui-repl-channel-handler-' . bin2hex(random_bytes(8));
    $projectRoot = sys_get_temp_dir() . '/coqui-repl-channel-project-' . bin2hex(random_bytes(8));
    mkdir($workspacePath . '/profiles/assistant', 0755, true);
    mkdir($projectRoot, 0755, true);
    file_put_contents($workspacePath . '/profiles/assistant/soul.md', '# Assistant');
    file_put_contents($workspacePath . '/openclaw.json', json_encode([
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'openai/gpt-4.1-mini'],
                'roles' => ['orchestrator' => 'openai/gpt-4.1-mini'],
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    $dbPath = $workspacePath . '/coqui.db';
    $storage = new SessionStorage($dbPath);
    $store = new ChannelStore($storage->getPdo());
    $configManager = new ConfigManager($workspacePath, $projectRoot, new DefaultsLoader(), new ConfigValidator());
    $configManager->load();
    $profileDiscovery = new ProfileDiscovery($workspacePath);
    $channelDiscovery = new ChannelDiscovery($projectRoot, $workspacePath);
    $manager = new ChannelManager(
        config: $configManager->config(),
        discovery: $channelDiscovery,
        store: $store,
        configManager: $configManager,
        runtimeContext: ['workspacePath' => $workspacePath],
    );
    $output = new BufferedOutput();

    return [
        'workspacePath' => $workspacePath,
        'projectRoot' => $projectRoot,
        'dbPath' => $dbPath,
        'configManager' => $configManager,
        'store' => $store,
        'manager' => $manager,
        'handler' => new ChannelHandler(
            $store,
            new ChannelConfigurationEditor($configManager, $channelDiscovery, $profileDiscovery, $storage),
            $channelDiscovery,
            $profileDiscovery,
            new RuntimeStateStore($storage->getPdo()),
        ),
        'io' => new SymfonyStyle(new ArrayInput([]), $output),
        'output' => $output,
    ];
}

function cleanupReplChannelHandlerFixture(array $fixture): void
{
    cleanupSqliteTestDb($fixture['dbPath']);
    cleanupTestTree($fixture['workspacePath']);
    cleanupTestTree($fixture['projectRoot']);
}

test('channel repl handler adds channels to workspace config', function (): void {
    $fixture = makeReplChannelHandlerFixture();

    try {
        $fixture['handler']->handle($fixture['io'], 'add signal signal-primary');
        $display = $fixture['output']->fetch();
        $config = $fixture['configManager']->toArray();

        expect($display)->toContain('Saved channel "signal-primary"');
        expect($config['channels']['instances']['signal-primary']['driver'])->toBe('signal');
        expect($config['channels']['instances']['signal-primary']['enabled'])->toBeTrue();
    } finally {
        cleanupReplChannelHandlerFixture($fixture);
    }
});

test('channel repl handler manages identity links', function (): void {
    $fixture = makeReplChannelHandlerFixture();

    try {
        $fixture['handler']->handle($fixture['io'], 'add signal signal-primary');
        $fixture['manager']->reconcile();
        $fixture['handler']->handle($fixture['io'], 'link signal-primary signal:+15551234567 assistant');
        $fixture['output']->fetch();

        $fixture['handler']->handle($fixture['io'], 'links signal-primary');
        $display = $fixture['output']->fetch();

        expect($display)->toContain('signal:+15551234567');
        expect($display)->toContain('assistant');
    } finally {
        cleanupReplChannelHandlerFixture($fixture);
    }
});

test('channel repl handler sets a bound session id', function (): void {
    $fixture = makeReplChannelHandlerFixture();

    try {
        $sessionId = (new SessionStorage($fixture['dbPath']))->createSession('orchestrator', 'openai/gpt-4.1-mini');
        $fixture['handler']->handle($fixture['io'], 'add signal signal-primary +15551234567');
        $fixture['output']->fetch();

        $fixture['handler']->handle($fixture['io'], 'set signal-primary boundSessionId ' . $sessionId);
        $display = $fixture['output']->fetch();
        $config = $fixture['configManager']->toArray();

        expect($display)->toContain('Updated channel "signal-primary"');
        expect($config['channels']['instances']['signal-primary']['boundSessionId'])->toBe($sessionId);
    } finally {
        cleanupReplChannelHandlerFixture($fixture);
    }
});