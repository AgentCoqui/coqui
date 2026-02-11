<?php

declare(strict_types=1);

use Coqui\Storage\SessionStorage;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-test-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
});

afterEach(function () {
    if (file_exists($this->dbPath)) {
        unlink($this->dbPath);
    }
});

test('creates session and returns id', function () {
    $id = $this->storage->createSession('orchestrator', 'ollama/qwen3:latest');

    expect($id)->toBeString();
    expect(strlen($id))->toBe(32);
});

test('getSession returns session data', function () {
    $id = $this->storage->createSession('coder', 'anthropic/claude');

    $session = $this->storage->getSession($id);

    expect($session)->toBeArray();
    expect($session['id'])->toBe($id);
    expect($session['model_role'])->toBe('coder');
    expect($session['model'])->toBe('anthropic/claude');
});

test('getSession returns null for missing session', function () {
    $session = $this->storage->getSession('nonexistent');

    expect($session)->toBeNull();
});

test('listSessions returns all sessions', function () {
    $this->storage->createSession('orchestrator', 'model1');
    $this->storage->createSession('coder', 'model2');

    $sessions = $this->storage->listSessions();

    expect($sessions)->toHaveCount(2);
});

test('addMessage saves and retrieves messages', function () {
    $sessionId = $this->storage->createSession('test', 'model');

    $this->storage->addMessage($sessionId, 'user', 'Hello');
    $this->storage->addMessage($sessionId, 'assistant', 'Hi there');

    $messages = $this->storage->getMessages($sessionId);

    expect($messages)->toHaveCount(2);
    expect($messages[0]['role'])->toBe('user');
    expect($messages[0]['content'])->toBe('Hello');
    expect($messages[1]['role'])->toBe('assistant');
    expect($messages[1]['content'])->toBe('Hi there');
});

test('addMessage with tool calls', function () {
    $sessionId = $this->storage->createSession('test', 'model');

    $toolCalls = json_encode([
        ['id' => 'call_123', 'name' => 'read_file', 'arguments' => ['path' => '/tmp/test.txt']],
    ]);

    $this->storage->addMessage($sessionId, 'assistant', '', $toolCalls);

    $messages = $this->storage->getMessages($sessionId);

    expect($messages[0]['tool_calls'])->toBe($toolCalls);
});

test('loadConversation rebuilds conversation object', function () {
    $sessionId = $this->storage->createSession('test', 'model');

    $this->storage->addMessage($sessionId, 'system', 'You are a test');
    $this->storage->addMessage($sessionId, 'user', 'Hello');
    $this->storage->addMessage($sessionId, 'assistant', 'Hi');

    $conversation = $this->storage->loadConversation($sessionId);
    $messages = $conversation->messages();

    expect($messages)->toHaveCount(3);
    expect($messages[0]->role()->value)->toBe('system');
    expect($messages[1]->role()->value)->toBe('user');
    expect($messages[2]->role()->value)->toBe('assistant');
});

test('logChildRun saves child run data', function () {
    $sessionId = $this->storage->createSession('test', 'model');

    $this->storage->logChildRun(
        sessionId: $sessionId,
        parentIteration: 3,
        agentRole: 'coder',
        model: 'anthropic/claude',
        prompt: 'Write a function',
        result: 'function test() {}',
        tokenCount: 150,
    );

    $runs = $this->storage->getChildRuns($sessionId);

    expect($runs)->toHaveCount(1);
    expect($runs[0]['agent_role'])->toBe('coder');
    expect($runs[0]['parent_iteration'])->toBe(3);
    expect($runs[0]['token_count'])->toBe(150);
});

test('deleteSession removes session and messages', function () {
    $sessionId = $this->storage->createSession('test', 'model');
    $this->storage->addMessage($sessionId, 'user', 'Hello');

    $this->storage->deleteSession($sessionId);

    expect($this->storage->getSession($sessionId))->toBeNull();
    expect($this->storage->getMessages($sessionId))->toBeEmpty();
});

test('getLatestSessionId returns most recent', function () {
    $id1 = $this->storage->createSession('test1', 'model');
    sleep(1); // Ensure different timestamp (SQLite TEXT stores seconds precision)
    $id2 = $this->storage->createSession('test2', 'model');

    $latest = $this->storage->getLatestSessionId();

    expect($latest)->toBe($id2);
});

test('updateTokenCount updates session tokens', function () {
    $sessionId = $this->storage->createSession('test', 'model');

    $this->storage->updateTokenCount($sessionId, 500);

    $session = $this->storage->getSession($sessionId);
    expect($session['token_count'])->toBe(500);
});
