<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\CredentialRequirement;

test('default optional is false', function () {
    $requirement = new CredentialRequirement(
        name: 'MY_API_KEY',
        description: 'API key for testing',
    );

    expect($requirement->name)->toBe('MY_API_KEY');
    expect($requirement->description)->toBe('API key for testing');
    expect($requirement->optional)->toBeFalse();
});

test('optional can be set to true', function () {
    $requirement = new CredentialRequirement(
        name: 'OPTIONAL_KEY',
        description: 'Optional credential',
        optional: true,
    );

    expect($requirement->optional)->toBeTrue();
});

test('optional can be explicitly set to false', function () {
    $requirement = new CredentialRequirement(
        name: 'REQUIRED_KEY',
        description: 'Required credential',
        optional: false,
    );

    expect($requirement->optional)->toBeFalse();
});
