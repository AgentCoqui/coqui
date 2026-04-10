<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\GoalEvaluationResult;

test('constructs with achieved=true and rationale', function () {
    $result = new GoalEvaluationResult(achieved: true, rationale: 'All tests pass');

    expect($result->achieved)->toBeTrue();
    expect($result->rationale)->toBe('All tests pass');
});

test('constructs with achieved=false and rationale', function () {
    $result = new GoalEvaluationResult(achieved: false, rationale: 'Coverage below 80%');

    expect($result->achieved)->toBeFalse();
    expect($result->rationale)->toBe('Coverage below 80%');
});

test('is immutable readonly', function () {
    $result = new GoalEvaluationResult(achieved: true, rationale: 'Done');

    $reflection = new ReflectionClass($result);
    expect($reflection->isReadOnly())->toBeTrue();
});
