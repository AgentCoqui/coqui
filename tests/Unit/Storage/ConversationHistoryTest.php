<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\Role;
use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Message\Conversation;
use CarmeloSantana\PHPAgents\Message\SystemMessage;
use CarmeloSantana\PHPAgents\Message\ToolResultMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CoquiBot\Coqui\Storage\SessionStorage;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-history-test-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
    $this->sessionId = $this->storage->createSession('orchestrator', 'test/model');
});

afterEach(function () {
    if (file_exists($this->dbPath)) {
        unlink($this->dbPath);
    }
});

test('loadConversation returns empty conversation for new session', function () {
    $conversation = $this->storage->loadConversation($this->sessionId);

    expect($conversation->count())->toBe(0);
});

test('loadConversation reconstructs user and assistant messages', function () {
    $this->storage->addMessage($this->sessionId, 'user', 'What is OpenClaw?');
    $this->storage->addMessage($this->sessionId, 'assistant', 'OpenClaw is an open-source AI assistant.');
    $this->storage->addMessage($this->sessionId, 'user', 'Tell me more');
    $this->storage->addMessage($this->sessionId, 'assistant', 'It uses large language models.');

    $conversation = $this->storage->loadConversation($this->sessionId);
    $messages = $conversation->messages();

    expect($messages)->toHaveCount(4);
    expect($messages[0]->role())->toBe(Role::User);
    expect($messages[0]->content())->toBe('What is OpenClaw?');
    expect($messages[1]->role())->toBe(Role::Assistant);
    expect($messages[1]->content())->toBe('OpenClaw is an open-source AI assistant.');
    expect($messages[2]->role())->toBe(Role::User);
    expect($messages[2]->content())->toBe('Tell me more');
    expect($messages[3]->role())->toBe(Role::Assistant);
    expect($messages[3]->content())->toBe('It uses large language models.');
});

test('loadConversation reconstructs tool call chains', function () {
    // Simulate a turn where the assistant called a tool
    $toolCalls = json_encode([
        ['id' => 'call_123', 'name' => 'brave_search', 'arguments' => ['query' => 'OpenClaw']],
    ]);

    $this->storage->addMessage($this->sessionId, 'user', 'Search for OpenClaw');
    $this->storage->addMessage($this->sessionId, 'assistant', '', $toolCalls);
    $this->storage->addMessage($this->sessionId, 'tool', 'Search results: OpenClaw.ai ...', null, 'call_123');
    $this->storage->addMessage($this->sessionId, 'assistant', 'Here is what I found about OpenClaw.');

    $conversation = $this->storage->loadConversation($this->sessionId);
    $messages = $conversation->messages();

    expect($messages)->toHaveCount(4);
    expect($messages[0]->role())->toBe(Role::User);
    expect($messages[1]->role())->toBe(Role::Assistant);
    expect($messages[1]->toolCalls())->toHaveCount(1);
    expect($messages[1]->toolCalls()[0]->name)->toBe('brave_search');
    expect($messages[2]->role())->toBe(Role::Tool);
    expect($messages[2]->toolCallId())->toBe('call_123');
    expect($messages[3]->role())->toBe(Role::Assistant);
});

test('loadConversation skips empty assistant messages without tool calls', function () {
    $this->storage->addMessage($this->sessionId, 'user', 'hello');
    $this->storage->addMessage($this->sessionId, 'assistant', '');
    $this->storage->addMessage($this->sessionId, 'user', 'still there?');

    $conversation = $this->storage->loadConversation($this->sessionId);
    $messages = $conversation->messages();

    expect($messages)->toHaveCount(2);
    expect($messages[0]->role())->toBe(Role::User);
    expect($messages[0]->content())->toBe('hello');
    expect($messages[1]->role())->toBe(Role::User);
    expect($messages[1]->content())->toBe('still there?');
});

test('loaded conversation can be pruned to fit budget', function () {
    // Simulate a long conversation
    for ($i = 0; $i < 10; $i++) {
        $this->storage->addMessage(
            $this->sessionId,
            'user',
            'User message ' . $i . str_repeat(' padding ', 50),
        );
        $this->storage->addMessage(
            $this->sessionId,
            'assistant',
            'Response ' . $i . str_repeat(' padding ', 50),
        );
    }

    $conversation = $this->storage->loadConversation($this->sessionId);
    expect($conversation->count())->toBe(20);

    // Prune to a very small budget
    $pruned = $conversation->fitWithinBudget(200);

    $userMessages = $pruned->filter(Role::User);
    expect(count($userMessages))->toBeLessThan(10);
    expect(count($userMessages))->toBeGreaterThanOrEqual(1);
});

test('history injection preserves conversation context', function () {
    // Simulate: Turn 1 happened, now we're loading for Turn 2
    $this->storage->addMessage($this->sessionId, 'user', 'What website is OpenClaw?');
    $this->storage->addMessage($this->sessionId, 'assistant', 'OpenClaw is at https://openclaw.ai/');

    $history = $this->storage->loadConversation($this->sessionId);

    // Build a conversation as AbstractAgent would:
    $conversation = new Conversation();
    $conversation->add(new SystemMessage('You are a helpful assistant.'));

    // Inject history (skip system messages, as the agent does)
    foreach ($history->messages() as $msg) {
        if ($msg->role() === Role::System) {
            continue;
        }
        $conversation->add($msg);
    }

    // Add the new user message
    $conversation->add(new UserMessage('Read the website to me'));

    $messages = $conversation->messages();

    // Verify the full conversation structure
    expect($messages)->toHaveCount(4);
    expect($messages[0]->role())->toBe(Role::System);
    expect($messages[1]->role())->toBe(Role::User);
    expect($messages[1]->content())->toBe('What website is OpenClaw?');
    expect($messages[2]->role())->toBe(Role::Assistant);
    expect($messages[2]->content())->toBe('OpenClaw is at https://openclaw.ai/');
    expect($messages[3]->role())->toBe(Role::User);
    expect($messages[3]->content())->toBe('Read the website to me');
});

test('token count accumulates across turns', function () {
    $this->storage->updateTokenCount($this->sessionId, 500);
    $this->storage->updateTokenCount($this->sessionId, 300);

    $session = $this->storage->getSession($this->sessionId);

    expect($session['token_count'])->toBe(800);
});

test('tool result messages are persisted and restored with correct pairing', function () {
    $toolCalls = json_encode([
        ['id' => 'tc_1', 'name' => 'read_file', 'arguments' => ['path' => 'README.md']],
        ['id' => 'tc_2', 'name' => 'brave_search', 'arguments' => ['query' => 'test']],
    ]);

    $this->storage->addMessage($this->sessionId, 'user', 'Do two things');
    $this->storage->addMessage($this->sessionId, 'assistant', '', $toolCalls);
    $this->storage->addMessage($this->sessionId, 'tool', 'File contents...', null, 'tc_1');
    $this->storage->addMessage($this->sessionId, 'tool', 'Search results...', null, 'tc_2');
    $this->storage->addMessage($this->sessionId, 'assistant', 'Done with both tasks.');

    $conversation = $this->storage->loadConversation($this->sessionId);
    $messages = $conversation->messages();

    expect($messages)->toHaveCount(5);

    // Verify assistant has two tool calls
    expect($messages[1]->toolCalls())->toHaveCount(2);
    expect($messages[1]->toolCalls()[0]->id)->toBe('tc_1');
    expect($messages[1]->toolCalls()[1]->id)->toBe('tc_2');

    // Verify tool results have correct call IDs
    expect($messages[2]->toolCallId())->toBe('tc_1');
    expect($messages[3]->toolCallId())->toBe('tc_2');
});
