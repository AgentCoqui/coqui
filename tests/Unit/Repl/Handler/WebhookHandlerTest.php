<?php

declare(strict_types=1);

use CoquiBot\Coqui\Repl\Handler\WebhookHandler;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Storage\WebhookStore;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

function createReplWebhookHandlerFixture(): array
{
    $workspacePath = sys_get_temp_dir() . '/coqui-repl-webhook-handler-' . bin2hex(random_bytes(8));
    mkdir($workspacePath, 0755, true);

    $dbPath = $workspacePath . '/coqui.db';
    $storage = new SessionStorage($dbPath);
    $store = new WebhookStore($storage->getPdo());
    $output = new BufferedOutput();

    return [
        'workspacePath' => $workspacePath,
        'dbPath' => $dbPath,
        'storage' => $storage,
        'store' => $store,
        'handler' => new WebhookHandler($storage),
        'io' => new SymfonyStyle(new ArrayInput([]), $output),
        'output' => $output,
    ];
}

function cleanupReplWebhookHandlerFixture(array $fixture): void
{
    cleanupSqliteTestDb($fixture['dbPath']);
    cleanupTestTree($fixture['workspacePath']);
}

test('webhook repl handler shows masked secret in status output', function () {
    $fixture = createReplWebhookHandlerFixture();

    try {
        $webhookId = $fixture['store']->create(
            name: 'github-release',
            promptTemplate: 'Summarize the release.',
            source: 'github',
            secret: str_repeat('a', 64),
        );

        $fixture['handler']->handle($fixture['io'], 'status github-release');
        $display = $fixture['output']->fetch();

        expect($display)->toContain('Webhook: github-release');
        expect($display)->toContain($webhookId);
        expect($display)->toContain('****');
        expect($display)->not->toContain(str_repeat('a', 64));
    } finally {
        cleanupReplWebhookHandlerFixture($fixture);
    }
});

test('webhook repl handler shows recent deliveries', function () {
    $fixture = createReplWebhookHandlerFixture();

    try {
        $webhookId = $fixture['store']->create(
            name: 'github-release',
            promptTemplate: 'Summarize the release.',
            source: 'github',
        );
        $fixture['store']->logDelivery($webhookId, 'completed', 'release.published', 'payload', 'task-123');

        $fixture['handler']->handle($fixture['io'], 'deliveries github-release');
        $display = $fixture['output']->fetch();

        expect($display)->toContain('Recent Deliveries: github-release');
        expect($display)->toContain('release.published');
        expect($display)->toContain('task-123');
    } finally {
        cleanupReplWebhookHandlerFixture($fixture);
    }
});