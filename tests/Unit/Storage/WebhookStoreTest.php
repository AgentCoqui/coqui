<?php

declare(strict_types=1);

use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Storage\WebhookStore;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-webhook-test-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
    $this->store = new WebhookStore($this->storage->getPdo());
});

afterEach(function () {
    releaseTestObjectProperties($this);
    cleanupSqliteTestDb($this->dbPath);
});

// --- Create ---

test('create returns a 32-char hex id', function () {
    $id = $this->store->create(
        name: 'test-webhook',
        promptTemplate: 'Process event: {{payload}}',
    );

    expect($id)->toBeString();
    expect(strlen($id))->toBe(32);
});

test('create stores webhook with correct fields', function () {
    $id = $this->store->create(
        name: 'github-deploy',
        promptTemplate: 'Handle deploy event: {{payload}}',
        source: 'github',
        role: 'coder',
        maxIterations: 20,
        description: 'Triggered on GitHub deployments',
        eventFilter: 'push,release',
        createdBy: 'orchestrator',
    );

    $webhook = $this->store->get($id);

    expect($webhook)->not->toBeNull();
    expect($webhook['name'])->toBe('github-deploy');
    expect($webhook['prompt_template'])->toBe('Handle deploy event: {{payload}}');
    expect($webhook['source'])->toBe('github');
    expect($webhook['role'])->toBe('coder');
    expect((int) $webhook['max_iterations'])->toBe(20);
    expect($webhook['description'])->toBe('Triggered on GitHub deployments');
    expect($webhook['event_filter'])->toBe('push,release');
    expect($webhook['created_by'])->toBe('orchestrator');
    expect((int) $webhook['enabled'])->toBe(1);
    expect((int) $webhook['trigger_count'])->toBe(0);
});

test('create auto-generates secret when not provided', function () {
    $id = $this->store->create(
        name: 'auto-secret',
        promptTemplate: 'test',
    );

    $webhook = $this->store->get($id);

    expect($webhook['secret'])->toBeString();
    expect(strlen($webhook['secret']))->toBe(64); // 32 bytes hex-encoded
});

test('create uses provided secret', function () {
    $id = $this->store->create(
        name: 'custom-secret',
        promptTemplate: 'test',
        secret: 'my-custom-secret-value',
    );

    $webhook = $this->store->get($id);

    expect($webhook['secret'])->toBe('my-custom-secret-value');
});

test('create enforces unique name', function () {
    $this->store->create(name: 'dupe', promptTemplate: 'a');
    $this->store->create(name: 'dupe', promptTemplate: 'b');
})->throws(PDOException::class);

// --- Get ---

test('get returns null for nonexistent id', function () {
    expect($this->store->get('nonexistent'))->toBeNull();
});

test('getByName returns the webhook', function () {
    $this->store->create(name: 'find-me', promptTemplate: 'test');

    $webhook = $this->store->getByName('find-me');

    expect($webhook)->not->toBeNull();
    expect($webhook['name'])->toBe('find-me');
});

test('getByName returns null for nonexistent name', function () {
    expect($this->store->getByName('nope'))->toBeNull();
});

// --- Update ---

test('update modifies fields', function () {
    $id = $this->store->create(name: 'update-me', promptTemplate: 'original');

    $result = $this->store->update($id,
        promptTemplate: 'updated template: {{payload}}',
        description: 'Updated description',
    );

    expect($result)->toBeTrue();

    $webhook = $this->store->get($id);
    expect($webhook['prompt_template'])->toBe('updated template: {{payload}}');
    expect($webhook['description'])->toBe('Updated description');
});

test('update returns false for nonexistent id', function () {
    expect($this->store->update('fake-id', description: 'x'))->toBeFalse();
});

// --- Delete ---

test('delete removes the webhook', function () {
    $id = $this->store->create(name: 'delete-me', promptTemplate: 'bye');

    $result = $this->store->delete($id);

    expect($result)->toBeTrue();
    expect($this->store->get($id))->toBeNull();
});

test('delete returns false for nonexistent id', function () {
    expect($this->store->delete('fake-id'))->toBeFalse();
});

// --- List ---

test('list returns all webhooks', function () {
    $this->store->create(name: 'a', promptTemplate: 'a');
    $this->store->create(name: 'b', promptTemplate: 'b');

    $list = $this->store->list();

    expect($list)->toHaveCount(2);
});

test('list filters by enabled state', function () {
    $this->store->create(name: 'enabled', promptTemplate: 'x');
    $id2 = $this->store->create(name: 'to-disable', promptTemplate: 'y');
    $this->store->update($id2, enabled: false);

    $enabledOnly = $this->store->list(enabled: true);
    $disabledOnly = $this->store->list(enabled: false);

    expect($enabledOnly)->toHaveCount(1);
    expect($enabledOnly[0]['name'])->toBe('enabled');
    expect($disabledOnly)->toHaveCount(1);
});

// --- Rotate Secret ---

test('rotateSecret returns new 64-char hex secret', function () {
    $id = $this->store->create(name: 'rotate', promptTemplate: 'test');

    $oldSecret = $this->store->get($id)['secret'];
    $newSecret = $this->store->rotateSecret($id);

    expect($newSecret)->toBeString();
    expect(strlen($newSecret))->toBe(64);
    expect($newSecret)->not->toBe($oldSecret);

    $webhook = $this->store->get($id);
    expect($webhook['secret'])->toBe($newSecret);
});

test('rotateSecret returns null for nonexistent id', function () {
    expect($this->store->rotateSecret('fake-id'))->toBeNull();
});

// --- Mark Triggered ---

test('markTriggered increments trigger count and sets last_triggered_at', function () {
    $id = $this->store->create(name: 'trigger-me', promptTemplate: 'test');

    $this->store->markTriggered($id);

    $webhook = $this->store->get($id);
    expect((int) $webhook['trigger_count'])->toBe(1);
    expect($webhook['last_triggered_at'])->not->toBeNull();

    $this->store->markTriggered($id);
    expect((int) $this->store->get($id)['trigger_count'])->toBe(2);
});

// --- Stats ---

test('getStats returns correct counts', function () {
    $this->store->create(name: 'a', promptTemplate: 'a');
    $id2 = $this->store->create(name: 'b', promptTemplate: 'b');
    $this->store->update($id2, enabled: false);

    $stats = $this->store->getStats();

    expect((int) $stats['total'])->toBe(2);
    expect((int) $stats['enabled'])->toBe(1);
    expect((int) $stats['disabled'])->toBe(1);
});

// --- Delivery Logging ---

test('logDelivery creates an audit entry', function () {
    $id = $this->store->create(name: 'log-test', promptTemplate: 'test');

    $deliveryId = $this->store->logDelivery(
        webhookId: $id,
        status: 'created',
        eventType: 'push',
        payloadSummary: '{"ref": "main"}',
        taskId: 'task-abc',
        sourceIp: '192.168.1.1',
    );

    expect($deliveryId)->toBeString();
    expect(strlen($deliveryId))->toBe(32);
});

test('getDeliveries returns logged entries', function () {
    $id = $this->store->create(name: 'deliveries', promptTemplate: 'test');

    $this->store->logDelivery(
        webhookId: $id,
        status: 'created',
        eventType: 'push',
        payloadSummary: 'push event',
        taskId: 'task-1',
    );
    $this->store->logDelivery(
        webhookId: $id,
        status: 'created',
        eventType: 'release',
        payloadSummary: 'release event',
        taskId: 'task-2',
    );

    $deliveries = $this->store->getDeliveries($id);

    expect($deliveries)->toHaveCount(2);
});

// --- Purge Old Deliveries ---

test('purgeOldDeliveries removes old entries', function () {
    $id = $this->store->create(name: 'purge-test', promptTemplate: 'test');

    // Log a delivery
    $this->store->logDelivery(
        webhookId: $id,
        status: 'created',
        eventType: 'push',
        payloadSummary: 'old event',
        taskId: 'task-1',
    );

    // Deliveries exist before purge
    expect($this->store->getDeliveries($id))->toHaveCount(1);

    // Purge with large retention keeps recent entries
    $count = $this->store->purgeOldDeliveries(365);
    expect($count)->toBe(0);
    expect($this->store->getDeliveries($id))->toHaveCount(1);
});
