<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\LoopParameterDefinition;

// ──────────────────────────────────────────────
//  Construction & fromArray
// ──────────────────────────────────────────────

test('fromArray creates parameter with all fields', function () {
    $param = LoopParameterDefinition::fromArray([
        'name' => 'topic',
        'description' => 'The subject to investigate',
        'required' => true,
    ]);

    expect($param->name)->toBe('topic');
    expect($param->description)->toBe('The subject to investigate');
    expect($param->required)->toBeTrue();
    expect($param->default)->toBeNull();
});

test('fromArray creates optional parameter with default', function () {
    $param = LoopParameterDefinition::fromArray([
        'name' => 'output_format',
        'description' => 'Type of deliverable',
        'required' => false,
        'default' => 'markdown document',
    ]);

    expect($param->name)->toBe('output_format');
    expect($param->required)->toBeFalse();
    expect($param->default)->toBe('markdown document');
});

test('fromArray defaults to required true', function () {
    $param = LoopParameterDefinition::fromArray([
        'name' => 'topic',
        'description' => 'Test parameter',
    ]);

    expect($param->required)->toBeTrue();
});

// ──────────────────────────────────────────────
//  toArray round-trip
// ──────────────────────────────────────────────

test('toArray serializes required parameter', function () {
    $param = LoopParameterDefinition::fromArray([
        'name' => 'topic',
        'description' => 'The subject',
        'required' => true,
    ]);

    $array = $param->toArray();

    expect($array['name'])->toBe('topic');
    expect($array['description'])->toBe('The subject');
    expect($array['required'])->toBeTrue();
    expect($array)->not->toHaveKey('default');
});

test('toArray serializes optional parameter with default', function () {
    $param = LoopParameterDefinition::fromArray([
        'name' => 'format',
        'description' => 'Output format',
        'required' => false,
        'default' => 'pdf',
    ]);

    $array = $param->toArray();

    expect($array['default'])->toBe('pdf');
    expect($array['required'])->toBeFalse();
});

test('fromArray round-trip preserves all fields', function () {
    $original = [
        'name' => 'language',
        'description' => 'Programming language',
        'required' => false,
        'default' => 'PHP',
    ];

    $param = LoopParameterDefinition::fromArray($original);
    $roundTripped = LoopParameterDefinition::fromArray($param->toArray());

    expect($roundTripped->name)->toBe($param->name);
    expect($roundTripped->description)->toBe($param->description);
    expect($roundTripped->required)->toBe($param->required);
    expect($roundTripped->default)->toBe($param->default);
});

// ──────────────────────────────────────────────
//  Validation
// ──────────────────────────────────────────────

test('constructor throws on empty name', function () {
    LoopParameterDefinition::fromArray([
        'name' => '',
        'description' => 'Test',
    ]);
})->throws(\InvalidArgumentException::class, 'lowercase alphanumeric');

test('constructor throws on invalid name characters', function () {
    LoopParameterDefinition::fromArray([
        'name' => 'My-Topic',
        'description' => 'Test',
    ]);
})->throws(\InvalidArgumentException::class, 'lowercase alphanumeric');

test('constructor throws on name starting with number', function () {
    LoopParameterDefinition::fromArray([
        'name' => '1topic',
        'description' => 'Test',
    ]);
})->throws(\InvalidArgumentException::class, 'lowercase alphanumeric');

test('constructor throws on empty description', function () {
    LoopParameterDefinition::fromArray([
        'name' => 'topic',
        'description' => '',
    ]);
})->throws(\InvalidArgumentException::class, 'non-empty description');

test('constructor throws on optional param without default', function () {
    LoopParameterDefinition::fromArray([
        'name' => 'topic',
        'description' => 'Test',
        'required' => false,
    ]);
})->throws(\InvalidArgumentException::class, 'must have a default value');

test('constructor allows underscores in name', function () {
    $param = LoopParameterDefinition::fromArray([
        'name' => 'output_format',
        'description' => 'Test',
    ]);

    expect($param->name)->toBe('output_format');
});
