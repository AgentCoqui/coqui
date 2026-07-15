<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\LoopDefinition;
use CoquiBot\Coqui\Contract\LoopRoleDefinition;
use CoquiBot\Coqui\Contract\OnQuestionPolicy;
use CoquiBot\Coqui\Contract\TerminationCondition;
use CoquiBot\Coqui\Contract\TerminationType;

// ──────────────────────────────────────────────
//  fromArray / fromJson round-trip
// ──────────────────────────────────────────────

test('fromArray creates definition with all fields', function () {
    $data = [
        'name' => 'harness',
        'description' => 'Generator-evaluator pattern',
        'roles' => [
            ['role' => 'plan', 'prompt' => 'Analyze the goal.'],
            ['role' => 'coder', 'prompt' => 'Implement the plan.'],
            ['role' => 'reviewer', 'prompt' => 'Review the code.'],
        ],
        'termination_condition' => [
            'type' => 'evaluation_bound',
            'value' => ['criteria' => 'Explicit approval', 'max_review_rounds' => 5],
        ],
    ];

    $def = LoopDefinition::fromArray($data);

    expect($def->name)->toBe('harness');
    expect($def->description)->toBe('Generator-evaluator pattern');
    expect($def->roles)->toHaveCount(3);
    expect($def->roles[0])->toBeInstanceOf(LoopRoleDefinition::class);
    expect($def->roles[0]->role)->toBe('plan');
    expect($def->terminationCondition)->toBeInstanceOf(TerminationCondition::class);
    expect($def->terminationCondition->type)->toBe(TerminationType::EvaluationBound);
});

test('fromJson parses valid JSON string', function () {
    $json = json_encode([
        'name' => 'research',
        'description' => 'Research-driven implementation',
        'roles' => [
            ['role' => 'explorer', 'prompt' => 'Investigate codebase.'],
            ['role' => 'coder', 'prompt' => 'Implement changes.'],
        ],
        'termination_condition' => [
            'type' => 'iteration_bound',
            'value' => 3,
        ],
    ]);

    $def = LoopDefinition::fromJson($json);

    expect($def->name)->toBe('research');
    expect($def->roles)->toHaveCount(2);
    expect($def->terminationCondition->type)->toBe(TerminationType::IterationBound);
    expect($def->terminationCondition->maxIterations)->toBe(3);
});

test('toArray and toJson produce round-trippable output', function () {
    $original = [
        'name' => 'harness',
        'description' => 'Test loop',
        'roles' => [
            ['role' => 'plan', 'prompt' => 'Plan the work.', 'skills' => [], 'max_iterations' => null],
            ['role' => 'coder', 'prompt' => 'Code it.', 'skills' => ['php'], 'max_iterations' => 30],
        ],
        'termination_condition' => [
            'type' => 'evaluation_bound',
            'value' => ['criteria' => 'Must be approved', 'max_review_rounds' => 5],
        ],
    ];

    $def = LoopDefinition::fromArray($original);
    $roundTripped = LoopDefinition::fromArray($def->toArray());

    expect($roundTripped->name)->toBe($def->name);
    expect($roundTripped->description)->toBe($def->description);
    expect($roundTripped->roles)->toHaveCount(count($def->roles));
    expect($roundTripped->terminationCondition->type)->toBe($def->terminationCondition->type);
});

test('fromJson then toJson produces valid JSON', function () {
    $json = json_encode([
        'name' => 'test-loop',
        'description' => 'A test definition',
        'roles' => [['role' => 'coder', 'prompt' => 'Do stuff.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
    ]);

    $def = LoopDefinition::fromJson($json);
    $output = $def->toJson();

    expect(json_decode($output, true))->not->toBeNull();
    expect(json_decode($output, true)['name'])->toBe('test-loop');
});

// ──────────────────────────────────────────────
//  roleNames / stageCount
// ──────────────────────────────────────────────

test('roleNames returns all role names', function () {
    $def = LoopDefinition::fromArray([
        'name' => 'test',
        'description' => 'Test',
        'roles' => [
            ['role' => 'plan', 'prompt' => 'Plan.'],
            ['role' => 'coder', 'prompt' => 'Code.'],
            ['role' => 'reviewer', 'prompt' => 'Review.'],
        ],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
    ]);

    expect($def->roleNames())->toBe(['plan', 'coder', 'reviewer']);
});

test('stageCount returns number of roles', function () {
    $def = LoopDefinition::fromArray([
        'name' => 'test',
        'description' => 'Test',
        'roles' => [
            ['role' => 'coder', 'prompt' => 'Code.'],
            ['role' => 'reviewer', 'prompt' => 'Review.'],
        ],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
    ]);

    expect($def->stageCount())->toBe(2);
});

// ──────────────────────────────────────────────
//  Validation
// ──────────────────────────────────────────────

test('constructor throws on empty name', function () {
    LoopDefinition::fromArray([
        'name' => '',
        'description' => 'Test',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
    ]);
})->throws(\InvalidArgumentException::class, 'slug-safe');

test('constructor throws on invalid slug characters', function () {
    LoopDefinition::fromArray([
        'name' => 'My Loop!',
        'description' => 'Test',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
    ]);
})->throws(\InvalidArgumentException::class, 'slug-safe');

test('constructor throws on uppercase name', function () {
    LoopDefinition::fromArray([
        'name' => 'MyLoop',
        'description' => 'Test',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
    ]);
})->throws(\InvalidArgumentException::class, 'slug-safe');

test('constructor throws on empty description', function () {
    LoopDefinition::fromArray([
        'name' => 'test',
        'description' => '',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
    ]);
})->throws(\InvalidArgumentException::class, 'description');

test('constructor throws on empty roles list', function () {
    LoopDefinition::fromArray([
        'name' => 'test',
        'description' => 'Test',
        'roles' => [],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
    ]);
})->throws(\InvalidArgumentException::class, 'at least one role');

test('fromArray throws when roles entry is not an array', function () {
    LoopDefinition::fromArray([
        'name' => 'test',
        'description' => 'Test',
        'roles' => ['not-an-array'],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
    ]);
})->throws(\InvalidArgumentException::class, 'must be an object');

test('fromArray throws when termination_condition is missing', function () {
    LoopDefinition::fromArray([
        'name' => 'test',
        'description' => 'Test',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
    ]);
})->throws(\InvalidArgumentException::class, 'termination_condition');

test('fromJson throws on invalid JSON', function () {
    LoopDefinition::fromJson('not valid json');
})->throws(\InvalidArgumentException::class);

test('name with hyphens and underscores is valid', function () {
    $def = LoopDefinition::fromArray([
        'name' => 'my-loop_v2',
        'description' => 'Test',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
    ]);

    expect($def->name)->toBe('my-loop_v2');
});

// ──────────────────────────────────────────────
//  Parameters
// ──────────────────────────────────────────────

test('fromArray parses parameters', function () {
    $def = LoopDefinition::fromArray([
        'name' => 'test',
        'description' => 'Test',
        'roles' => [['role' => 'coder', 'prompt' => 'Code {{subject}}.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
        'parameters' => [
            ['name' => 'subject', 'description' => 'Subject to investigate', 'required' => true],
            ['name' => 'format', 'description' => 'Output format', 'required' => false, 'default' => 'markdown'],
        ],
    ]);

    expect($def->parameters)->toHaveCount(2);
    expect($def->parameters[0]->name)->toBe('subject');
    expect($def->parameters[0]->required)->toBeTrue();
    expect($def->parameters[1]->name)->toBe('format');
    expect($def->parameters[1]->default)->toBe('markdown');
});

test('fromArray defaults to empty parameters', function () {
    $def = LoopDefinition::fromArray([
        'name' => 'test',
        'description' => 'Test',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
    ]);

    expect($def->parameters)->toBe([]);
});

test('toArray includes parameters when present', function () {
    $def = LoopDefinition::fromArray([
        'name' => 'test',
        'description' => 'Test',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
        'parameters' => [
            ['name' => 'subject', 'description' => 'Subject', 'required' => true],
        ],
    ]);

    $array = $def->toArray();
    expect($array)->toHaveKey('parameters');
    expect($array['parameters'])->toHaveCount(1);
    expect($array['parameters'][0]['name'])->toBe('subject');
});

test('toArray omits parameters when empty', function () {
    $def = LoopDefinition::fromArray([
        'name' => 'test',
        'description' => 'Test',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
    ]);

    $array = $def->toArray();
    expect($array)->not->toHaveKey('parameters');
});

test('requiredParameterNames returns only required params', function () {
    $def = LoopDefinition::fromArray([
        'name' => 'test',
        'description' => 'Test',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
        'parameters' => [
            ['name' => 'subject', 'description' => 'Required', 'required' => true],
            ['name' => 'format', 'description' => 'Optional', 'required' => false, 'default' => 'md'],
            ['name' => 'language', 'description' => 'Also required', 'required' => true],
        ],
    ]);

    expect($def->requiredParameterNames())->toBe(['subject', 'language']);
});

test('resolveParameters merges provided values with defaults', function () {
    $def = LoopDefinition::fromArray([
        'name' => 'test',
        'description' => 'Test',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
        'parameters' => [
            ['name' => 'subject', 'description' => 'Subject', 'required' => true],
            ['name' => 'format', 'description' => 'Format', 'required' => false, 'default' => 'markdown'],
        ],
    ]);

    $resolved = $def->resolveParameters(['subject' => 'authentication']);

    expect($resolved)->toBe([
        'subject' => 'authentication',
        'format' => 'markdown',
    ]);
});

test('resolveParameters allows overriding defaults', function () {
    $def = LoopDefinition::fromArray([
        'name' => 'test',
        'description' => 'Test',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
        'parameters' => [
            ['name' => 'format', 'description' => 'Format', 'required' => false, 'default' => 'markdown'],
        ],
    ]);

    $resolved = $def->resolveParameters(['format' => 'PDF']);

    expect($resolved)->toBe(['format' => 'PDF']);
});

test('resolveParameters returns empty for no-parameter definitions', function () {
    $def = LoopDefinition::fromArray([
        'name' => 'test',
        'description' => 'Test',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
    ]);

    expect($def->resolveParameters([]))->toBe([]);
});

// ──────────────────────────────────────────────
//  on_question policy
// ──────────────────────────────────────────────

test('on_question defaults to block and round-trips', function () {
    $def = LoopDefinition::fromArray([
        'name' => 'demo',
        'description' => 'demo loop',
        'roles' => [['role' => 'coder', 'prompt' => 'do it']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 1],
    ]);
    expect($def->onQuestion)->toBe(OnQuestionPolicy::Block);
    expect($def->toArray()['on_question'])->toBe('block');

    $withDefault = LoopDefinition::fromArray(
        ['on_question' => 'default'] + $def->toArray(),
    );
    expect($withDefault->onQuestion)->toBe(OnQuestionPolicy::DefaultAnswer);
    expect($withDefault->toArray()['on_question'])->toBe('default');
});

test('fromArray throws when parameters entry is not an array', function () {
    LoopDefinition::fromArray([
        'name' => 'test',
        'description' => 'Test',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
        'parameters' => ['not-an-array'],
    ]);
})->throws(\InvalidArgumentException::class, 'must be an object');
