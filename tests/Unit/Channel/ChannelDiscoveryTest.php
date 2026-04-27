<?php

declare(strict_types=1);

use CoquiBot\Coqui\Channel\ChannelDiscovery;
use CoquiBot\Coqui\Tests\Support\Channel\TestExternalChannelDriver;

/**
 * @return array{projectRoot: string, workspacePath: string}
 */
function makeChannelDiscoveryFixture(): array
{
    $projectRoot = sys_get_temp_dir() . '/coqui-channel-discovery-project-' . bin2hex(random_bytes(8));
    $workspacePath = sys_get_temp_dir() . '/coqui-channel-discovery-workspace-' . bin2hex(random_bytes(8));

    mkdir($projectRoot . '/vendor/composer', 0755, true);
    mkdir($workspacePath, 0755, true);

    return [
        'projectRoot' => $projectRoot,
        'workspacePath' => $workspacePath,
    ];
}

test('channel discovery registers built-in drivers by default', function (): void {
    $fixture = makeChannelDiscoveryFixture();

    try {
        $discovery = new ChannelDiscovery($fixture['projectRoot'], $fixture['workspacePath']);

        expect($discovery->driverNames())->toContain('signal', 'telegram', 'discord');
    } finally {
        cleanupTestTree($fixture['projectRoot']);
        cleanupTestTree($fixture['workspacePath']);
    }
});

test('channel discovery loads external driver declarations from composer metadata', function (): void {
    $fixture = makeChannelDiscoveryFixture();

    try {
    file_put_contents(
        $fixture['projectRoot'] . '/vendor/composer/installed.json',
        json_encode([
            'packages' => [[
                'name' => 'acme/test-channel',
                'extra' => [
                    'coqui' => [
                        'channels' => [TestExternalChannelDriver::class],
                    ],
                ],
            ]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    );

        $discovery = new ChannelDiscovery($fixture['projectRoot'], $fixture['workspacePath']);
        $newDrivers = $discovery->discoverAll();

        expect($newDrivers)->toContain('test-external');
        expect($discovery->driver('test-external'))->toBeInstanceOf(TestExternalChannelDriver::class);
        expect($discovery->packages()['test-external'])->toBe('acme/test-channel');
    } finally {
        cleanupTestTree($fixture['projectRoot']);
        cleanupTestTree($fixture['workspacePath']);
    }
});