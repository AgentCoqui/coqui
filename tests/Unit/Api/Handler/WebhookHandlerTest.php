<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Handler\WebhookHandler;
use CoquiBot\Coqui\Api\Handler\WebhookManagementHandler;
use CoquiBot\Coqui\Api\Router;
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
    (new WebhookHandler($store, $storage, new WebhookVerifierRegistry()))->register($router);
    (new WebhookManagementHandler($store, new ProfileDiscovery($workspacePath)))->register($router);

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

        expect($response->getStatusCode())->toBe(200);
        expect($body['status'])->toBe('accepted');
        expect($session)->not->toBeNull();
        expect($session['profile'])->toBe('caelum');
    } finally {
        cleanupWebhookHandlerFixture($fixture);
    }
});
