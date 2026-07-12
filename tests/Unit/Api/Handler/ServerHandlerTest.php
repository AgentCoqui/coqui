<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\AgentTurnManager;
use CoquiBot\Coqui\Api\BackgroundTaskManager;
use CoquiBot\Coqui\Api\Handler\HealthHandler;
use CoquiBot\Coqui\Api\Handler\ServerHandler;
use CoquiBot\Coqui\Storage\ScheduleStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use React\Http\Message\ServerRequest;

function createServerHandlerFixture(): array
{
    $workspacePath = '/tmp/coqui';
    $dbPath = sys_get_temp_dir() . '/coqui-server-handler-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    $scheduleStore = new ScheduleStore($storage->getPdo());

    return [
        'workspacePath' => $workspacePath,
        'dbPath' => $dbPath,
        'storage' => $storage,
        'scheduleStore' => $scheduleStore,
        'turnManager' => new AgentTurnManager($storage, $workspacePath, '/tmp/openclaw.json', '/tmp'),
        'taskManager' => new BackgroundTaskManager($storage, $workspacePath, '/tmp/openclaw.json', '/tmp'),
    ];
}

function cleanupServerHandlerFixture(array $fixture): void
{
    if (file_exists($fixture['dbPath'])) {
        unlink($fixture['dbPath']);
    }
}

test('server info and health share the same app version source', function () {
    $fixture = createServerHandlerFixture();
    $original = getenv('COQUI_VERSION');
    putenv('COQUI_VERSION=7.8.9');

    try {
        $serverHandler = new ServerHandler(
            storage: $fixture['storage'],
            startTime: microtime(true) - 5,
            turnManager: $fixture['turnManager'],
            workspacePath: $fixture['workspacePath'],
            databasePath: $fixture['dbPath'],
            taskManager: $fixture['taskManager'],
        );
        $healthHandler = new HealthHandler(
            startTime: microtime(true) - 5,
            turnManager: $fixture['turnManager'],
            workspacePath: $fixture['workspacePath'],
            databasePath: $fixture['dbPath'],
            taskManager: $fixture['taskManager'],
            scheduleStore: $fixture['scheduleStore'],
        );

        $serverResponse = $serverHandler->info(new ServerRequest('GET', '/api/v1/server/info'));
        $healthResponse = $healthHandler(new ServerRequest('GET', '/api/v1/health'));

        $serverBody = json_decode((string) $serverResponse->getBody(), true);
        $healthBody = json_decode((string) $healthResponse->getBody(), true);

        expect($serverBody['version'])->toBe('7.8.9');
        expect($healthBody['version'])->toBe('7.8.9');
    } finally {
        if ($original === false) {
            putenv('COQUI_VERSION');
        } else {
            putenv("COQUI_VERSION={$original}");
        }

        cleanupServerHandlerFixture($fixture);
    }
});
