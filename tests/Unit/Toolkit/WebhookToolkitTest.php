<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Storage\WebhookStore;
use CoquiBot\Coqui\Toolkit\WebhookToolkit;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-webhook-toolkit-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
    $this->store = new WebhookStore($this->storage->getPdo());
    $this->toolkit = new WebhookToolkit(
        webhookStore: $this->store,
        apiBaseUrl: 'https://coqui.test',
        activeProfileId: 'caelum',
    );
});

afterEach(function () {
    cleanupSqliteTestDb($this->dbPath);
});

test('webhook_create returns structured json metadata', function () {
    $tool = toolFromToolkit($this->toolkit, 'webhook_create');

    $result = $tool->execute([
        'name' => 'deploy-hook',
        'prompt_template' => 'Handle {{event_type}}',
        'source' => 'github',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->mimeType)->toBe('application/json');
    expect($result->displayHint)->toBe('structured-json');

    $data = json_decode($result->content, true);
    expect($data['name'])->toBe('deploy-hook');
    expect($data['url'])->toBe('https://coqui.test/api/v1/webhooks/incoming/deploy-hook');
    expect($data['source'])->toBe('github');
    expect($data['profile'])->toBe('caelum');
    expect($data['secret'])->not->toBe('');
});

test('webhook_get returns structured json metadata with masked secret', function () {
    $id = $this->store->create(
        name: 'audit-hook',
        promptTemplate: 'Handle {{event_type}}',
        source: 'generic',
        profile: 'caelum',
        secret: 'super-secret',
    );
    $tool = toolFromToolkit($this->toolkit, 'webhook_get');

    $result = $tool->execute(['id' => $id]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->mimeType)->toBe('application/json');
    expect($result->displayHint)->toBe('structured-json');

    $data = json_decode($result->content, true);
    expect($data['webhook']['id'])->toBe($id);
    expect($data['webhook']['secret'])->not->toBe('super-secret');
    expect($data['recent_deliveries'])->toBeArray();
});

test('webhook_update returns structured json metadata', function () {
    $id = $this->store->create(
        name: 'build-hook',
        promptTemplate: 'Handle {{event_type}}',
        source: 'generic',
        profile: 'caelum',
    );
    $tool = toolFromToolkit($this->toolkit, 'webhook_update');

    $result = $tool->execute([
        'id' => $id,
        'event_filter' => 'push,pull_request',
        'description' => 'Build events only',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->mimeType)->toBe('application/json');
    expect($result->displayHint)->toBe('structured-json');

    $data = json_decode($result->content, true);
    expect($data['webhook']['id'])->toBe($id);
    expect($data['webhook']['event_filter'])->toBe('push,pull_request');
    expect($data['webhook']['description'])->toBe('Build events only');
});

test('webhook_rotate_secret returns structured json metadata', function () {
    $id = $this->store->create(
        name: 'rotate-hook',
        promptTemplate: 'Handle {{event_type}}',
        source: 'slack',
        profile: 'caelum',
        secret: 'old-secret',
    );
    $tool = toolFromToolkit($this->toolkit, 'webhook_rotate_secret');

    $result = $tool->execute(['id' => $id]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->mimeType)->toBe('application/json');
    expect($result->displayHint)->toBe('structured-json');

    $data = json_decode($result->content, true);
    expect($data['webhook_url'])->toBe('https://coqui.test/api/v1/webhooks/incoming/rotate-hook');
    expect($data['new_secret'])->not->toBe('old-secret');
    expect($this->store->get($id)['secret'])->toBe($data['new_secret']);
});