<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\MountDefinition;

test('creates valid mount from constructor', function () {
    $tmpDir = sys_get_temp_dir();

    $mount = new MountDefinition(
        path: $tmpDir,
        alias: 'datasets',
        access: 'ro',
        description: 'Training data',
    );

    expect($mount->path)->toBe($tmpDir);
    expect($mount->alias)->toBe('datasets');
    expect($mount->access)->toBe('ro');
    expect($mount->description)->toBe('Training data');
});

test('defaults to read-only access', function () {
    $mount = new MountDefinition(
        path: sys_get_temp_dir(),
        alias: 'data',
    );

    expect($mount->access)->toBe('ro');
    expect($mount->isReadOnly())->toBeTrue();
});

test('read-write access is not read-only', function () {
    $mount = new MountDefinition(
        path: sys_get_temp_dir(),
        alias: 'workspace',
        access: 'rw',
    );

    expect($mount->isReadOnly())->toBeFalse();
});

test('throws on empty alias', function () {
    new MountDefinition(
        path: sys_get_temp_dir(),
        alias: '',
    );
})->throws(\InvalidArgumentException::class, 'non-empty string without path separators');

test('throws on alias with forward slash', function () {
    new MountDefinition(
        path: sys_get_temp_dir(),
        alias: 'bad/alias',
    );
})->throws(\InvalidArgumentException::class, 'non-empty string without path separators');

test('throws on alias with backslash', function () {
    new MountDefinition(
        path: sys_get_temp_dir(),
        alias: 'bad\\alias',
    );
})->throws(\InvalidArgumentException::class, 'non-empty string without path separators');

test('throws on invalid access level', function () {
    new MountDefinition(
        path: sys_get_temp_dir(),
        alias: 'data',
        access: 'readwrite',
    );
})->throws(\InvalidArgumentException::class, 'must be "ro" or "rw"');

test('throws when path does not exist', function () {
    new MountDefinition(
        path: '/nonexistent/path/' . bin2hex(random_bytes(8)),
        alias: 'ghost',
    );
})->throws(\InvalidArgumentException::class, 'does not exist or is not a directory');

test('fromArray creates mount with all fields', function () {
    $mount = MountDefinition::fromArray([
        'path' => sys_get_temp_dir(),
        'alias' => 'mydata',
        'access' => 'rw',
        'description' => 'My data directory',
    ]);

    expect($mount->path)->toBe(sys_get_temp_dir());
    expect($mount->alias)->toBe('mydata');
    expect($mount->access)->toBe('rw');
    expect($mount->description)->toBe('My data directory');
});

test('fromArray defaults access to ro and description to null', function () {
    $mount = MountDefinition::fromArray([
        'path' => sys_get_temp_dir(),
        'alias' => 'minimal',
    ]);

    expect($mount->access)->toBe('ro');
    expect($mount->description)->toBeNull();
});

test('description is nullable', function () {
    $mount = new MountDefinition(
        path: sys_get_temp_dir(),
        alias: 'nodesc',
    );

    expect($mount->description)->toBeNull();
});
