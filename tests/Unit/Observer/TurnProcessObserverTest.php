<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Agent\Output;
use CarmeloSantana\PHPAgents\Contract\AgentInterface;
use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Message\Conversation;
use CarmeloSantana\PHPAgents\Provider\Response;
use CoquiBot\Coqui\Observer\TurnProcessObserver;
use CoquiBot\Coqui\Storage\SessionStorage;

/**
 * Create a minimal AgentInterface stub that can emit any event/data pair.
 */
function makeTurnAgentStub(string $event, mixed $data): AgentInterface
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

        public function attach(\SplObserver $observer): void {}
        public function detach(\SplObserver $observer): void {}
        public function notify(): void {}
    };
}

function makeTurnTransientSubject(string $event, mixed $data): SplSubject
{
    return new class ($event, $data) implements SplSubject {
        public function __construct(
            private readonly string $eventName,
            private readonly mixed $eventData,
        ) {}

        public function getEventName(): string { return $this->eventName; }
        public function getEventData(): mixed { return $this->eventData; }
        public function attach(SplObserver $observer): void {}
        public function detach(SplObserver $observer): void {}
        public function notify(): void {}
    };
}

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-turn-observer-test-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
    $this->sessionId = $this->storage->createSession('orchestrator', 'test/model');
    $this->turnProcessId = $this->storage->createTurnProcess($this->sessionId, 'What is 2+2?');

    $this->observer = new TurnProcessObserver($this->storage, $this->turnProcessId);
});

afterEach(function () {
    releaseTestObjectProperties($this);
    cleanupSqliteTestDb($this->dbPath);
});

test('agent.reasoning is persisted as reasoning event type for turn processes', function () {
    $agent = makeTurnAgentStub('agent.reasoning', 'I am thinking step by step...');

    $this->observer->update($agent);

    $events = $this->storage->getTurnEvents($this->turnProcessId);

    $reasoningEvents = array_filter($events, fn($e) => $e['event_type'] === 'reasoning');
    expect($reasoningEvents)->toHaveCount(1);

    $event = array_values($reasoningEvents)[0];
    $data = json_decode($event['data'], true);
    expect($data['content'])->toBe('I am thinking step by step...');
});

test('agent.iteration is persisted without requiring background task heartbeat state', function () {
    $this->observer->update(makeTurnAgentStub('agent.iteration', 2));

    $events = $this->storage->getTurnEvents($this->turnProcessId);
    expect($events)->toHaveCount(1);
    expect($events[0]['event_type'])->toBe('iteration');

    $turnProcess = $this->storage->getTurnProcess($this->turnProcessId);
    expect($turnProcess['status'])->toBe('pending');
});

test('unknown turn observer event is silently ignored', function () {
    $this->observer->update(makeTurnAgentStub('totally.unknown', 'data'));

    $events = $this->storage->getTurnEvents($this->turnProcessId);
    expect($events)->toBeEmpty();
});

test('turn process observer persists review lifecycle events with depth metadata', function () {
    $this->observer->update(makeTurnAgentStub('child.review_start', [
        'round' => 2,
        'max_rounds' => 3,
    ]));
    $this->observer->update(makeTurnAgentStub('child.review_end', [
        'round' => 2,
        'approved' => true,
        'verdict' => 'approved',
    ]));

    $events = $this->storage->getTurnEvents($this->turnProcessId);

    expect($events)->toHaveCount(2);
    expect($events[0]['event_type'])->toBe('review_start');
    expect(json_decode((string) $events[0]['data'], true))->toBe([
        'round' => 2,
        'max_rounds' => 3,
        'depth' => 0,
    ]);
    expect($events[1]['event_type'])->toBe('review_end');
    expect(json_decode((string) $events[1]['data'], true))->toBe([
        'round' => 2,
        'verdict' => 'approved',
        'approved' => true,
        'depth' => 0,
    ]);
});

test('turn process observer persists budget and notification events', function () {
    $this->observer->update(makeTurnAgentStub('agent.budget_warning', [
        'usage_percent' => 91.2,
        'threshold_percent' => 90.0,
    ]));
    $this->observer->update(makeTurnAgentStub('agent.notification', [
        'kind' => 'task.completed',
        'title' => 'Build finished',
    ]));

    $events = $this->storage->getTurnEvents($this->turnProcessId);

    expect($events)->toHaveCount(2);
    expect($events[0]['event_type'])->toBe('budget_warning');
    expect(json_decode((string) $events[0]['data'], true)['usage_percent'])->toBe(91.2);
    expect($events[1]['event_type'])->toBe('notification');
    expect(json_decode((string) $events[1]['data'], true)['kind'])->toBe('task.completed');
});

test('turn process observer persists transient loop events', function () {
    $this->observer->update(makeTurnTransientSubject('loop.stage_start', [
        'loop_id' => 'loop-123',
        'iteration' => 2,
        'role' => 'coder',
    ]));

    $events = $this->storage->getTurnEvents($this->turnProcessId);

    expect($events)->toHaveCount(1);
    expect($events[0]['event_type'])->toBe('loop_stage_start');
    expect(json_decode((string) $events[0]['data'], true))->toBe([
        'loop_id' => 'loop-123',
        'iteration' => 2,
        'role' => 'coder',
    ]);
});

test('turn process observer adds actor metadata when configured for a group responder', function () {
    $observer = new TurnProcessObserver($this->storage, $this->turnProcessId, 'nova', 'orchestrator');

    $observer->update(makeTurnAgentStub('agent.text_delta', 'Let me take point on this.'));

    $events = $this->storage->getTurnEvents($this->turnProcessId);

    expect($events)->toHaveCount(1);
    expect($events[0]['event_type'])->toBe('text_delta');
    expect(json_decode((string) $events[0]['data'], true))->toBe([
        'content' => 'Let me take point on this.',
        'actor_name' => 'nova',
        'actor_role' => 'orchestrator',
    ]);
});