<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Agent\ToolBoundEvaluator;
use CoquiBot\Coqui\Contract\ToolExecutorInterface;

/**
 * Create a stub ToolExecutorInterface that returns a fixed ToolResult.
 */
function makeToolExecutor(ToolResult $result): ToolExecutorInterface
{
    return new class ($result) implements ToolExecutorInterface {
        public function __construct(private readonly ToolResult $result) {}

        public function execute(string $toolName, array $arguments): ToolResult
        {
            return $this->result;
        }
    };
}

function makeThrowingToolExecutor(\Throwable $exception): ToolExecutorInterface
{
    return new class ($exception) implements ToolExecutorInterface {
        public function __construct(private readonly \Throwable $exception) {}

        public function execute(string $toolName, array $arguments): ToolResult
        {
            throw $this->exception;
        }
    };
}

// ──────────────────────────────────────────────
//  Operator comparison
// ──────────────────────────────────────────────

test('evaluate returns met=true when value meets >= threshold', function () {
    $evaluator = new ToolBoundEvaluator(makeToolExecutor(ToolResult::success('95')));

    $result = $evaluator->evaluate('run_tests', [], '>=', 90.0);

    expect($result->met)->toBeTrue();
    expect($result->actualValue)->toBe(95.0);
    expect($result->operator)->toBe('>=');
    expect($result->threshold)->toBe(90.0);
    expect($result->error)->toBeNull();
});

test('evaluate returns met=false when value below >= threshold', function () {
    $evaluator = new ToolBoundEvaluator(makeToolExecutor(ToolResult::success('85')));

    $result = $evaluator->evaluate('run_tests', [], '>=', 90.0);

    expect($result->met)->toBeFalse();
    expect($result->actualValue)->toBe(85.0);
});

test('evaluate handles greater-than operator', function () {
    $evaluator = new ToolBoundEvaluator(makeToolExecutor(ToolResult::success('90')));

    expect($evaluator->evaluate('t', [], '>', 90.0)->met)->toBeFalse();
    expect($evaluator->evaluate('t', [], '>', 89.9)->met)->toBeTrue();
});

test('evaluate handles less-than-or-equal operator', function () {
    $evaluator = new ToolBoundEvaluator(makeToolExecutor(ToolResult::success('5')));

    expect($evaluator->evaluate('t', [], '<=', 5.0)->met)->toBeTrue();
    expect($evaluator->evaluate('t', [], '<=', 4.0)->met)->toBeFalse();
});

test('evaluate handles less-than operator', function () {
    $evaluator = new ToolBoundEvaluator(makeToolExecutor(ToolResult::success('3')));

    expect($evaluator->evaluate('t', [], '<', 5.0)->met)->toBeTrue();
    expect($evaluator->evaluate('t', [], '<', 3.0)->met)->toBeFalse();
});

test('evaluate handles equality operator with float epsilon', function () {
    $evaluator = new ToolBoundEvaluator(makeToolExecutor(ToolResult::success('100')));

    expect($evaluator->evaluate('t', [], '==', 100.0)->met)->toBeTrue();
    expect($evaluator->evaluate('t', [], '==', 100.1)->met)->toBeFalse();
});

test('evaluate handles not-equal operator with float epsilon', function () {
    $evaluator = new ToolBoundEvaluator(makeToolExecutor(ToolResult::success('50')));

    expect($evaluator->evaluate('t', [], '!=', 50.0)->met)->toBeFalse();
    expect($evaluator->evaluate('t', [], '!=', 51.0)->met)->toBeTrue();
});

test('evaluate returns met=false for unknown operator', function () {
    $evaluator = new ToolBoundEvaluator(makeToolExecutor(ToolResult::success('50')));

    expect($evaluator->evaluate('t', [], '===', 50.0)->met)->toBeFalse();
});

// ──────────────────────────────────────────────
//  Numeric parsing
// ──────────────────────────────────────────────

test('evaluate parses plain numeric string', function () {
    $evaluator = new ToolBoundEvaluator(makeToolExecutor(ToolResult::success('42')));

    $result = $evaluator->evaluate('t', [], '>=', 40.0);

    expect($result->met)->toBeTrue();
    expect($result->actualValue)->toBe(42.0);
});

test('evaluate parses leading number from mixed output', function () {
    $evaluator = new ToolBoundEvaluator(makeToolExecutor(ToolResult::success('42 tests passed')));

    $result = $evaluator->evaluate('t', [], '>=', 40.0);

    expect($result->met)->toBeTrue();
    expect($result->actualValue)->toBe(42.0);
});

test('evaluate parses decimal numbers', function () {
    $evaluator = new ToolBoundEvaluator(makeToolExecutor(ToolResult::success('95.5')));

    $result = $evaluator->evaluate('t', [], '>=', 95.0);

    expect($result->met)->toBeTrue();
    expect($result->actualValue)->toBe(95.5);
});

test('evaluate parses negative numbers', function () {
    $evaluator = new ToolBoundEvaluator(makeToolExecutor(ToolResult::success('-3.14')));

    $result = $evaluator->evaluate('t', [], '<', 0.0);

    expect($result->met)->toBeTrue();
    expect($result->actualValue)->toBe(-3.14);
});

test('evaluate handles whitespace-padded output', function () {
    $evaluator = new ToolBoundEvaluator(makeToolExecutor(ToolResult::success('  42  ')));

    $result = $evaluator->evaluate('t', [], '==', 42.0);

    expect($result->met)->toBeTrue();
});

test('evaluate handles zero', function () {
    $evaluator = new ToolBoundEvaluator(makeToolExecutor(ToolResult::success('0')));

    $result = $evaluator->evaluate('t', [], '==', 0.0);

    expect($result->met)->toBeTrue();
    expect($result->actualValue)->toBe(0.0);
});

// ──────────────────────────────────────────────
//  Error handling
// ──────────────────────────────────────────────

test('evaluate returns error for non-numeric tool output', function () {
    $evaluator = new ToolBoundEvaluator(makeToolExecutor(ToolResult::success('no results found')));

    $result = $evaluator->evaluate('run_tests', [], '>=', 90.0);

    expect($result->met)->toBeFalse();
    expect($result->error)->toContain('not numeric');
    expect($result->error)->toContain('run_tests');
});

test('evaluate returns error for empty tool output', function () {
    $evaluator = new ToolBoundEvaluator(makeToolExecutor(ToolResult::success('')));

    $result = $evaluator->evaluate('run_tests', [], '>=', 90.0);

    expect($result->met)->toBeFalse();
    expect($result->error)->toContain('not numeric');
});

test('evaluate returns error when tool returns error status', function () {
    $evaluator = new ToolBoundEvaluator(makeToolExecutor(ToolResult::error('Tool not found')));

    $result = $evaluator->evaluate('run_tests', [], '>=', 90.0);

    expect($result->met)->toBeFalse();
    expect($result->error)->toBe('Tool not found');
    expect($result->actualValue)->toBe(0.0);
});

test('evaluate catches tool execution exceptions gracefully', function () {
    $evaluator = new ToolBoundEvaluator(makeThrowingToolExecutor(new \RuntimeException('Connection refused')));

    $result = $evaluator->evaluate('run_tests', [], '>=', 90.0);

    expect($result->met)->toBeFalse();
    expect($result->error)->toContain('Connection refused');
    expect($result->error)->toContain('Tool execution failed');
});
