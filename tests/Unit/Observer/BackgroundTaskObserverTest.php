<?php

declare(strict_types=1);

use CoquiBot\Coqui\Observer\BackgroundTaskObserver;
use CoquiBot\Coqui\Storage\SessionStorage;
use CarmeloSantana\PHPAgents\Agent\Output;
use CarmeloSantana\PHPAgents\Contract\AgentInterface;
use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Message\Conversation;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Enum\ModelCapability;

/**
 * Create a minimal AgentInterface stub that can emit any event/data pair.
 */
function makeAgentStub(string $event, mixed $data): AgentInterface
{
    return new class ($event, $data) implements AgentInterface {
        public function __construct(
            private readonly string $eventName,
            private readonly mixed $eventData,
        ) {}

        public function lastEvent(): string { return $this->eventName; }
        public function lastEventData(): mixed { return $this->eventData; }

        public function instructions(): string { return ''; }
        public function tools(): array { return []; }
        public function provider(): ProviderInterface
        {
            return new class implements ProviderInterface {
                public function chat(array $messages, array $tools = [], array $options = []): Response
                {
                    return new Response(content: '', finishReason: ProviderFinishReason::Stop, model: 'test');
                }
                public function stream(array $messages, array $tools = [], array $options = []): iterable { return []; }
                public function structured(array $messages, string $schema, array $options = []): mixed { return null; }
                public function models(): array { return []; }
                public function isAvailable(): bool { return false; }
                public function getModel(): string { return 'test'; }
                public function withModel(string $model): static { return $this; }
            };
        }
        public function run(MessageInterface $input, ?Conversation $history = null): Output
        {
            return new Output(content: '', iterations: 0);
        }
        public function maxIterations(): int { return 1; }
        public function requiredCapabilities(): array { return []; }

        // SplSubject interface
        public function attach(\SplObserver $observer): void {}
        public function detach(\SplObserver $observer): void {}
        public function notify(): void {}
    };
}

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-bg-observer-test-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);

    // Create a task we can append events to
    $this->sessionId = $this->storage->createSession('orchestrator', 'test/model');
    $this->taskId = $this->storage->createTask($this->sessionId, 'What is 2+2?');

    $this->observer = new BackgroundTaskObserver($this->storage, $this->taskId);
});

afterEach(function () {
    if (file_exists($this->dbPath)) {
        unlink($this->dbPath);
    }
});

// --- agent.reasoning ---

test('agent.reasoning is persisted as reasoning event type', function () {
    $agent = makeAgentStub('agent.reasoning', 'I am thinking step by step...');

    $this->observer->update($agent);

    $events = $this->storage->getTaskEvents($this->taskId);

    $reasoningEvents = array_filter($events, fn($e) => $e['event_type'] === 'reasoning');
    expect($reasoningEvents)->toHaveCount(1);

    $event = array_values($reasoningEvents)[0];
    $data = json_decode($event['data'], true);
    expect($data['content'])->toBe('I am thinking step by step...');
});

test('agent.reasoning with non-string data persists empty content', function () {
    $agent = makeAgentStub('agent.reasoning', 42);

    $this->observer->update($agent);

    $events = $this->storage->getTaskEvents($this->taskId);
    $reasoningEvents = array_filter($events, fn($e) => $e['event_type'] === 'reasoning');
    expect($reasoningEvents)->toHaveCount(1);

    $event = array_values($reasoningEvents)[0];
    $data = json_decode($event['data'], true);
    expect($data['content'])->toBe('');
});

test('multiple reasoning chunks are all persisted', function () {
    foreach (['chunk A', 'chunk B', 'chunk C'] as $chunk) {
        $this->observer->update(makeAgentStub('agent.reasoning', $chunk));
    }

    $events = $this->storage->getTaskEvents($this->taskId);
    $reasoningEvents = array_filter($events, fn($e) => $e['event_type'] === 'reasoning');
    expect($reasoningEvents)->toHaveCount(3);
});

// --- other events persist correctly ---

test('agent.start is persisted as agent_start event', function () {
    $this->observer->update(makeAgentStub('agent.start', null));

    $events = $this->storage->getTaskEvents($this->taskId);
    $found = array_filter($events, fn($e) => $e['event_type'] === 'agent_start');
    expect($found)->toHaveCount(1);
});

test('agent.iteration is persisted as iteration event with number', function () {
    $this->observer->update(makeAgentStub('agent.iteration', 3));

    $events = $this->storage->getTaskEvents($this->taskId);
    $found = array_filter($events, fn($e) => $e['event_type'] === 'iteration');
    expect($found)->toHaveCount(1);

    $data = json_decode(array_values($found)[0]['data'], true);
    expect($data['number'])->toBe(3);
});

test('agent.text_delta is persisted as text_delta event with content', function () {
    $this->observer->update(makeAgentStub('agent.text_delta', 'Hello world'));

    $events = $this->storage->getTaskEvents($this->taskId);
    $found = array_filter($events, fn($e) => $e['event_type'] === 'text_delta');
    expect($found)->toHaveCount(1);

    $data = json_decode(array_values($found)[0]['data'], true);
    expect($data['content'])->toBe('Hello world');
});

test('unknown event is silently ignored and not persisted', function () {
    $this->observer->update(makeAgentStub('totally.unknown', 'data'));

    $events = $this->storage->getTaskEvents($this->taskId);
    expect($events)->toBeEmpty();
});

test('update ignores non-AgentInterface subjects', function () {
    $subject = new class implements \SplSubject {
        public function attach(\SplObserver $observer): void {}
        public function detach(\SplObserver $observer): void {}
        public function notify(): void {}
    };

    // Should not throw — non-AgentInterface subjects are silently skipped
    $this->observer->update($subject);

    $events = $this->storage->getTaskEvents($this->taskId);
    expect($events)->toBeEmpty();
});
