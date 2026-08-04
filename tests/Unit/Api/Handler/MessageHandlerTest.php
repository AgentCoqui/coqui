<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\AgentTurnManager;
use CoquiBot\Coqui\Api\Handler\MessageHandler;
use CoquiBot\Coqui\Content\ContentStore;
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
    $this->handler = new MessageHandler($this->storage, $this->turnManager);
    $this->sessionId = $this->storage->createSession('orchestrator', 'test/model');
});

afterEach(function () {
    releaseTestObjectProperties($this);
    cleanupSqliteTestDb($this->dbPath);
    cleanupTestTree($this->tmpDir);
});

function markTurnManagerSessionActive(AgentTurnManager $turnManager, string $sessionId): void
{
    $reflection = new ReflectionProperty(AgentTurnManager::class, 'sessionTurns');
    $sessionTurns = $reflection->getValue($turnManager);
    $sessionTurns[$sessionId] = 'turn-test';
    $reflection->setValue($turnManager, $sessionTurns);
}

/**
 * @return array<string, mixed>|null
 */
function extractMessageHandlerCompleteResult(MessageHandler $handler, string $turnProcessId): ?array
{
    $method = new ReflectionMethod(MessageHandler::class, 'extractCompleteResult');

    /** @var array<string, mixed>|null $result */
    $result = $method->invoke($handler, $turnProcessId);

    return $result;
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

test('message handler rejects prompts for closed sessions', function () {
    $this->storage->closeSession($this->sessionId, 'test-close');

    $request = new ServerRequest(
        'POST',
        '/api/v1/sessions/' . $this->sessionId . '/messages',
        ['Content-Type' => 'application/json'],
        json_encode([
            'prompt' => 'Hello?',
        ]) ?: '',
    );

    $response = $this->handler->send($request, $this->sessionId);
    $body = json_decode((string) $response->getBody(), true);

    expect($response->getStatusCode())->toBe(409);
    expect($body['code'])->toBe('session_closed');
});

test('message handler rejects deleting messages from closed sessions', function () {
    $messageId = $this->storage->addMessage($this->sessionId, 'user', 'Hello');
    $this->storage->closeSession($this->sessionId, 'test-close');

    $response = $this->handler->delete(
        new ServerRequest('DELETE', '/api/v1/sessions/' . $this->sessionId . '/messages/' . $messageId),
        $this->sessionId,
        $messageId,
    );
    $body = json_decode((string) $response->getBody(), true);

    expect($response->getStatusCode())->toBe(409);
    expect($body['code'])->toBe('session_closed');
});

test('message handler rejects listing messages for hidden sessions', function () {
    $hiddenSessionId = $this->storage->createSession('learner', 'background-task', visibility: 'hidden');

    $response = $this->handler->list(
        new ServerRequest('GET', '/api/v1/sessions/' . $hiddenSessionId . '/messages'),
        $hiddenSessionId,
    );
    $body = json_decode((string) $response->getBody(), true);

    expect($response->getStatusCode())->toBe(404);
    expect($body['code'])->toBe('session_not_found');
});

test('message handler 404s an attachment referencing an unknown content_ref', function () {
    $request = new ServerRequest(
        'POST',
        '/api/v1/sessions/' . $this->sessionId . '/messages',
        ['Content-Type' => 'application/json'],
        json_encode([
            'prompt' => 'Describe this.',
            'attachments' => [['content_ref' => 'does-not-exist', 'mime_type' => 'image/png']],
        ]) ?: '',
    );

    $response = $this->handler->send($request, $this->sessionId);
    $body = json_decode((string) $response->getBody(), true);

    expect($response->getStatusCode())->toBe(404);
    expect($body['code'])->toBe('content_not_found');
});

test('message handler resolves an attachment by content_ref past the store lookup', function () {
    // A real blob in the content store; the send should resolve it, then hit the
    // active-run guard (proving resolution succeeded — a bad ref would 404 first).
    $content = new ContentStore($this->storage->getPdo());
    $stored = $content->store('hello world', 'text/plain');

    markTurnManagerSessionActive($this->turnManager, $this->sessionId);

    $request = new ServerRequest(
        'POST',
        '/api/v1/sessions/' . $this->sessionId . '/messages',
        ['Content-Type' => 'application/json'],
        json_encode([
            'prompt' => 'Summarize the attachment.',
            'attachments' => [['content_ref' => $stored['content_ref'], 'mime_type' => 'text/plain']],
        ]) ?: '',
    );

    $response = $this->handler->send($request, $this->sessionId);
    $body = json_decode((string) $response->getBody(), true);

    expect($response->getStatusCode())->toBe(409);
    expect($body['code'])->toBe('agent_busy');
});

test('message handler serializes a stored message with a content_ref attachment', function () {
    $content = new ContentStore($this->storage->getPdo());
    $stored = $content->store('attach me', 'text/plain');

    $mid = $this->storage->addMessage($this->sessionId, 'user', 'see attached', attachments: [
        ['content_ref' => $stored['content_ref'], 'mime_type' => 'text/plain'],
    ]);

    $wire = MessageHandler::toWire($this->storage->getMessageRow($mid));

    expect($wire['session_id'])->toBe($this->sessionId);
    expect($wire['attachments'])->toBe([
        ['content_ref' => $stored['content_ref'], 'mime_type' => 'text/plain'],
    ]);
});

test('message handler complete extraction preserves rich turn summary payload', function () {
    $turnProcessId = $this->storage->createTurnProcess($this->sessionId, 'Hello');
    $payload = [
        'content' => 'Done',
        'iterations' => 2,
        'prompt_tokens' => 1250,
        'completion_tokens' => 340,
        'total_tokens' => 1590,
        'duration_ms' => 4521,
        'tools_used' => ['list_dir'],
        'child_agent_count' => 0,
        'restart_requested' => false,
        'iteration_limit_reached' => false,
        'budget_exhausted' => false,
        'context_usage' => [
            'max_tokens' => 128000,
            'reserved_tokens' => 8192,
            'used_tokens' => 24500,
            'usage_percent' => 20.4,
            'available_tokens' => 95308,
            'effective_budget' => 119808,
            'breakdown' => [
                'system' => 5000,
                'memory' => 1200,
                'user' => 800,
                'assistant' => 7000,
                'tool' => 9000,
                'summary' => 1500,
            ],
        ],
        'file_edits' => [
            ['file_path' => '/tmp/example.php', 'operation' => 'update'],
        ],
        'error' => null,
        'review_feedback' => 'Looks good',
        'review_approved' => true,
        'background_tasks' => [
            'agents' => [[
                'id' => 'task-1',
                'status' => 'running',
                'title' => 'Refactor auth',
                'role' => 'coder',
                'started_at' => '2026-04-21T12:00:00+00:00',
                'created_at' => '2026-04-21T11:59:30+00:00',
            ]],
            'tools' => [],
            'total_count' => 1,
        ],
    ];

    $this->storage->appendTurnEvent($turnProcessId, 'complete', $payload);

    expect(extractMessageHandlerCompleteResult($this->handler, $turnProcessId))->toBe($payload);
});