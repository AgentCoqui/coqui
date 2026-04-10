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

// ──────────────────────────────────────────────
//  GoalBound
// ──────────────────────────────────────────────

test('goal_bound with goal_prompt and max_iterations', function () {
    $tc = new TerminationCondition(
        type: TerminationType::GoalBound,
        goalPrompt: 'Has the feature been fully implemented?',
        maxIterations: 10,
    );

    expect($tc->type)->toBe(TerminationType::GoalBound);
    expect($tc->goalPrompt)->toBe('Has the feature been fully implemented?');
    expect($tc->maxIterations)->toBe(10);
});

test('goal_bound allows null goal_prompt', function () {
    $tc = new TerminationCondition(
        type: TerminationType::GoalBound,
        maxIterations: 5,
    );

    expect($tc->goalPrompt)->toBeNull();
    expect($tc->maxIterations)->toBe(5);
});

test('goal_bound throws on missing max_iterations', function () {
    new TerminationCondition(type: TerminationType::GoalBound);
})->throws(\InvalidArgumentException::class, 'max_iterations');

test('goal_bound throws on zero max_iterations', function () {
    new TerminationCondition(type: TerminationType::GoalBound, maxIterations: 0);
})->throws(\InvalidArgumentException::class, 'max_iterations');

test('goal_bound throws on negative max_iterations', function () {
    new TerminationCondition(type: TerminationType::GoalBound, maxIterations: -1);
})->throws(\InvalidArgumentException::class, 'max_iterations');

test('goal_bound fromArray with goal_prompt and max_iterations', function () {
    $tc = TerminationCondition::fromArray([
        'type' => 'goal_bound',
        'value' => [
            'goal_prompt' => 'Is the test suite green?',
            'max_iterations' => 8,
        ],
    ]);

    expect($tc->type)->toBe(TerminationType::GoalBound);
    expect($tc->goalPrompt)->toBe('Is the test suite green?');
    expect($tc->maxIterations)->toBe(8);
});

test('goal_bound fromArray without goal_prompt', function () {
    $tc = TerminationCondition::fromArray([
        'type' => 'goal_bound',
        'value' => ['max_iterations' => 3],
    ]);

    expect($tc->goalPrompt)->toBeNull();
    expect($tc->maxIterations)->toBe(3);
});

test('goal_bound toArray round-trip', function () {
    $tc = new TerminationCondition(
        type: TerminationType::GoalBound,
        goalPrompt: 'Verify the goal',
        maxIterations: 6,
    );

    $arr = $tc->toArray();

    expect($arr['type'])->toBe('goal_bound');
    expect($arr['value']['goal_prompt'])->toBe('Verify the goal');
    expect($arr['value']['max_iterations'])->toBe(6);

    $roundTripped = TerminationCondition::fromArray($arr);
    expect($roundTripped->type)->toBe($tc->type);
    expect($roundTripped->goalPrompt)->toBe($tc->goalPrompt);
    expect($roundTripped->maxIterations)->toBe($tc->maxIterations);
});

test('goal_bound toArray omits null goal_prompt', function () {
    $tc = new TerminationCondition(
        type: TerminationType::GoalBound,
        maxIterations: 5,
    );

    $arr = $tc->toArray();

    expect($arr['value'])->not->toHaveKey('goal_prompt');
    expect($arr['value']['max_iterations'])->toBe(5);
});

// ──────────────────────────────────────────────
//  ToolBound
// ──────────────────────────────────────────────

test('tool_bound with all fields', function () {
    $tc = new TerminationCondition(
        type: TerminationType::ToolBound,
        toolName: 'run_tests',
        toolArguments: ['suite' => 'unit'],
        operator: '>=',
        threshold: 95.0,
        maxIterations: 10,
    );

    expect($tc->type)->toBe(TerminationType::ToolBound);
    expect($tc->toolName)->toBe('run_tests');
    expect($tc->toolArguments)->toBe(['suite' => 'unit']);
    expect($tc->operator)->toBe('>=');
    expect($tc->threshold)->toBe(95.0);
    expect($tc->maxIterations)->toBe(10);
});

test('tool_bound allows null arguments', function () {
    $tc = new TerminationCondition(
        type: TerminationType::ToolBound,
        toolName: 'check_coverage',
        operator: '>',
        threshold: 80.0,
        maxIterations: 5,
    );

    expect($tc->toolArguments)->toBeNull();
});

test('tool_bound throws on missing tool name', function () {
    new TerminationCondition(
        type: TerminationType::ToolBound,
        operator: '>=',
        threshold: 90.0,
        maxIterations: 5,
    );
})->throws(\InvalidArgumentException::class, 'tool');

test('tool_bound throws on empty tool name', function () {
    new TerminationCondition(
        type: TerminationType::ToolBound,
        toolName: '',
        operator: '>=',
        threshold: 90.0,
        maxIterations: 5,
    );
})->throws(\InvalidArgumentException::class, 'tool');

test('tool_bound throws on invalid operator', function () {
    new TerminationCondition(
        type: TerminationType::ToolBound,
        toolName: 'run_tests',
        operator: '===',
        threshold: 90.0,
        maxIterations: 5,
    );
})->throws(\InvalidArgumentException::class, 'operator');

test('tool_bound throws on missing threshold', function () {
    new TerminationCondition(
        type: TerminationType::ToolBound,
        toolName: 'run_tests',
        operator: '>=',
        maxIterations: 5,
    );
})->throws(\InvalidArgumentException::class, 'threshold');

test('tool_bound throws on missing max_iterations', function () {
    new TerminationCondition(
        type: TerminationType::ToolBound,
        toolName: 'run_tests',
        operator: '>=',
        threshold: 90.0,
    );
})->throws(\InvalidArgumentException::class, 'max_iterations');

test('tool_bound throws on zero max_iterations', function () {
    new TerminationCondition(
        type: TerminationType::ToolBound,
        toolName: 'run_tests',
        operator: '>=',
        threshold: 90.0,
        maxIterations: 0,
    );
})->throws(\InvalidArgumentException::class, 'max_iterations');

test('tool_bound validates all six operators', function () {
    $validOperators = ['>=', '>', '<=', '<', '==', '!='];

    foreach ($validOperators as $op) {
        $tc = new TerminationCondition(
            type: TerminationType::ToolBound,
            toolName: 'run_tests',
            operator: $op,
            threshold: 50.0,
            maxIterations: 3,
        );

        expect($tc->operator)->toBe($op);
    }
});

test('tool_bound fromArray with all fields', function () {
    $tc = TerminationCondition::fromArray([
        'type' => 'tool_bound',
        'value' => [
            'tool' => 'run_tests',
            'arguments' => ['suite' => 'unit'],
            'operator' => '>=',
            'threshold' => 95,
            'max_iterations' => 10,
        ],
    ]);

    expect($tc->type)->toBe(TerminationType::ToolBound);
    expect($tc->toolName)->toBe('run_tests');
    expect($tc->toolArguments)->toBe(['suite' => 'unit']);
    expect($tc->operator)->toBe('>=');
    expect($tc->threshold)->toBe(95.0);
    expect($tc->maxIterations)->toBe(10);
});

test('tool_bound fromArray without arguments', function () {
    $tc = TerminationCondition::fromArray([
        'type' => 'tool_bound',
        'value' => [
            'tool' => 'check_coverage',
            'operator' => '>',
            'threshold' => 80,
            'max_iterations' => 5,
        ],
    ]);

    expect($tc->toolArguments)->toBeNull();
});

test('tool_bound toArray round-trip', function () {
    $tc = new TerminationCondition(
        type: TerminationType::ToolBound,
        toolName: 'run_tests',
        toolArguments: ['suite' => 'unit'],
        operator: '>=',
        threshold: 95.0,
        maxIterations: 10,
    );

    $arr = $tc->toArray();

    expect($arr['type'])->toBe('tool_bound');
    expect($arr['value']['tool'])->toBe('run_tests');
    expect($arr['value']['arguments'])->toBe(['suite' => 'unit']);
    expect($arr['value']['operator'])->toBe('>=');
    expect($arr['value']['threshold'])->toBe(95.0);
    expect($arr['value']['max_iterations'])->toBe(10);

    $roundTripped = TerminationCondition::fromArray($arr);
    expect($roundTripped->type)->toBe($tc->type);
    expect($roundTripped->toolName)->toBe($tc->toolName);
    expect($roundTripped->toolArguments)->toBe($tc->toolArguments);
    expect($roundTripped->operator)->toBe($tc->operator);
    expect($roundTripped->threshold)->toBe($tc->threshold);
    expect($roundTripped->maxIterations)->toBe($tc->maxIterations);
});

test('tool_bound toArray omits null arguments', function () {
    $tc = new TerminationCondition(
        type: TerminationType::ToolBound,
        toolName: 'check_coverage',
        operator: '>',
        threshold: 80.0,
        maxIterations: 5,
    );

    $arr = $tc->toArray();

    expect($arr['value'])->not->toHaveKey('arguments');
});
