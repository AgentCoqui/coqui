<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Tool\Parameter\ArrayParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\ObjectParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Coqui\Mcp\Schema\SchemaConverter;

// -- Basic Types --

test('converts string property to StringParameter', function () {
    $converter = new SchemaConverter();
    $params = $converter->convert([
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string', 'description' => 'The name'],
        ],
        'required' => ['name'],
    ]);

    expect($params)->toHaveCount(1)
        ->and($params[0])->toBeInstanceOf(StringParameter::class)
        ->and($params[0]->name)->toBe('name')
        ->and($params[0]->description)->toBe('The name')
        ->and($params[0]->required)->toBeTrue();
});

test('converts optional string property', function () {
    $converter = new SchemaConverter();
    $params = $converter->convert([
        'type' => 'object',
        'properties' => [
            'label' => ['type' => 'string', 'description' => 'Optional label'],
        ],
    ]);

    expect($params[0]->required)->toBeFalse();
});

test('converts number property to NumberParameter', function () {
    $converter = new SchemaConverter();
    $params = $converter->convert([
        'type' => 'object',
        'properties' => [
            'count' => ['type' => 'number', 'description' => 'A count', 'minimum' => 0, 'maximum' => 100],
        ],
        'required' => ['count'],
    ]);

    expect($params[0])->toBeInstanceOf(NumberParameter::class)
        ->and($params[0]->name)->toBe('count')
        ->and($params[0]->integer)->toBeFalse()
        ->and($params[0]->minimum)->toBe(0.0)
        ->and($params[0]->maximum)->toBe(100.0);
});

test('converts integer property to NumberParameter with integer flag', function () {
    $converter = new SchemaConverter();
    $params = $converter->convert([
        'type' => 'object',
        'properties' => [
            'page' => ['type' => 'integer', 'description' => 'Page number'],
        ],
    ]);

    expect($params[0])->toBeInstanceOf(NumberParameter::class)
        ->and($params[0]->integer)->toBeTrue();
});

test('converts boolean property to BoolParameter', function () {
    $converter = new SchemaConverter();
    $params = $converter->convert([
        'type' => 'object',
        'properties' => [
            'verbose' => ['type' => 'boolean', 'description' => 'Enable verbose output'],
        ],
    ]);

    expect($params[0])->toBeInstanceOf(BoolParameter::class)
        ->and($params[0]->name)->toBe('verbose');
});

// -- Enum --

test('converts string with enum to EnumParameter', function () {
    $converter = new SchemaConverter();
    $params = $converter->convert([
        'type' => 'object',
        'properties' => [
            'color' => [
                'type' => 'string',
                'description' => 'A color',
                'enum' => ['red', 'green', 'blue'],
            ],
        ],
    ]);

    expect($params[0])->toBeInstanceOf(EnumParameter::class)
        ->and($params[0]->values)->toBe(['red', 'green', 'blue']);
});

test('converts top-level enum without type to EnumParameter', function () {
    $converter = new SchemaConverter();
    $params = $converter->convert([
        'type' => 'object',
        'properties' => [
            'status' => [
                'description' => 'Status',
                'enum' => ['active', 'inactive'],
            ],
        ],
    ]);

    expect($params[0])->toBeInstanceOf(EnumParameter::class);
});

// -- Complex Types --

test('converts array property to ArrayParameter', function () {
    $converter = new SchemaConverter();
    $params = $converter->convert([
        'type' => 'object',
        'properties' => [
            'tags' => [
                'type' => 'array',
                'description' => 'List of tags',
                'items' => ['type' => 'string'],
            ],
        ],
    ]);

    expect($params[0])->toBeInstanceOf(ArrayParameter::class)
        ->and($params[0]->items)->toBeInstanceOf(StringParameter::class);
});

test('converts array without items schema', function () {
    $converter = new SchemaConverter();
    $params = $converter->convert([
        'type' => 'object',
        'properties' => [
            'data' => ['type' => 'array', 'description' => 'Raw data'],
        ],
    ]);

    expect($params[0])->toBeInstanceOf(ArrayParameter::class)
        ->and($params[0]->items)->toBeNull();
});

test('converts object property to ObjectParameter with nested properties', function () {
    $converter = new SchemaConverter();
    $params = $converter->convert([
        'type' => 'object',
        'properties' => [
            'metadata' => [
                'type' => 'object',
                'description' => 'Metadata object',
                'properties' => [
                    'author' => ['type' => 'string', 'description' => 'Author name'],
                    'year' => ['type' => 'integer', 'description' => 'Publication year'],
                ],
                'required' => ['author'],
            ],
        ],
    ]);

    expect($params[0])->toBeInstanceOf(ObjectParameter::class)
        ->and($params[0]->properties)->toHaveCount(2)
        ->and($params[0]->properties[0])->toBeInstanceOf(StringParameter::class)
        ->and($params[0]->properties[0]->required)->toBeTrue()
        ->and($params[0]->properties[1])->toBeInstanceOf(NumberParameter::class)
        ->and($params[0]->properties[1]->required)->toBeFalse();
});

// -- Edge Cases --

test('unknown type falls back to StringParameter with hint', function () {
    $converter = new SchemaConverter();
    $params = $converter->convert([
        'type' => 'object',
        'properties' => [
            'complex' => ['type' => 'null', 'description' => 'A null type'],
        ],
    ]);

    expect($params[0])->toBeInstanceOf(StringParameter::class)
        ->and($params[0]->description)->toContain('JSON string');
});

test('empty properties returns empty array', function () {
    $converter = new SchemaConverter();

    expect($converter->convert(['type' => 'object', 'properties' => []]))->toBe([])
        ->and($converter->convert([]))->toBe([]);
});

test('multiple properties with mixed required', function () {
    $converter = new SchemaConverter();
    $params = $converter->convert([
        'type' => 'object',
        'properties' => [
            'owner' => ['type' => 'string', 'description' => 'Repo owner'],
            'repo' => ['type' => 'string', 'description' => 'Repo name'],
            'title' => ['type' => 'string', 'description' => 'Issue title'],
            'body' => ['type' => 'string', 'description' => 'Issue body'],
        ],
        'required' => ['owner', 'repo', 'title'],
    ]);

    expect($params)->toHaveCount(4)
        ->and($params[0]->required)->toBeTrue()    // owner
        ->and($params[1]->required)->toBeTrue()    // repo
        ->and($params[2]->required)->toBeTrue()    // title
        ->and($params[3]->required)->toBeFalse();  // body
});

test('string with pattern and maxLength', function () {
    $converter = new SchemaConverter();
    $params = $converter->convert([
        'type' => 'object',
        'properties' => [
            'code' => ['type' => 'string', 'description' => 'Code', 'pattern' => '^[A-Z]{3}$', 'maxLength' => 3],
        ],
    ]);

    expect($params[0])->toBeInstanceOf(StringParameter::class)
        ->and($params[0]->pattern)->toBe('^[A-Z]{3}$')
        ->and($params[0]->maxLength)->toBe(3);
});
