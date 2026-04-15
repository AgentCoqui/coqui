<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\AgentTurnManager;
use CoquiBot\Coqui\Api\Handler\MessageHandler;
use CoquiBot\Coqui\Storage\FileUploadStorage;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Utility\PromptSizeValidator;
use React\Http\Message\ServerRequest;

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/coqui-message-handler-' . bin2hex(random_bytes(8));
    mkdir($this->tmpDir, 0755, true);

    $this->dbPath = $this->tmpDir . '/coqui.db';
    $this->storage = new SessionStorage($this->dbPath);
    $this->turnManager = new AgentTurnManager(
        $this->storage,
        '/nonexistent/coqui',
        '',
        sys_get_temp_dir(),
        $this->tmpDir,
    );
    $this->uploadStorage = new FileUploadStorage($this->tmpDir);
    $this->handler = new MessageHandler($this->storage, $this->turnManager, $this->uploadStorage);
    $this->sessionId = $this->storage->createSession('orchestrator', 'test/model');
});

afterEach(function () {
    releaseTestObjectProperties($this);
    cleanupSqliteTestDb($this->dbPath);
    cleanupTestTree($this->tmpDir);
});

function markTurnManagerSessionActive(AgentTurnManager $turnManager, string $sessionId): void
{
    $markActive = \Closure::bind(
        static function (AgentTurnManager $turnManager, string $sessionId): void {
            $turnManager->sessionTurns[$sessionId] = 'turn-test';
        },
        null,
        AgentTurnManager::class,
    );

    $markActive($turnManager, $sessionId);
}

test('message handler validates missing prompt', function () {
    $request = new ServerRequest(
        'POST',
        '/api/v1/sessions/' . $this->sessionId . '/messages',
        ['Content-Type' => 'application/json'],
        json_encode([]) ?: '',
    );

    $response = $this->handler->send($request, $this->sessionId);
    $body = json_decode((string) $response->getBody(), true);

    expect($response->getStatusCode())->toBe(400);
    expect($body['code'])->toBe('missing_field');
});

test('message handler rejects oversized prompts', function () {
    $request = new ServerRequest(
        'POST',
        '/api/v1/sessions/' . $this->sessionId . '/messages',
        ['Content-Type' => 'application/json'],
        json_encode([
            'prompt' => str_repeat('x', PromptSizeValidator::API_MAX_PROMPT_BYTES + 1),
        ]) ?: '',
    );

    $response = $this->handler->send($request, $this->sessionId);
    $body = json_decode((string) $response->getBody(), true);

    expect($response->getStatusCode())->toBe(413);
    expect($body['code'])->toBe('payload_too_large');
});

test('message handler accepts prompt at shared limit before execution checks', function () {
    markTurnManagerSessionActive($this->turnManager, $this->sessionId);

    $request = new ServerRequest(
        'POST',
        '/api/v1/sessions/' . $this->sessionId . '/messages',
        ['Content-Type' => 'application/json'],
        json_encode([
            'prompt' => str_repeat('x', PromptSizeValidator::API_MAX_PROMPT_BYTES),
        ]) ?: '',
    );

    $response = $this->handler->send($request, $this->sessionId);
    $body = json_decode((string) $response->getBody(), true);

    expect($response->getStatusCode())->toBe(409);
    expect($body['code'])->toBe('agent_busy');
});