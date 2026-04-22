<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\ChannelManager;
use CoquiBot\Coqui\Api\Handler\ChannelHandler;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Channel\ChannelConfigurationEditor;
use CoquiBot\Coqui\Channel\ChannelDiscovery;
use CoquiBot\Coqui\Config\ConfigManager;
use CoquiBot\Coqui\Config\ConfigValidator;
use CoquiBot\Coqui\Config\DefaultsLoader;
use CoquiBot\Coqui\Config\ProfileDiscovery;
use CoquiBot\Coqui\Storage\ChannelStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use React\Http\Message\ServerRequest;

/**
 * @return array{workspacePath: string, projectRoot: string, dbPath: string, configManager: ConfigManager, store: ChannelStore, handler: ChannelHandler, router: Router}
 */
function makeApiChannelHandlerFixture(): array
{
    $workspacePath = sys_get_temp_dir() . '/coqui-api-channel-handler-' . bin2hex(random_bytes(8));
    $projectRoot = sys_get_temp_dir() . '/coqui-api-channel-project-' . bin2hex(random_bytes(8));
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
    $channelManager = new ChannelManager(
        config: $configManager->config(),
        discovery: $channelDiscovery,
        store: $store,
        configManager: $configManager,
        runtimeContext: ['workspacePath' => $workspacePath],
    );

    $handler = new ChannelHandler(
        $store,
        $channelManager,
        new ChannelConfigurationEditor($configManager, $channelDiscovery, $profileDiscovery),
        $channelDiscovery,
        $profileDiscovery,
    );
    $router = new Router();
    $handler->register($router);

    return [
        'workspacePath' => $workspacePath,
        'projectRoot' => $projectRoot,
        'dbPath' => $dbPath,
        'configManager' => $configManager,
        'store' => $store,
        'handler' => $handler,
        'router' => $router,
    ];
}

function cleanupApiChannelHandlerFixture(array $fixture): void
{
    cleanupSqliteTestDb($fixture['dbPath']);
    cleanupTestTree($fixture['workspacePath']);
    cleanupTestTree($fixture['projectRoot']);
}

test('channel api handler creates and lists configured channels', function (): void {
    $fixture = makeApiChannelHandlerFixture();

    try {
        $createRequest = new ServerRequest(
            'POST',
            '/api/v1/channels',
            ['Content-Type' => 'application/json'],
            json_encode([
                'name' => 'signal-primary',
                'driver' => 'signal',
                'displayName' => 'Signal Primary',
                'defaultProfile' => 'assistant',
                'settings' => ['transport' => 'signal-cli'],
            ], JSON_THROW_ON_ERROR),
        );

        $createResponse = $fixture['router']->dispatch($createRequest);
        $createBody = json_decode((string) $createResponse->getBody(), true);

        expect($createResponse->getStatusCode())->toBe(201);
        expect($createBody['channel']['name'])->toBe('signal-primary');
        expect($createBody['channel']['driver'])->toBe('signal');
        expect($createBody['channel']['worker_status'])->toBe('invalid_configuration');
        expect($createBody['channel']['last_error'])->toBe('signal settings.account is required');

        $listResponse = $fixture['router']->dispatch(new ServerRequest('GET', '/api/v1/channels'));
        $listBody = json_decode((string) $listResponse->getBody(), true);

        expect($listResponse->getStatusCode())->toBe(200);
        expect($listBody['channels'])->toHaveCount(1);
        expect($listBody['stats']['total'])->toBe(1);
    } finally {
        cleanupApiChannelHandlerFixture($fixture);
    }
});

test('channel api handler creates identity links for configured channels', function (): void {
    $fixture = makeApiChannelHandlerFixture();

    try {
        $fixture['router']->dispatch(new ServerRequest(
            'POST',
            '/api/v1/channels',
            ['Content-Type' => 'application/json'],
            json_encode([
                'name' => 'signal-primary',
                'driver' => 'signal',
                'defaultProfile' => 'assistant',
            ], JSON_THROW_ON_ERROR),
        ));

        $linkResponse = $fixture['router']->dispatch(new ServerRequest(
            'POST',
            '/api/v1/channels/signal-primary/links',
            ['Content-Type' => 'application/json'],
            json_encode([
                'remote_user_key' => 'signal:+15551234567',
                'profile' => 'assistant',
            ], JSON_THROW_ON_ERROR),
        ));
        $linkBody = json_decode((string) $linkResponse->getBody(), true);

        expect($linkResponse->getStatusCode())->toBe(201);
        expect($linkBody['link']['remote_user_key'])->toBe('signal:+15551234567');

        $linksResponse = $fixture['router']->dispatch(new ServerRequest('GET', '/api/v1/channels/signal-primary/links'));
        $linksBody = json_decode((string) $linksResponse->getBody(), true);

        expect($linksResponse->getStatusCode())->toBe(200);
        expect($linksBody['links'])->toHaveCount(1);
        expect($linksBody['links'][0]['profile'])->toBe('assistant');
    } finally {
        cleanupApiChannelHandlerFixture($fixture);
    }
});