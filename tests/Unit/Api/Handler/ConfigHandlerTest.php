<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Handler\ConfigHandler;
use CoquiBot\Coqui\Config\ConfigValidator;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\ProfileDiscovery;
use React\Http\Message\ServerRequest;

function createApiConfigHandlerFixture(): array
{
    $workspacePath = sys_get_temp_dir() . '/coqui-config-handler-' . bin2hex(random_bytes(8));
    mkdir($workspacePath . '/profiles/caelum', 0755, true);
    mkdir($workspacePath . '/profiles/trinity', 0755, true);
    file_put_contents($workspacePath . '/profiles/caelum/soul.md', "# Caelum\n\nA calm companion.");
    file_put_contents($workspacePath . '/profiles/trinity/soul.md', "# Trinity\n\nA precise hacker and guide.");

    $config = OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'profile' => 'caelum',
                'model' => ['primary' => 'ollama/qwen3:latest'],
                'roles' => [
                    'orchestrator' => 'ollama/qwen3:latest',
                ],
            ],
        ],
    ]);

    return [
        'workspacePath' => $workspacePath,
        'handler' => new ConfigHandler($config, new ConfigValidator(), new ProfileDiscovery($workspacePath)),
    ];
}

function cleanupApiConfigHandlerFixture(array $fixture): void
{
    cleanupTestTree($fixture['workspacePath']);
}

test('config handler lists discovered profiles and default profile', function () {
    $fixture = createApiConfigHandlerFixture();

    try {
        $response = $fixture['handler']->profiles(new ServerRequest('GET', '/api/v1/config/profiles'));
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['count'])->toBe(2);
        expect($body['default_profile'])->toBe('caelum');
        expect(array_column($body['profiles'], 'name'))->toBe(['caelum', 'trinity']);
        expect($body['profiles'][0])->toHaveKeys(['name', 'display_name', 'description']);
    } finally {
        cleanupApiConfigHandlerFixture($fixture);
    }
});
