<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\LoopDefinition;
use CoquiBot\Coqui\Contract\LoopRoleDefinition;
use CoquiBot\Coqui\Contract\TerminationCondition;
use CoquiBot\Coqui\Contract\TerminationType;
use CoquiBot\Coqui\Export\LoopDefinitionProducer;
use CoquiBot\Coqui\Tests\Conformance\Support\ConformanceValidator;

/**
 * CORE-8: a file-based LoopDefinition projects to a schema-valid loop-definition.json
 * whose termination_condition.value shape is discriminated by its type. coqui loop
 * definitions are FILE-based (no loop_definitions table); the required `version`
 * (absent from files) is stamped to 1.
 */

it('CORE-8: an iteration_bound definition produces value:integer matching its type', function () {
    $def = new LoopDefinition(
        name: 'iteration-loop',
        description: 'Fixed-iteration loop.',
        roles: [new LoopRoleDefinition(role: 'coder', prompt: 'Do one increment.')],
        terminationCondition: new TerminationCondition(TerminationType::IterationBound, maxIterations: 5),
    );

    $wire = LoopDefinitionProducer::toWire($def);

    $v = new ConformanceValidator();
    expect($v->isValid('loop-definition.json', $wire))->toBeTrue($v->errorText('loop-definition.json', $wire));

    // version required by the schema but absent from file defs → stamped 1.
    expect($wire['version'])->toBe(1);
    // The value shape matches the type: iteration_bound carries a bare integer.
    expect($wire['termination_condition']['type'])->toBe('iteration_bound');
    expect($wire['termination_condition']['value'])->toBeInt();
    // roles carry a schema-derived `order`; coqui-only skills/max_iterations are dropped.
    expect($wire['roles'][0]['order'])->toBe(0);
    expect($wire['roles'][0])->not->toHaveKey('skills');
    expect($wire['roles'][0])->not->toHaveKey('max_iterations');
})->group('conformance');

it('CORE-8: an evaluation_bound definition produces value:{criteria,max_review_rounds}', function () {
    $def = new LoopDefinition(
        name: 'evaluation-loop',
        description: 'Generator-evaluator loop.',
        roles: [
            new LoopRoleDefinition(role: 'coder', prompt: 'Implement.'),
            new LoopRoleDefinition(role: 'reviewer', prompt: 'Review.', gate: true),
        ],
        terminationCondition: new TerminationCondition(
            TerminationType::EvaluationBound,
            criteria: 'Change meets the goal and quality bar',
            maxReviewRounds: 4,
        ),
    );

    $wire = LoopDefinitionProducer::toWire($def);

    $v = new ConformanceValidator();
    expect($v->isValid('loop-definition.json', $wire))->toBeTrue($v->errorText('loop-definition.json', $wire));

    expect($wire['termination_condition']['type'])->toBe('evaluation_bound');
    expect($wire['termination_condition']['value'])->toHaveKeys(['criteria', 'max_review_rounds']);
    expect($wire['termination_condition']['value']['max_review_rounds'])->toBe(4);
})->group('conformance');

it('CORE-8: a goal_bound definition produces value:{goal_prompt,max_iterations}', function () {
    $def = new LoopDefinition(
        name: 'goal-loop',
        description: 'Goal-judged loop.',
        roles: [new LoopRoleDefinition(role: 'coder', prompt: 'Work toward the goal.')],
        terminationCondition: new TerminationCondition(
            TerminationType::GoalBound,
            maxIterations: 8,
            goalPrompt: 'Has the stated goal been achieved?',
        ),
    );

    $wire = LoopDefinitionProducer::toWire($def);

    $v = new ConformanceValidator();
    expect($v->isValid('loop-definition.json', $wire))->toBeTrue($v->errorText('loop-definition.json', $wire));

    expect($wire['termination_condition']['type'])->toBe('goal_bound');
    expect($wire['termination_condition']['value'])->toHaveKeys(['goal_prompt', 'max_iterations']);
})->group('conformance');

it('CORE-8: a value shape mismatched to its type is rejected by the schema', function () {
    // An evaluation_bound value (object) placed under the iteration_bound type must
    // fail the discriminated oneOf — proving the assertion has teeth.
    $mismatched = [
        'name' => 'broken-loop',
        'version' => 1,
        'description' => 'Type/value mismatch.',
        'roles' => [['role' => 'coder', 'prompt' => 'Work.', 'order' => 0]],
        'termination_condition' => [
            'type' => 'iteration_bound',
            'value' => ['criteria' => 'nope', 'max_review_rounds' => 3],
        ],
    ];

    $v = new ConformanceValidator();
    expect($v->isValid('loop-definition.json', $mismatched))->toBeFalse();
})->group('conformance');

it('CORE-8: a real file-based config/loops definition projects schema-valid', function () {
    // reflection.json is a literal iteration_bound (value:1); its termination value
    // needs no template resolution, so the file projects cleanly.
    $path = __DIR__ . '/../../../config/loops/reflection.json';
    $def = LoopDefinition::fromJson((string) file_get_contents($path));

    $wire = LoopDefinitionProducer::toWire($def);

    $v = new ConformanceValidator();
    expect($v->isValid('loop-definition.json', $wire))->toBeTrue($v->errorText('loop-definition.json', $wire));
    expect($wire['name'])->toBe('reflection');
    expect($wire['version'])->toBe(1);
    expect(count($wire['roles']))->toBeGreaterThan(0);
})->group('conformance');
