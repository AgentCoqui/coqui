<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Handler\ScheduleHandler;
use CoquiBot\Coqui\Storage\ScheduleStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use React\Http\Message\ServerRequest;

function createScheduleHandlerFixture(): array
{
    $dbPath = sys_get_temp_dir() . '/coqui-schedule-handler-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    $store = new ScheduleStore($storage->getPdo());

    return [
        'dbPath' => $dbPath,
        'storage' => $storage,
        'store' => $store,
        'handler' => new ScheduleHandler($store, $storage),
    ];
}

function cleanupScheduleHandlerFixture(array $fixture): void
{
    $fixture['handler'] = null;
    $fixture['store'] = null;
    $fixture['storage'] = null;
    cleanupSqliteTestDb($fixture['dbPath']);
}

test('schedule handler creates a schedule through the API', function () {
    $fixture = createScheduleHandlerFixture();

    try {
        $request = new ServerRequest(
            'POST',
            '/api/v1/schedules',
            ['Content-Type' => 'application/json'],
            json_encode([
                'name' => 'daily-review',
                'schedule_expression' => '0 9 * * 1-5',
                'prompt' => 'Review recent changes.',
                'role' => 'orchestrator',
                'timezone' => 'UTC',
                'max_iterations' => 12,
                'max_failures' => 5,
            ]) ?: '',
        );

        $response = $fixture['handler']->create($request);
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(201);
        expect($body['schedule']['name'])->toBe('daily-review');
        expect($body['schedule']['created_by'])->toBe('api');
        expect($body['schedule']['enabled'])->toBe(1);
    } finally {
        cleanupScheduleHandlerFixture($fixture);
    }
});

test('schedule handler updates mutable schedules', function () {
    $fixture = createScheduleHandlerFixture();

    try {
        $scheduleId = $fixture['store']->create('daily-review', '0 9 * * 1-5', 'Review recent changes.');

        $request = new ServerRequest(
            'PATCH',
            '/api/v1/schedules/' . $scheduleId,
            ['Content-Type' => 'application/json'],
            json_encode([
                'description' => 'Weekday review run',
                'schedule_expression' => '0 10 * * 1-5',
            ]) ?: '',
        );

        $response = $fixture['handler']->update($request, $scheduleId);
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['schedule']['description'])->toBe('Weekday review run');
        expect($body['schedule']['schedule_expression'])->toBe('0 10 * * 1-5');
    } finally {
        cleanupScheduleHandlerFixture($fixture);
    }
});

test('schedule handler action endpoints disable enable and trigger schedules', function () {
    $fixture = createScheduleHandlerFixture();

    try {
        $scheduleId = $fixture['store']->create('daily-review', '0 9 * * 1-5', 'Review recent changes.');

        $disableResponse = $fixture['handler']->disable(new ServerRequest('POST', '/api/v1/schedules/' . $scheduleId . '/disable'), $scheduleId);
        $enableResponse = $fixture['handler']->enable(new ServerRequest('POST', '/api/v1/schedules/' . $scheduleId . '/enable'), $scheduleId);
        $triggerResponse = $fixture['handler']->trigger(new ServerRequest('POST', '/api/v1/schedules/' . $scheduleId . '/trigger'), $scheduleId);

        expect($disableResponse->getStatusCode())->toBe(200);
        expect(json_decode((string) $disableResponse->getBody(), true)['schedule']['enabled'])->toBe(0);
        expect($enableResponse->getStatusCode())->toBe(200);
        expect(json_decode((string) $enableResponse->getBody(), true)['schedule']['enabled'])->toBe(1);

        $triggerBody = json_decode((string) $triggerResponse->getBody(), true);
        expect($triggerResponse->getStatusCode())->toBe(200);
        expect($triggerBody['message'])->toContain('next API scheduler tick');
        expect($triggerBody['schedule']['next_run_at'])->not->toBeNull();
    } finally {
        cleanupScheduleHandlerFixture($fixture);
    }
});

test('schedule handler delete removes mutable schedules', function () {
    $fixture = createScheduleHandlerFixture();

    try {
        $scheduleId = $fixture['store']->create('daily-review', '0 9 * * 1-5', 'Review recent changes.');

        $response = $fixture['handler']->delete(new ServerRequest('DELETE', '/api/v1/schedules/' . $scheduleId), $scheduleId);
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['deleted'])->toBeTrue();
        expect($fixture['store']->get($scheduleId))->toBeNull();
    } finally {
        cleanupScheduleHandlerFixture($fixture);
    }
});

test('schedule handler rejects mutations for filesystem schedules', function () {
    $fixture = createScheduleHandlerFixture();

    try {
        $scheduleId = $fixture['store']->upsertFilesystem(
            name: 'nightly-review',
            sourcePath: '/workspace/schedules/nightly-review.json',
            scheduleExpression: '0 0 * * *',
            prompt: 'Run nightly review.',
        );

        $response = $fixture['handler']->trigger(new ServerRequest('POST', '/api/v1/schedules/' . $scheduleId . '/trigger'), $scheduleId);
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(409);
        expect($body['code'])->toBe('conflict');
        expect($body['details']['source_path'])->toBe('/workspace/schedules/nightly-review.json');
    } finally {
        cleanupScheduleHandlerFixture($fixture);
    }
});

test('schedule handler exposes upcoming schedules and aggregate stats', function () {
    $fixture = createScheduleHandlerFixture();

    try {
        $enabledId = $fixture['store']->create('daily-review', '@once', 'Review recent changes.');
        $disabledId = $fixture['store']->create('weekly-retro', '0 9 * * 1', 'Review weekly progress.');
        $fixture['store']->disable($disabledId);

        $upcomingResponse = $fixture['handler']->upcoming(
            new ServerRequest('GET', '/api/v1/schedules/upcoming?hours=24'),
        );
        $upcomingBody = json_decode((string) $upcomingResponse->getBody(), true);

        $statsResponse = $fixture['handler']->stats(
            new ServerRequest('GET', '/api/v1/schedules/stats'),
        );
        $statsBody = json_decode((string) $statsResponse->getBody(), true);

        expect($upcomingResponse->getStatusCode())->toBe(200);
        expect($upcomingBody['count'])->toBe(1);
        expect($upcomingBody['schedules'][0]['id'])->toBe($enabledId);

        expect($statsResponse->getStatusCode())->toBe(200);
        expect($statsBody['total'])->toBe(2);
        expect($statsBody['enabled'])->toBe(1);
        expect($statsBody['disabled'])->toBe(1);
        expect($statsBody['total_runs'])->toBe(0);
    } finally {
        cleanupScheduleHandlerFixture($fixture);
    }
});

test('schedule handler returns run history for a schedule', function () {
    $fixture = createScheduleHandlerFixture();

    try {
        $scheduleId = $fixture['store']->create('daily-review', '0 9 * * 1-5', 'Review recent changes.');

        $sessionOne = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');
        $taskOne = $fixture['storage']->createTask(
            sessionId: $sessionOne,
            prompt: 'Run the weekday review',
            title: 'Weekday review',
            scheduleId: $scheduleId,
            metadata: ['source' => 'schedule'],
        );
        $fixture['storage']->updateTaskStatus($taskOne, 'completed', ['result' => 'Review complete']);

        $sessionTwo = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');
        $taskTwo = $fixture['storage']->createTask(
            sessionId: $sessionTwo,
            prompt: 'Run the weekday review again',
            title: 'Weekday review retry',
            scheduleId: $scheduleId,
        );
        $fixture['storage']->updateTaskStatus($taskTwo, 'failed', ['error' => 'Network timeout']);

        $response = $fixture['handler']->runs(
            new ServerRequest('GET', '/api/v1/schedules/' . $scheduleId . '/runs?limit=10'),
            $scheduleId,
        );
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['schedule']['id'])->toBe($scheduleId);
        expect($body['count'])->toBe(2);
        expect($body['counts']['completed'])->toBe(1);
        expect($body['counts']['failed'])->toBe(1);

        $runsById = [];
        foreach ($body['runs'] as $run) {
            $runsById[$run['id']] = $run;
        }

        expect(array_keys($runsById))->toContain($taskOne, $taskTwo);
        expect($runsById[$taskTwo]['error'])->toBe('Network timeout');
        expect($runsById[$taskOne]['result'])->toBe('Review complete');
        expect($runsById[$taskOne]['metadata']['source'])->toBe('schedule');
    } finally {
        cleanupScheduleHandlerFixture($fixture);
    }
});