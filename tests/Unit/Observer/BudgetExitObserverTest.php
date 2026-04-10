<?php

declare(strict_types=1);

use CoquiBot\Coqui\Observer\BudgetExitObserver;
use CarmeloSantana\PHPAgents\Message\UserMessage;

function makeBudgetSubjectStub(string $event, mixed $data): SplSubject
{
    return new class ($event, $data) implements SplSubject {
        public function __construct(
            private readonly string $eventName,
            private readonly mixed $eventData,
        ) {}

        public function lastEvent(): string
        {
            return $this->eventName;
        }

        public function lastEventData(): mixed
        {
            return $this->eventData;
        }

        public function attach(SplObserver $observer): void {}

        public function detach(SplObserver $observer): void {}

        public function notify(): void {}
    };
}

test('queues a workflow-aware wrap-up instruction for budget warnings', function () {
    $observer = new BudgetExitObserver(
        workflowContextBuilder: fn(): string => 'Todos: 1/3 completed',
    );

    $observer->update(makeBudgetSubjectStub('agent.budget_warning', [
        'usagePercent' => 84.6,
        'wrapUpIterations' => 2,
    ]));

    $messages = $observer->consumePendingInputs();

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(UserMessage::class)
        ->and($messages[0]->content())->toContain('[BUDGET WARNING] Context window is 85% consumed.')
        ->and($messages[0]->content())->toContain('You have 2 iteration(s) remaining')
        ->and($messages[0]->content())->toContain('Current workflow state:')
        ->and($messages[0]->content())->toContain('Todos: 1/3 completed')
        ->and($messages[0]->content())->toContain('Call done(response: "...") with this summary immediately.');
});

test('consumePendingInputs clears queued wrap-up messages', function () {
    $observer = new BudgetExitObserver();

    $observer->update(makeBudgetSubjectStub('agent.budget_warning', [
        'usagePercent' => 90,
        'wrapUpIterations' => 1,
    ]));

    expect($observer->consumePendingInputs())->toHaveCount(1)
        ->and($observer->consumePendingInputs())->toBe([]);
});

test('ignores non-budget events and invalid payloads', function () {
    $observer = new BudgetExitObserver();

    $observer->update(makeBudgetSubjectStub('agent.iteration', 3));
    $observer->update(makeBudgetSubjectStub('agent.budget_warning', 'not-an-array'));

    expect($observer->consumePendingInputs())->toBe([]);
});

test('continues without workflow context when the builder fails', function () {
    $observer = new BudgetExitObserver(
        workflowContextBuilder: static fn(): string => throw new RuntimeException('builder failed'),
    );

    $observer->update(makeBudgetSubjectStub('agent.budget_warning', [
        'usagePercent' => 92,
        'wrapUpIterations' => 2,
    ]));

    $messages = $observer->consumePendingInputs();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->content())->not->toContain('Current workflow state:');
});