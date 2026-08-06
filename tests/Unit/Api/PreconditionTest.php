<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Precondition;
use React\Http\Message\ServerRequest;

test('If-None-Match: * signals a create precondition', function () {
    $precondition = Precondition::fromRequest(
        new ServerRequest('PUT', '/api/v1/roles/analyst', ['If-None-Match' => '*']),
    );

    expect($precondition->isCreate)->toBeTrue();
    expect($precondition->expectedVersion)->toBeNull();
    expect($precondition->isUnconditional)->toBeFalse();
});

test('If-Match: 3 parses the expected version', function () {
    $precondition = Precondition::fromRequest(
        new ServerRequest('PATCH', '/api/v1/personas/caelum', ['If-Match' => '3']),
    );

    expect($precondition->expectedVersion)->toBe(3);
    expect($precondition->isCreate)->toBeFalse();
    expect($precondition->isUnconditional)->toBeFalse();
});

test('a quoted, weak If-Match ETag still parses to an integer version', function () {
    $precondition = Precondition::fromRequest(
        new ServerRequest('PATCH', '/api/v1/personas/caelum', ['If-Match' => 'W/"7"']),
    );

    expect($precondition->expectedVersion)->toBe(7);
});

test('neither precondition header present is unconditional', function () {
    $precondition = Precondition::fromRequest(
        new ServerRequest('PATCH', '/api/v1/personas/caelum'),
    );

    expect($precondition->isUnconditional)->toBeTrue();
    expect($precondition->isCreate)->toBeFalse();
    expect($precondition->expectedVersion)->toBeNull();
});

test('a non-numeric If-Match yields a null expected version', function () {
    $precondition = Precondition::fromRequest(
        new ServerRequest('PATCH', '/api/v1/personas/caelum', ['If-Match' => '*']),
    );

    expect($precondition->expectedVersion)->toBeNull();
    expect($precondition->isUnconditional)->toBeFalse();
});
