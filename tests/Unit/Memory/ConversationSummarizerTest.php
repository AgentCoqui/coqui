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

    // Count user messages in the result conversation (should be 4 kept + 0 summarized)
    $userMessages = array_filter(
        $result->conversation->messages(),
        fn($m) => $m->role() === Role::User,
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

    // Verify a summary system message was persisted
    $messages = $this->storage->getMessages($sessionId);
    $summaryMessages = array_filter($messages, fn($m) =>
        $m['role'] === 'system' && str_contains($m['content'], '[CONVERSATION SUMMARY'),
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
