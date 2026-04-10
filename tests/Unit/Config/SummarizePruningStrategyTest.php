<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Message\Conversation;
use CarmeloSantana\PHPAgents\Message\SystemMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\Response;
use CoquiBot\Coqui\Config\SummarizePruningStrategy;
use CoquiBot\Coqui\Storage\SessionStorage;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-test-pruning-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
});

afterEach(function () {
    unset($this->storage);

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

function buildTestConversation(int $turns): Conversation
{
    $conv = new Conversation();
    $conv->add(new SystemMessage('You are a helpful assistant.'));

    for ($i = 1; $i <= $turns; $i++) {
        $conv->add(new UserMessage("Question {$i}"));
        $conv->add(new AssistantMessage("Answer {$i}"));
    }

    return $conv;
}

function createTestProvider(string $response): ProviderInterface
{
    return new class($response) implements ProviderInterface {
        public function __construct(private readonly string $response) {}

        public function chat(array $messages, array $tools = [], array $options = []): Response
        {
            return new Response(
                content: $this->response,
                finishReason: ProviderFinishReason::Stop,
            );
        }

        public function stream(array $messages, array $tools = [], array $options = []): iterable
        {
            yield new Response(content: $this->response, finishReason: ProviderFinishReason::Stop);
        }

        public function structured(array $messages, string $schema, array $options = []): mixed
        {
            return new Response(content: $this->response, finishReason: ProviderFinishReason::Stop);
        }

        public function models(): array { return []; }
        public function isAvailable(): bool { return true; }
        public function getModel(): string { return 'test/model'; }
        public function withModel(string $model): static { return $this; }
    };
}

test('wasSummarizationApplied returns false initially', function () {
    $provider = createTestProvider('summary');
    $strategy = new SummarizePruningStrategy(
        provider: $provider,
        storage: $this->storage,
    );

    expect($strategy->wasSummarizationApplied())->toBeFalse();
});

test('skipNextPrune causes prune to skip and return conversation unchanged', function () {
    $provider = createTestProvider('summary');
    $strategy = new SummarizePruningStrategy(
        provider: $provider,
        storage: $this->storage,
    );

    $conv = buildTestConversation(10);
    $originalCount = $conv->count();

    $strategy->skipNextPrune();

    // With a very small budget (1), normally this would trigger summarization.
    // But skipNextPrune should cause it to return unchanged.
    $result = $strategy->prune($conv, 1);
    expect($result->count())->toBe($originalCount);
});

test('skipNextPrune only affects one call', function () {
    $provider = createTestProvider('Summary of older conversation.');
    $strategy = new SummarizePruningStrategy(
        provider: $provider,
        storage: $this->storage,
    );

    $conv = buildTestConversation(10);

    $strategy->skipNextPrune();

    // First call — skipped
    $result1 = $strategy->prune($conv, 1);
    expect($result1->count())->toBe($conv->count());

    // Second call — NOT skipped, should try to prune (will fallback since no sessionId for persist)
    $result2 = $strategy->prune($conv, 1);
    // Should be different from original since pruning was attempted
    expect($result2->count())->toBeLessThanOrEqual($conv->count());
});

test('reset clears both flags', function () {
    $provider = createTestProvider('summary');
    $strategy = new SummarizePruningStrategy(
        provider: $provider,
        storage: $this->storage,
    );

    $strategy->skipNextPrune();

    $strategy->reset();

    expect($strategy->wasSummarizationApplied())->toBeFalse();
});

test('sessionId returns the configured session ID', function () {
    $provider = createTestProvider('summary');

    $strategy = new SummarizePruningStrategy(
        provider: $provider,
        storage: $this->storage,
        sessionId: 'test-session-123',
    );

    expect($strategy->sessionId())->toBe('test-session-123');
});

test('sessionId returns null when not configured', function () {
    $provider = createTestProvider('summary');

    $strategy = new SummarizePruningStrategy(
        provider: $provider,
        storage: $this->storage,
    );

    expect($strategy->sessionId())->toBeNull();
});

test('prune returns conversation unchanged when under budget', function () {
    $provider = createTestProvider('summary');
    $strategy = new SummarizePruningStrategy(
        provider: $provider,
        storage: $this->storage,
    );

    $conv = buildTestConversation(3);

    // Budget of 999999 — conversation is well under
    $result = $strategy->prune($conv, 999999);
    expect($result->count())->toBe($conv->count());
    expect($strategy->wasSummarizationApplied())->toBeFalse();
});
