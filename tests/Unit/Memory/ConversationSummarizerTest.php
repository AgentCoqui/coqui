<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Enum\FinishReason;
use CarmeloSantana\PHPAgents\Enum\Role;
use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Message\Conversation;
use CarmeloSantana\PHPAgents\Message\SystemMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\Response;
use CoquiBot\Coqui\Memory\ConversationSummarizer;
use CoquiBot\Coqui\Memory\ConversationSummaryResult;
use CoquiBot\Coqui\Storage\SessionStorage;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-test-summarizer-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
    $this->summarizer = new ConversationSummarizer(
        storage: $this->storage,
    );
});

afterEach(function () {
    unset($this->summarizer, $this->storage);

    if (file_exists($this->dbPath)) {
        unlink($this->dbPath);
    }

    foreach (['-wal', '-shm'] as $suffix) {
        $path = $this->dbPath . $suffix;
        if (file_exists($path)) {
            unlink($path);
        }
    }
});

function buildConversation(int $turns): Conversation
{
    $conv = new Conversation();
    $conv->add(new SystemMessage('You are a helpful assistant.'));

    for ($i = 1; $i <= $turns; $i++) {
        $conv->add(new UserMessage("Question {$i}"));
        $conv->add(new AssistantMessage("Answer {$i}"));
    }

    return $conv;
}

function createFakeProvider(string $summaryResponse): ProviderInterface
{
    return new class($summaryResponse) implements ProviderInterface {
        public function __construct(private readonly string $response) {}

        public function chat(array $messages, array $tools = [], array $options = []): Response
        {
            return new Response(
                content: $this->response,
                finishReason: FinishReason::Stop,
            );
        }

        public function stream(array $messages, array $tools = [], array $options = []): iterable
        {
            yield new Response(content: $this->response, finishReason: FinishReason::Stop);
        }

        public function structured(array $messages, string $schema, array $options = []): mixed
        {
            return new Response(content: $this->response, finishReason: FinishReason::Stop);
        }

        public function models(): array { return []; }
        public function isAvailable(): bool { return true; }
        public function getModel(): string { return 'test/model'; }
        public function withModel(string $model): static { return $this; }
    };
}

// --- summarize() ---

test('summarize returns empty result when conversation is too short', function () {
    $conv = buildConversation(2);
    $provider = createFakeProvider('summary text');

    $result = $this->summarizer->summarize($conv, $provider, keepRecentTurns: 3);

    expect($result)->toBeInstanceOf(ConversationSummaryResult::class);
    expect($result->wasSummarized())->toBeFalse();
    expect($result->messagesSummarized)->toBe(0);
    expect($result->summary)->toBe('');
});

test('summarize compresses older messages and keeps recent turns', function () {
    // Use 10 turns with verbose messages so the summary is a real compression
    $conv = new Conversation();
    $conv->add(new SystemMessage('You are a helpful assistant.'));
    for ($i = 1; $i <= 10; $i++) {
        $conv->add(new UserMessage("This is a detailed question number {$i} about a complex topic that requires extensive analysis and multiple paragraphs of response content."));
        $conv->add(new AssistantMessage("This is a comprehensive answer number {$i} covering multiple aspects of the topic with detailed explanations, examples, and references to previous discussion points."));
    }

    $provider = createFakeProvider('Summary: discussed 10 detailed topics about complex analysis.');

    $result = $this->summarizer->summarize($conv, $provider, keepRecentTurns: 3);

    expect($result->wasSummarized())->toBeTrue();
    expect($result->messagesSummarized)->toBeGreaterThan(0);
    expect($result->summary)->toBe('Summary: discussed 10 detailed topics about complex analysis.');
    expect($result->tokensBefore)->toBeGreaterThan($result->tokensAfter);
    expect($result->tokensSaved())->toBeGreaterThan(0);
});

test('summarize preserves system messages in the output conversation', function () {
    $conv = buildConversation(6);
    $provider = createFakeProvider('Summary of older turns.');

    $result = $this->summarizer->summarize($conv, $provider, keepRecentTurns: 2);

    $messages = $result->conversation->messages();
    $systemMessages = array_filter($messages, fn($m) => $m->role() === Role::System);

    expect(count($systemMessages))->toBeGreaterThanOrEqual(1);
});

test('summarize returns original conversation when LLM returns empty summary', function () {
    $conv = buildConversation(6);
    $provider = createFakeProvider('');

    $result = $this->summarizer->summarize($conv, $provider, keepRecentTurns: 2);

    expect($result->wasSummarized())->toBeFalse();
    expect($result->messagesSummarized)->toBe(0);
});

test('summarize keeps exactly the requested number of recent turns', function () {
    $conv = buildConversation(10);
    $provider = createFakeProvider('Summary of older turns.');

    $result = $this->summarizer->summarize($conv, $provider, keepRecentTurns: 4);

    // Count user messages excluding the summary itself (which is now a UserMessage)
    $userMessages = array_filter(
        $result->conversation->messages(),
        fn($m) => $m->role() === Role::User && !str_contains($m->content(), '[CONVERSATION SUMMARY'),
    );

    expect(count($userMessages))->toBe(4);
});

// --- ConversationSummaryResult ---

test('ConversationSummaryResult calculates tokens saved', function () {
    $conv = new Conversation();
    $result = new ConversationSummaryResult(
        summary: 'a summary',
        messagesSummarized: 10,
        tokensBefore: 5000,
        tokensAfter: 1000,
        conversation: $conv,
    );

    expect($result->tokensSaved())->toBe(4000);
    expect($result->wasSummarized())->toBeTrue();
});

test('ConversationSummaryResult reports not summarized when no messages', function () {
    $conv = new Conversation();
    $result = new ConversationSummaryResult(
        summary: '',
        messagesSummarized: 0,
        tokensBefore: 1000,
        tokensAfter: 1000,
        conversation: $conv,
    );

    expect($result->wasSummarized())->toBeFalse();
    expect($result->tokensSaved())->toBe(0);
});

// --- summarizeAndPersist() ---

test('summarizeAndPersist stores a summary message in the session', function () {
    $sessionId = $this->storage->createSession('orchestrator', 'test/model');

    // Add enough messages to trigger summarization
    for ($i = 1; $i <= 8; $i++) {
        $this->storage->addMessage($sessionId, 'user', "Question {$i}");
        $this->storage->addMessage($sessionId, 'assistant', "Answer {$i}");
    }

    $provider = createFakeProvider('Summary of the conversation.');

    $result = $this->summarizer->summarizeAndPersist(
        sessionId: $sessionId,
        provider: $provider,
        keepRecentTurns: 3,
    );

    expect($result->wasSummarized())->toBeTrue();

    // Verify a summary was persisted as a user message (not system —
    // AbstractAgent skips system messages from history)
    $messages = $this->storage->getMessages($sessionId);
    $summaryMessages = array_filter($messages, fn($m) =>
        $m['role'] === 'user' && str_contains($m['content'], '[CONVERSATION SUMMARY'),
    );

    expect(count($summaryMessages))->toBe(1);
});

test('summarizeAndPersist returns unchanged result for short conversations', function () {
    $sessionId = $this->storage->createSession('orchestrator', 'test/model');

    $this->storage->addMessage($sessionId, 'user', 'Hello');
    $this->storage->addMessage($sessionId, 'assistant', 'Hi!');

    $provider = createFakeProvider('summary');

    $result = $this->summarizer->summarizeAndPersist(
        sessionId: $sessionId,
        provider: $provider,
        keepRecentTurns: 3,
    );

    expect($result->wasSummarized())->toBeFalse();
});

// --- workflowContext ---

function createCapturingProvider(string $response): object
{
    return new class($response) implements ProviderInterface {
        /** @var list<array{role: string, content: string}> */
        public array $capturedMessages = [];

        public function __construct(private readonly string $response) {}

        public function chat(array $messages, array $tools = [], array $options = []): Response
        {
            $this->capturedMessages = array_map(
                fn($m) => ['role' => $m->role()->value, 'content' => $m->content()],
                $messages,
            );

            return new Response(
                content: $this->response,
                finishReason: FinishReason::Stop,
            );
        }

        public function stream(array $messages, array $tools = [], array $options = []): iterable
        {
            yield new Response(content: $this->response, finishReason: FinishReason::Stop);
        }

        public function structured(array $messages, string $schema, array $options = []): mixed
        {
            return new Response(content: $this->response, finishReason: FinishReason::Stop);
        }

        public function models(): array { return []; }
        public function isAvailable(): bool { return true; }
        public function getModel(): string { return 'test/model'; }
        public function withModel(string $model): static { return $this; }
    };
}

test('summarize includes workflow context in LLM prompt when provided', function () {
    $conv = buildConversation(10);
    $provider = createCapturingProvider('Summary with workflow context.');

    $result = $this->summarizer->summarize(
        $conv,
        $provider,
        keepRecentTurns: 3,
        workflowContext: "Active todos: 3 pending, 1 in-progress\nArtifacts: plan-v2 (final)",
    );

    expect($result->wasSummarized())->toBeTrue();

    // The system message sent to the LLM should contain the workflow context
    $systemContent = $provider->capturedMessages[0]['content'] ?? '';
    expect($systemContent)->toContain('Current workflow state');
    expect($systemContent)->toContain('Active todos: 3 pending');
    expect($systemContent)->toContain('plan-v2 (final)');
});

test('summarize omits workflow section when context is null', function () {
    $conv = buildConversation(10);
    $provider = createCapturingProvider('Summary without workflow.');

    $result = $this->summarizer->summarize(
        $conv,
        $provider,
        keepRecentTurns: 3,
        workflowContext: null,
    );

    expect($result->wasSummarized())->toBeTrue();

    $systemContent = $provider->capturedMessages[0]['content'] ?? '';
    expect($systemContent)->not->toContain('Current workflow state');
});

// --- Summary message role (critical bug fix) ---

test('summarize produces a UserMessage summary, not SystemMessage', function () {
    $conv = buildConversation(8);
    $provider = createFakeProvider('Summary of older turns.');

    $result = $this->summarizer->summarize($conv, $provider, keepRecentTurns: 3);

    expect($result->wasSummarized())->toBeTrue();

    // The summary message in the result conversation must be a UserMessage
    $summaryMsg = null;
    foreach ($result->conversation->messages() as $msg) {
        if (str_contains($msg->content(), '[CONVERSATION SUMMARY')) {
            $summaryMsg = $msg;
            break;
        }
    }

    expect($summaryMsg)->not->toBeNull();
    expect($summaryMsg->role())->toBe(Role::User);
    expect($summaryMsg)->toBeInstanceOf(UserMessage::class);
});

// --- DB cleanup after summarization ---

test('summarizeAndPersist deletes old messages from the database', function () {
    $sessionId = $this->storage->createSession('orchestrator', 'test/model');

    // Add 8 turns (16 messages)
    for ($i = 1; $i <= 8; $i++) {
        $this->storage->addMessage($sessionId, 'user', "Question {$i}");
        $this->storage->addMessage($sessionId, 'assistant', "Answer {$i}");
    }

    $messagesBefore = $this->storage->getMessages($sessionId);
    $countBefore = count($messagesBefore);

    $provider = createFakeProvider('Summary of the conversation.');

    $result = $this->summarizer->summarizeAndPersist(
        sessionId: $sessionId,
        provider: $provider,
        keepRecentTurns: 3,
    );

    expect($result->wasSummarized())->toBeTrue();

    // After summarization: old messages deleted, summary inserted,
    // remaining count should be less than before
    $messagesAfter = $this->storage->getMessages($sessionId);
    expect(count($messagesAfter))->toBeLessThan($countBefore);

    // The most recent 3 user turns should still be present
    $remainingUserMessages = array_filter($messagesAfter, fn($m) =>
        $m['role'] === 'user' && !str_contains($m['content'], '[CONVERSATION SUMMARY'),
    );
    expect(count($remainingUserMessages))->toBe(3);
});

test('summarizeAndPersist preserves system messages in the database', function () {
    $sessionId = $this->storage->createSession('orchestrator', 'test/model');

    // Add a system message plus turns
    $this->storage->addMessage($sessionId, 'system', 'You are a helpful assistant.');
    for ($i = 1; $i <= 8; $i++) {
        $this->storage->addMessage($sessionId, 'user', "Question {$i}");
        $this->storage->addMessage($sessionId, 'assistant', "Answer {$i}");
    }

    $provider = createFakeProvider('Summary of the conversation.');

    $this->summarizer->summarizeAndPersist(
        sessionId: $sessionId,
        provider: $provider,
        keepRecentTurns: 3,
    );

    $messagesAfter = $this->storage->getMessages($sessionId);
    $systemMessages = array_filter($messagesAfter, fn($m) => $m['role'] === 'system');

    // System messages are never deleted
    expect(count($systemMessages))->toBeGreaterThanOrEqual(1);
});

// --- formatSummaryMessage recency guidance ---

test('summary message contains recency guidance text', function () {
    $conv = buildConversation(8);
    $provider = createFakeProvider('Summary of older turns.');

    $result = $this->summarizer->summarize($conv, $provider, keepRecentTurns: 3);

    expect($result->wasSummarized())->toBeTrue();

    // Find the summary message and check for recency guidance
    foreach ($result->conversation->messages() as $msg) {
        if (str_contains($msg->content(), '[CONVERSATION SUMMARY')) {
            expect($msg->content())->toContain('Focus on the most recent messages below');
            expect($msg->content())->toContain('background context only');
            break;
        }
    }
});

// --- identifySummarizedMessageIds ---

test('identifySummarizedMessageIds correctly identifies messages before cut point', function () {
    $sessionId = $this->storage->createSession('orchestrator', 'test/model');

    // Add 6 turns (12 messages)
    for ($i = 1; $i <= 6; $i++) {
        $this->storage->addMessage($sessionId, 'user', "Question {$i}");
        $this->storage->addMessage($sessionId, 'assistant', "Answer {$i}");
    }

    $rawMessages = $this->storage->getMessages($sessionId);

    // Use reflection to test the private method
    $reflection = new ReflectionClass($this->summarizer);
    $method = $reflection->getMethod('identifySummarizedMessageIds');

    $ids = $method->invoke($this->summarizer, $rawMessages, 2);

    // With 6 user turns and keepRecentTurns=2, the first 4 user turns
    // (8 messages: 4 user + 4 assistant) should be identified for deletion
    expect(count($ids))->toBe(8);

    // Verify none of the last 2 user turns' messages are included
    $lastFourMessages = array_slice($rawMessages, -4);
    $lastFourIds = array_column($lastFourMessages, 'id');
    foreach ($lastFourIds as $id) {
        expect(in_array($id, $ids, true))->toBeFalse();
    }
});

test('identifySummarizedMessageIds returns empty when conversation is short', function () {
    $sessionId = $this->storage->createSession('orchestrator', 'test/model');

    // Add 2 turns (4 messages)
    for ($i = 1; $i <= 2; $i++) {
        $this->storage->addMessage($sessionId, 'user', "Question {$i}");
        $this->storage->addMessage($sessionId, 'assistant', "Answer {$i}");
    }

    $rawMessages = $this->storage->getMessages($sessionId);

    $reflection = new ReflectionClass($this->summarizer);
    $method = $reflection->getMethod('identifySummarizedMessageIds');

    $ids = $method->invoke($this->summarizer, $rawMessages, 3);

    expect($ids)->toBe([]);
});

test('identifySummarizedMessageIds never includes system messages', function () {
    $sessionId = $this->storage->createSession('orchestrator', 'test/model');

    // Add a system message first, then turns
    $this->storage->addMessage($sessionId, 'system', 'You are a helpful assistant.');
    for ($i = 1; $i <= 6; $i++) {
        $this->storage->addMessage($sessionId, 'user', "Question {$i}");
        $this->storage->addMessage($sessionId, 'assistant', "Answer {$i}");
    }

    $rawMessages = $this->storage->getMessages($sessionId);

    $reflection = new ReflectionClass($this->summarizer);
    $method = $reflection->getMethod('identifySummarizedMessageIds');

    $ids = $method->invoke($this->summarizer, $rawMessages, 2);

    // Find the system message ID
    $systemId = null;
    foreach ($rawMessages as $row) {
        if ($row['role'] === 'system') {
            $systemId = $row['id'];
            break;
        }
    }

    expect($systemId)->not->toBeNull();
    expect(in_array($systemId, $ids, true))->toBeFalse();
});
