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
        'handler' => new ScheduleHandler($store),
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