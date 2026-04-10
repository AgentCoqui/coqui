<?php

declare(strict_types=1);

use CoquiBot\Coqui\Agent\QualityAutomationCoordinator;
use CoquiBot\Coqui\Agent\QualityAutomationStatusService;
use CoquiBot\Coqui\Api\AgentTurnManager;
use CoquiBot\Coqui\Api\BackgroundTaskManager;
use CoquiBot\Coqui\Api\Handler\HealthHandler;
use CoquiBot\Coqui\Api\Handler\ServerHandler;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Storage\EvaluationStore;
use CoquiBot\Coqui\Storage\ScheduleStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use React\Http\Message\ServerRequest;

function createServerHandlerFixture(): array
{
    $dbPath = sys_get_temp_dir() . '/coqui-server-handler-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    $evaluationStore = new EvaluationStore($storage->getPdo());
    $scheduleStore = new ScheduleStore($storage->getPdo());

    $config = OpenClawConfig::fromArray([]);
    $coordinator = new QualityAutomationCoordinator(
        config: $config,
        storage: $storage,
        scheduleStore: $scheduleStore,
        evaluationStore: $evaluationStore,
    );
    $coordinator->ensureDefaultSchedules();

    $sessionId = $storage->createSession('orchestrator', 'test-model');
    $evaluationId = $evaluationStore->create(
        sessionId: $sessionId,
        overallGrade: 'D',
        scoreCompletion: 0.4,
        scoreHallucination: 0.4,
        scoreEfficiency: 0.4,
        overallScore: 0.4,
        report: 'Poor evaluation',
    );
    $coordinator->queueLearnerFollowUp($evaluationId, $sessionId, 'D', 0.4);

    $qualityStatus = new QualityAutomationStatusService(
        config: $config,
        storage: $storage,
        evaluationStore: $evaluationStore,
        scheduleStore: $scheduleStore,
    );

    return [
        'dbPath' => $dbPath,
        'storage' => $storage,
        'scheduleStore' => $scheduleStore,
        'qualityStatus' => $qualityStatus,
        'turnManager' => new AgentTurnManager($storage, '/tmp/coqui', '/tmp/openclaw.json', '/tmp'),
        'taskManager' => new BackgroundTaskManager($storage, '/tmp/coqui', '/tmp/openclaw.json', '/tmp'),
    ];
}

function cleanupServerHandlerFixture(array $fixture): void
{
    if (file_exists($fixture['dbPath'])) {
        unlink($fixture['dbPath']);
    }
}

test('server info includes quality automation summary', function () {
    $fixture = createServerHandlerFixture();

    try {
        $handler = new ServerHandler(
            storage: $fixture['storage'],
            startTime: microtime(true) - 5,
            turnManager: $fixture['turnManager'],
            taskManager: $fixture['taskManager'],
            qualityAutomation: $fixture['qualityStatus'],
        );

        $response = $handler->info(new ServerRequest('GET', '/api/v1/server/info'));
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['quality_automation']['enabled'])->toBeTrue();
        expect($body['quality_automation']['linked_follow_ups'])->toBe(1);
        expect($body['quality_automation']['present_schedules'])->toBe(2);
    } finally {
        cleanupServerHandlerFixture($fixture);
    }
});

test('server quality endpoint returns detailed quality automation state', function () {
    $fixture = createServerHandlerFixture();

    try {
        $handler = new ServerHandler(
            storage: $fixture['storage'],
            startTime: microtime(true) - 5,
            turnManager: $fixture['turnManager'],
            taskManager: $fixture['taskManager'],
            qualityAutomation: $fixture['qualityStatus'],
        );

        $response = $handler->quality(new ServerRequest('GET', '/api/v1/server/quality'));
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['follow_ups']['counts']['linked'])->toBe(1);
        expect($body['follow_ups']['active'])->toHaveCount(1);
        expect($body['schedules'])->toHaveCount(2);
    } finally {
        cleanupServerHandlerFixture($fixture);
    }
});

test('health handler includes quality automation summary', function () {
    $fixture = createServerHandlerFixture();

    try {
        $handler = new HealthHandler(
            startTime: microtime(true) - 5,
            turnManager: $fixture['turnManager'],
            taskManager: $fixture['taskManager'],
            scheduleStore: $fixture['scheduleStore'],
            webhookStore: null,
            qualityAutomation: $fixture['qualityStatus'],
        );

        $response = $handler(new ServerRequest('GET', '/api/v1/health'));
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['quality_automation']['enabled'])->toBeTrue();
        expect($body['quality_automation']['linked_follow_ups'])->toBe(1);
        expect($body['quality_automation']['active_follow_ups'])->toBe(1);
    } finally {
        cleanupServerHandlerFixture($fixture);
    }
});
