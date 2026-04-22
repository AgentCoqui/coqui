<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Handler\WebhookHandler;
use CoquiBot\Coqui\Api\Handler\WebhookManagementHandler;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Api\Webhook\WebhookDispatchService;
use CoquiBot\Coqui\Api\Webhook\WebhookVerifierRegistry;
use CoquiBot\Coqui\Config\ProfileDiscovery;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Storage\WebhookStore;
use React\Http\Message\ServerRequest;

function createWebhookHandlerFixture(): array
{
    $dbPath = sys_get_temp_dir() . '/coqui-webhook-handler-' . bin2hex(random_bytes(8)) . '.db';
    $workspacePath = sys_get_temp_dir() . '/coqui-webhook-handler-ws-' . bin2hex(random_bytes(8));
    mkdir($workspacePath . '/profiles/caelum', 0755, true);
    file_put_contents($workspacePath . '/profiles/caelum/soul.md', '# Caelum' . "\n\nA calm companion.");

    $storage = new SessionStorage($dbPath);
    $store = new WebhookStore($storage->getPdo());
    $router = new Router();
    $dispatcher = new WebhookDispatchService($store, $storage);
    (new WebhookHandler($store, $storage, new WebhookVerifierRegistry(), $dispatcher))->register($router);
    (new WebhookManagementHandler($store, new ProfileDiscovery($workspacePath), $storage, $dispatcher))->register($router);

    return [
        'dbPath' => $dbPath,
        'workspacePath' => $workspacePath,
        'storage' => $storage,
        'store' => $store,
        'router' => $router,
    ];
}

function cleanupWebhookHandlerFixture(array $fixture): void
{
    cleanupSqliteTestDb($fixture['dbPath']);
    cleanupTestTree($fixture['workspacePath']);
}

test('webhook management create persists explicit profile', function () {
    $fixture = createWebhookHandlerFixture();

    try {
        $request = new ServerRequest(
            'POST',
            '/api/v1/webhooks',
            ['Content-Type' => 'application/json'],
            json_encode([
                'name' => 'caelum-hook',
                'prompt_template' => 'Handle {{event_type}}',
                'profile' => 'caelum',
            ]) ?: '',
        );

        $response = $fixture['router']->dispatch($request);
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(201);
        expect($body['webhook']['profile'])->toBe('caelum');
    } finally {
        cleanupWebhookHandlerFixture($fixture);
    }
});

test('incoming webhook creates task session with webhook profile', function () {
    $fixture = createWebhookHandlerFixture();

    try {
        $secret = 'super-secret';
        $scheduleId = $fixture['store']->create(
            name: 'caelum-hook',
            promptTemplate: 'Handle {{event_type}}',
            source: 'generic',
            profile: 'caelum',
            secret: $secret,
        );
        expect($scheduleId)->not->toBe('');

        $payload = json_encode(['message' => 'hello'], JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', (string) $payload, $secret);

        $request = new ServerRequest(
            'POST',
            '/api/v1/webhooks/incoming/caelum-hook',
            [
                'Content-Type' => 'application/json',
                'X-Signature' => 'sha256=' . $signature,
                'X-Event-Type' => 'ping',
            ],
            $payload ?: '',
        );

        $response = $fixture['router']->dispatch($request);
        $body = json_decode((string) $response->getBody(), true);
        $task = $fixture['storage']->getTask((string) $body['task_id']);
        $session = $task !== null ? $fixture['storage']->getSession((string) $task['session_id']) : null;
        $delivery = $fixture['store']->getDelivery((string) $body['delivery_id']);

        expect($response->getStatusCode())->toBe(200);
        expect($body['status'])->toBe('accepted');
        expect($body['delivery_id'])->not->toBe('');
        expect($session)->not->toBeNull();
        expect($session['profile'])->toBe('caelum');
        expect($delivery)->not->toBeNull();
        expect($delivery['status'])->toBe('delivered');
    } finally {
        cleanupWebhookHandlerFixture($fixture);
    }
});

test('webhook management returns delivery detail with linked task', function () {
    $fixture = createWebhookHandlerFixture();

    try {
        $webhookId = $fixture['store']->create(
            name: 'audit-hook',
            promptTemplate: 'Handle {{event_type}} for {{sender.login}}',
            source: 'generic',
            secret: 'secret',
            profile: 'caelum',
        );

        $payload = json_encode(['sender' => ['login' => 'carmelo']], JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', (string) $payload, 'secret');
        $incomingResponse = $fixture['router']->dispatch(new ServerRequest(
            'POST',
            '/api/v1/webhooks/incoming/audit-hook',
            [
                'Content-Type' => 'application/json',
                'X-Signature' => 'sha256=' . $signature,
                'X-Event-Type' => 'push',
            ],
            $payload ?: '',
        ));
        $incomingBody = json_decode((string) $incomingResponse->getBody(), true);

        $detailResponse = $fixture['router']->dispatch(
            new ServerRequest('GET', '/api/v1/webhooks/' . $webhookId . '/deliveries/' . $incomingBody['delivery_id']),
        );
        $detailBody = json_decode((string) $detailResponse->getBody(), true);

        expect($detailResponse->getStatusCode())->toBe(200);
        expect($detailBody['delivery']['id'])->toBe($incomingBody['delivery_id']);
        expect($detailBody['delivery']['webhook_id'])->toBe($webhookId);
        expect($detailBody['delivery']['status'])->toBe('delivered');
        expect($detailBody['task']['id'])->toBe($incomingBody['task_id']);
    } finally {
        cleanupWebhookHandlerFixture($fixture);
    }
});

test('webhook management test endpoint dispatches synthetic delivery without mutating trigger counters', function () {
    $fixture = createWebhookHandlerFixture();

    try {
        $webhookId = $fixture['store']->create(
            name: 'test-hook',
            promptTemplate: 'Handle {{event_type}} from {{repository.full_name}}',
            source: 'generic',
            profile: 'caelum',
        );

        $before = $fixture['store']->get($webhookId);

        $response = $fixture['router']->dispatch(new ServerRequest(
            'POST',
            '/api/v1/webhooks/' . $webhookId . '/test',
            ['Content-Type' => 'application/json'],
            json_encode([
                'event_type' => 'pull_request',
                'payload' => [
                    'repository' => ['full_name' => 'carmelo/coqui'],
                    'sender' => ['login' => 'carmelo'],
                ],
            ]) ?: '',
        ));
        $body = json_decode((string) $response->getBody(), true);
        $after = $fixture['store']->get($webhookId);
        $delivery = $fixture['store']->getDelivery((string) $body['delivery_id'], $webhookId);
        $task = $fixture['storage']->getTask((string) $body['task_id']);
        $session = $task !== null ? $fixture['storage']->getSession((string) $task['session_id']) : null;

        expect($response->getStatusCode())->toBe(200);
        expect($body['status'])->toBe('accepted');
        expect($body['event_type'])->toBe('pull_request');
        expect($body['prompt_preview'])->toContain('pull_request');
        expect($body['prompt_preview'])->toContain('carmelo/coqui');
        expect($delivery['status'])->toBe('test_delivered');
        expect($session)->not->toBeNull();
        expect($session['profile'])->toBe('caelum');
        expect((int) $after['trigger_count'])->toBe((int) $before['trigger_count']);
    } finally {
        cleanupWebhookHandlerFixture($fixture);
    }
});
