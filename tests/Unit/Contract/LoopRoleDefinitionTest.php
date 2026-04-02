<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\LoopRoleDefinition;

// ──────────────────────────────────────────────
//  Construction
// ──────────────────────────────────────────────

test('constructor creates role with all fields', function () {
    $role = new LoopRoleDefinition(
        role: 'coder',
        prompt: 'Implement the plan.',
        skills: ['php', 'testing'],
        maxIterations: 30,
    );

    expect($role->role)->toBe('coder');
    expect($role->prompt)->toBe('Implement the plan.');
    expect($role->skills)->toBe(['php', 'testing']);
    expect($role->maxIterations)->toBe(30);
});

test('constructor defaults skills to empty array and maxIterations to null', function () {
    $role = new LoopRoleDefinition(
        role: 'reviewer',
        prompt: 'Review the code.',
    );

    expect($role->skills)->toBe([]);
    expect($role->maxIterations)->toBeNull();
});

// ──────────────────────────────────────────────
//  fromArray
// ──────────────────────────────────────────────

test('fromArray with role key', function () {
    $role = LoopRoleDefinition::fromArray([
        'role' => 'plan',
        'prompt' => 'Create a plan.',
    ]);

    expect($role->role)->toBe('plan');
    expect($role->prompt)->toBe('Create a plan.');
});

test('fromArray with name key falls back when role is missing', function () {
    $role = LoopRoleDefinition::fromArray([
        'name' => 'explorer',
        'prompt' => 'Explore the codebase.',
    ]);

    expect($role->role)->toBe('explorer');
});

test('fromArray prefers role over name when both present', function () {
    $role = LoopRoleDefinition::fromArray([
        'role' => 'coder',
        'name' => 'explorer',
        'prompt' => 'Do work.',
    ]);

    expect($role->role)->toBe('coder');
});

test('fromArray with skills and max_iterations', function () {
    $role = LoopRoleDefinition::fromArray([
        'role' => 'reviewer',
        'prompt' => 'Review code.',
        'skills' => ['code-review', 'security'],
        'max_iterations' => 15,
    ]);

    expect($role->skills)->toBe(['code-review', 'security']);
    expect($role->maxIterations)->toBe(15);
});

test('fromArray without optional fields uses defaults', function () {
    $role = LoopRoleDefinition::fromArray([
        'role' => 'coder',
        'prompt' => 'Code something.',
    ]);

    expect($role->skills)->toBe([]);
    expect($role->maxIterations)->toBeNull();
});

// ──────────────────────────────────────────────
//  toArray
// ──────────────────────────────────────────────

test('toArray serializes all fields', function () {
    $role = new LoopRoleDefinition(
        role: 'plan',
        prompt: 'Build a plan.',
        skills: ['analysis'],
        maxIterations: 20,
    );

    $arr = $role->toArray();

    expect($arr['role'])->toBe('plan');
    expect($arr['prompt'])->toBe('Build a plan.');
    expect($arr['skills'])->toBe(['analysis']);
    expect($arr['max_iterations'])->toBe(20);
});

test('toArray round-trip through fromArray', function () {
    $original = new LoopRoleDefinition(
        role: 'coder',
        prompt: 'Implement features.',
        skills: ['php'],
        maxIterations: 30,
    );

    $roundTripped = LoopRoleDefinition::fromArray($original->toArray());

    expect($roundTripped->role)->toBe($original->role);
    expect($roundTripped->prompt)->toBe($original->prompt);
    expect($roundTripped->skills)->toBe($original->skills);
    expect($roundTripped->maxIterations)->toBe($original->maxIterations);
});

// ──────────────────────────────────────────────
//  Validation
// ──────────────────────────────────────────────

test('constructor throws on empty role', function () {
    new LoopRoleDefinition(role: '', prompt: 'Do work.');
})->throws(\InvalidArgumentException::class, 'must not be empty');

test('constructor throws on empty prompt', function () {
    new LoopRoleDefinition(role: 'coder', prompt: '');
})->throws(\InvalidArgumentException::class, 'non-empty "prompt"');

test('fromArray throws on missing role and name', function () {
    LoopRoleDefinition::fromArray(['prompt' => 'Do stuff.']);
})->throws(\InvalidArgumentException::class, 'must not be empty');

test('fromArray throws on missing prompt', function () {
    LoopRoleDefinition::fromArray(['role' => 'coder']);
})->throws(\InvalidArgumentException::class, 'non-empty "prompt"');
