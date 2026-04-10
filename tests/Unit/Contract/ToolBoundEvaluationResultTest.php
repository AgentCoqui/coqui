<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\ToolBoundEvaluationResult;

test('constructs successful evaluation with all fields', function () {
    $result = new ToolBoundEvaluationResult(
        met: true,
        actualValue: 95.5,
        operator: '>=',
        threshold: 80.0,
    );

    expect($result->met)->toBeTrue();
    expect($result->actualValue)->toBe(95.5);
    expect($result->operator)->toBe('>=');
    expect($result->threshold)->toBe(80.0);
    expect($result->error)->toBeNull();
});

test('constructs failed evaluation with error', function () {
    $result = new ToolBoundEvaluationResult(
        met: false,
        actualValue: 0.0,
        operator: '>=',
        threshold: 80.0,
        error: 'Tool returned non-numeric output',
    );

    expect($result->met)->toBeFalse();
    expect($result->error)->toBe('Tool returned non-numeric output');
});

test('is immutable readonly', function () {
    $result = new ToolBoundEvaluationResult(
        met: true,
        actualValue: 1.0,
        operator: '==',
        threshold: 1.0,
    );

    $reflection = new ReflectionClass($result);
    expect($reflection->isReadOnly())->toBeTrue();
});
