<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\ChannelManager;
use CoquiBot\Coqui\Channel\ChannelDiscovery;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Storage\ChannelStore;
use CoquiBot\Coqui\Storage\SessionStorage;

/**
 * @return array{workspacePath: string, projectRoot: string, dbPath: string, storage: SessionStorage, store: ChannelStore}
 */
function makeChannelManagerFixture(): array
{
    $workspacePath = sys_get_temp_dir() . '/coqui-channel-manager-workspace-' . bin2hex(random_bytes(8));
    mkdir($workspacePath, 0755, true);

    $projectRoot = sys_get_temp_dir() . '/coqui-channel-manager-project-' . bin2hex(random_bytes(8));
    mkdir($projectRoot, 0755, true);

    $dbPath = $workspacePath . '/coqui.db';
    $storage = new SessionStorage($dbPath);

    return [
        'workspacePath' => $workspacePath,
        'projectRoot' => $projectRoot,
        'dbPath' => $dbPath,
        'storage' => $storage,
        'store' => new ChannelStore($storage->getPdo()),
    ];
}

test('channel manager reconciles configured instances into runtime state', function (): void {
    $fixture = makeChannelManagerFixture();
    $config = OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'openai/gpt-4.1-mini'],
            ],
        ],
        'channels' => [
            'instances' => [
                'signal-primary' => [
                    'driver' => 'signal',
                    'enabled' => true,
                ],
                'discord-ops' => [
                    'driver' => 'discord',
                    'enabled' => false,
                ],
            ],
        ],
    ]);

    try {
    $manager = new ChannelManager(
        config: $config,
        discovery: new ChannelDiscovery($fixture['projectRoot'], $fixture['workspacePath']),
        store: $fixture['store'],
        runtimeContext: ['workspacePath' => $fixture['workspacePath']],
    );

        $manager->reconcile();

        $signal = $fixture['store']->getByName('signal-primary');
        $discord = $fixture['store']->getByName('discord-ops');
        $stats = $manager->stats();

        expect($signal)->not->toBeNull();
        expect($signal['worker_status'])->toBe('placeholder');
        expect($discord)->not->toBeNull();
        expect($discord['worker_status'])->toBe('disabled');
        expect($stats['total'])->toBe(2);
        expect($stats['enabled'])->toBe(1);
        expect($stats['active_runtimes'])->toBe(1);
        expect($stats['registered_drivers'])->toBeGreaterThanOrEqual(3);
    } finally {
        releaseTestObjectProperties((object) $fixture);
        cleanupTestTree($fixture['workspacePath']);
        cleanupTestTree($fixture['projectRoot']);
    }
});