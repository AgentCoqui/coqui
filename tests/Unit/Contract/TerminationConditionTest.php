<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\TerminationCondition;
use CoquiBot\Coqui\Contract\TerminationType;

// ──────────────────────────────────────────────
//  EvaluationBound
// ──────────────────────────────────────────────

test('evaluation_bound with criteria and default max_review_rounds', function () {
    $tc = new TerminationCondition(
        type: TerminationType::EvaluationBound,
        criteria: 'Must pass all tests',
    );

    expect($tc->type)->toBe(TerminationType::EvaluationBound);
    expect($tc->criteria)->toBe('Must pass all tests');
    expect($tc->maxReviewRounds)->toBe(5);
});

test('evaluation_bound with custom max_review_rounds', function () {
    $tc = new TerminationCondition(
        type: TerminationType::EvaluationBound,
        criteria: 'Explicit approval',
        maxReviewRounds: 10,
    );

    expect($tc->maxReviewRounds)->toBe(10);
});

test('evaluation_bound throws on null criteria', function () {
    new TerminationCondition(type: TerminationType::EvaluationBound);
})->throws(\InvalidArgumentException::class, 'criteria');

test('evaluation_bound throws on empty criteria', function () {
    new TerminationCondition(type: TerminationType::EvaluationBound, criteria: '');
})->throws(\InvalidArgumentException::class, 'criteria');

test('evaluation_bound fromArray with value as string', function () {
    $tc = TerminationCondition::fromArray([
        'type' => 'evaluation_bound',
        'value' => 'Must be approved by reviewer',
    ]);

    expect($tc->criteria)->toBe('Must be approved by reviewer');
    expect($tc->maxReviewRounds)->toBe(5);
});

test('evaluation_bound fromArray with value as object', function () {
    $tc = TerminationCondition::fromArray([
        'type' => 'evaluation_bound',
        'value' => ['criteria' => 'All tests pass', 'max_review_rounds' => 3],
    ]);

    expect($tc->criteria)->toBe('All tests pass');
    expect($tc->maxReviewRounds)->toBe(3);
});

// ──────────────────────────────────────────────
//  IterationBound
// ──────────────────────────────────────────────

test('iteration_bound with valid value', function () {
    $tc = new TerminationCondition(
        type: TerminationType::IterationBound,
        maxIterations: 10,
    );

    expect($tc->type)->toBe(TerminationType::IterationBound);
    expect($tc->maxIterations)->toBe(10);
});

test('iteration_bound throws on null value', function () {
    new TerminationCondition(type: TerminationType::IterationBound);
})->throws(\InvalidArgumentException::class, 'value');

test('iteration_bound throws on zero value', function () {
    new TerminationCondition(type: TerminationType::IterationBound, maxIterations: 0);
})->throws(\InvalidArgumentException::class, 'value');

test('iteration_bound throws on negative value', function () {
    new TerminationCondition(type: TerminationType::IterationBound, maxIterations: -1);
})->throws(\InvalidArgumentException::class, 'value');

test('iteration_bound fromArray', function () {
    $tc = TerminationCondition::fromArray([
        'type' => 'iteration_bound',
        'value' => 5,
    ]);

    expect($tc->maxIterations)->toBe(5);
});

test('iteration_bound fromArray with string numeric value', function () {
    $tc = TerminationCondition::fromArray([
        'type' => 'iteration_bound',
        'value' => '7',
    ]);

    expect($tc->maxIterations)->toBe(7);
});

// ──────────────────────────────────────────────
//  TimeBound
// ──────────────────────────────────────────────

test('time_bound with valid deadline', function () {
    $tc = new TerminationCondition(
        type: TerminationType::TimeBound,
        deadline: '2025-12-31T23:59:59Z',
    );

    expect($tc->type)->toBe(TerminationType::TimeBound);
    expect($tc->deadline)->toBe('2025-12-31T23:59:59Z');
});

test('time_bound throws on null deadline', function () {
    new TerminationCondition(type: TerminationType::TimeBound);
})->throws(\InvalidArgumentException::class, 'value');

test('time_bound throws on empty deadline', function () {
    new TerminationCondition(type: TerminationType::TimeBound, deadline: '');
})->throws(\InvalidArgumentException::class, 'value');

test('time_bound fromArray', function () {
    $tc = TerminationCondition::fromArray([
        'type' => 'time_bound',
        'value' => '2025-06-01T00:00:00Z',
    ]);

    expect($tc->deadline)->toBe('2025-06-01T00:00:00Z');
});

// ──────────────────────────────────────────────
//  Manual
// ──────────────────────────────────────────────

test('manual type requires no extra fields', function () {
    $tc = new TerminationCondition(type: TerminationType::Manual);

    expect($tc->type)->toBe(TerminationType::Manual);
    expect($tc->criteria)->toBeNull();
    expect($tc->maxIterations)->toBeNull();
    expect($tc->deadline)->toBeNull();
});

test('manual fromArray', function () {
    $tc = TerminationCondition::fromArray(['type' => 'manual']);

    expect($tc->type)->toBe(TerminationType::Manual);
});

// ──────────────────────────────────────────────
//  toArray round-trip
// ──────────────────────────────────────────────

test('evaluation_bound toArray round-trip', function () {
    $tc = new TerminationCondition(
        type: TerminationType::EvaluationBound,
        criteria: 'Approval required',
        maxReviewRounds: 3,
    );

    $arr = $tc->toArray();

    expect($arr['type'])->toBe('evaluation_bound');
    expect($arr['value']['criteria'])->toBe('Approval required');
    expect($arr['value']['max_review_rounds'])->toBe(3);

    $roundTripped = TerminationCondition::fromArray($arr);
    expect($roundTripped->type)->toBe($tc->type);
    expect($roundTripped->criteria)->toBe($tc->criteria);
    expect($roundTripped->maxReviewRounds)->toBe($tc->maxReviewRounds);
});

test('iteration_bound toArray round-trip', function () {
    $tc = new TerminationCondition(
        type: TerminationType::IterationBound,
        maxIterations: 10,
    );

    $arr = $tc->toArray();
    $roundTripped = TerminationCondition::fromArray($arr);

    expect($roundTripped->maxIterations)->toBe(10);
});

test('time_bound toArray round-trip', function () {
    $tc = new TerminationCondition(
        type: TerminationType::TimeBound,
        deadline: '2025-12-31T00:00:00Z',
    );

    $arr = $tc->toArray();
    $roundTripped = TerminationCondition::fromArray($arr);

    expect($roundTripped->deadline)->toBe('2025-12-31T00:00:00Z');
});

test('manual toArray round-trip', function () {
    $tc = new TerminationCondition(type: TerminationType::Manual);

    $arr = $tc->toArray();
    $roundTripped = TerminationCondition::fromArray($arr);

    expect($roundTripped->type)->toBe(TerminationType::Manual);
});

// ──────────────────────────────────────────────
//  Error cases
// ──────────────────────────────────────────────

test('fromArray throws on unknown type', function () {
    TerminationCondition::fromArray(['type' => 'nonexistent_type']);
})->throws(\InvalidArgumentException::class, 'Unknown termination type');

test('fromArray throws on empty type', function () {
    TerminationCondition::fromArray(['type' => '']);
})->throws(\InvalidArgumentException::class, 'Unknown termination type');

test('fromArray throws on missing type', function () {
    TerminationCondition::fromArray([]);
})->throws(\InvalidArgumentException::class, 'Unknown termination type');
