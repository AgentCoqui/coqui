<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Enum\FinishReason;
use CarmeloSantana\PHPAgents\Provider\Response;
use CoquiBot\Coqui\Agent\GoalEvaluator;

/**
 * Create a stub ProviderInterface that returns a fixed response and captures messages.
 */
function makeGoalProvider(string $responseContent, ?array &$capturedMessages = null): ProviderInterface
{
    return new class ($responseContent, $capturedMessages) implements ProviderInterface {
        public function __construct(
            private readonly string $responseContent,
            private ?array &$capturedMessages,
        ) {}

        public function chat(array $messages, array $tools = [], array $options = []): Response
        {
            $this->capturedMessages = $messages;
            return new Response(content: $this->responseContent, finishReason: FinishReason::Stop);
        }

        public function stream(array $messages, array $tools = [], array $options = []): iterable { return []; }
        public function structured(array $messages, string $schema, array $options = []): mixed { return null; }
        public function models(): array { return []; }
        public function isAvailable(): bool { return true; }
        public function getModel(): string { return 'test/model'; }
        public function withModel(string $model): static { return $this; }
    };
}

function makeThrowingGoalProvider(\Throwable $exception): ProviderInterface
{
    return new class ($exception) implements ProviderInterface {
        public function __construct(private readonly \Throwable $exception) {}

        public function chat(array $messages, array $tools = [], array $options = []): Response
        {
            throw $this->exception;
        }

        public function stream(array $messages, array $tools = [], array $options = []): iterable { return []; }
        public function structured(array $messages, string $schema, array $options = []): mixed { return null; }
        public function models(): array { return []; }
        public function isAvailable(): bool { return true; }
        public function getModel(): string { return 'test/model'; }
        public function withModel(string $model): static { return $this; }
    };
}

// ──────────────────────────────────────────────
//  Achievement detection
// ──────────────────────────────────────────────

test('evaluate returns achieved when response starts with ACHIEVED', function () {
    $evaluator = new GoalEvaluator(makeGoalProvider("ACHIEVED\nThe feature is complete and all tests pass."));

    $result = $evaluator->evaluate('Build feature X', null, 'All tests passing.');

    expect($result->achieved)->toBeTrue();
    expect($result->rationale)->toBe('The feature is complete and all tests pass.');
});

test('evaluate returns not-achieved when response starts with NOT_ACHIEVED', function () {
    $evaluator = new GoalEvaluator(makeGoalProvider("NOT_ACHIEVED\nTests are still failing."));

    $result = $evaluator->evaluate('Build feature X', null, 'Some tests fail.');

    expect($result->achieved)->toBeFalse();
    expect($result->rationale)->toBe('Tests are still failing.');
});

test('evaluate treats line containing both ACHIEVED and NOT_ACHIEVED as not-achieved', function () {
    $evaluator = new GoalEvaluator(makeGoalProvider("NOT_ACHIEVED — goal not yet reached."));

    $result = $evaluator->evaluate('Build feature X', null, 'Partial work.');

    expect($result->achieved)->toBeFalse();
});

test('evaluate handles ACHIEVED with no rationale body', function () {
    $evaluator = new GoalEvaluator(makeGoalProvider("ACHIEVED"));

    $result = $evaluator->evaluate('Build feature X', null, 'Done.');

    expect($result->achieved)->toBeTrue();
    expect($result->rationale)->toBe('Goal achieved.');
});

test('evaluate handles NOT_ACHIEVED with no rationale body', function () {
    $evaluator = new GoalEvaluator(makeGoalProvider("NOT_ACHIEVED"));

    $result = $evaluator->evaluate('Build feature X', null, 'Partial.');

    expect($result->achieved)->toBeFalse();
    expect($result->rationale)->toBe('Goal not yet achieved.');
});

// ──────────────────────────────────────────────
//  Error handling
// ──────────────────────────────────────────────

test('evaluate falls back to not-achieved on provider exception', function () {
    $evaluator = new GoalEvaluator(makeThrowingGoalProvider(new \RuntimeException('API timeout')));

    $result = $evaluator->evaluate('Build feature X', null, 'Output.');

    expect($result->achieved)->toBeFalse();
    expect($result->rationale)->toContain('internal error');
});

// ──────────────────────────────────────────────
//  Prompt composition
// ──────────────────────────────────────────────

test('evaluate includes goal_prompt as evaluation criteria', function () {
    $captured = null;
    $provider = makeGoalProvider("NOT_ACHIEVED\nStill working.", $captured);

    $evaluator = new GoalEvaluator($provider);
    $evaluator->evaluate('Build feature X', 'Must have 100% test coverage', 'Output.');

    $userContent = $captured[1]->content();
    expect($userContent)->toContain('Evaluation Criteria');
    expect($userContent)->toContain('Must have 100% test coverage');
});

test('evaluate includes previous outcomes in prompt', function () {
    $captured = null;
    $provider = makeGoalProvider("NOT_ACHIEVED\nStill working.", $captured);

    $evaluator = new GoalEvaluator($provider);
    $evaluator->evaluate('Build feature X', null, 'Latest output.', [
        ['iteration_number' => 1, 'outcome_summary' => 'Partial implementation', 'status' => 'completed'],
        ['iteration_number' => 2, 'outcome_summary' => null, 'status' => 'failed'],
    ]);

    $userContent = $captured[1]->content();
    expect($userContent)->toContain('Previous Iterations');
    expect($userContent)->toContain('Iteration 1: Partial implementation');
    expect($userContent)->toContain('Iteration 2: failed');
});
