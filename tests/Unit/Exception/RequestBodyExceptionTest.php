<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Exception\RequestBodyException;

test('toThrownError carries the message and catalog code', function () {
    $thrown = (new RequestBodyException(ApiErrorCode::NOT_FOUND, 'No such persona', ['id' => 'p_missing']))
        ->toThrownError();

    expect($thrown['error'])->toBe('No such persona');
    expect($thrown['code'])->toBe('not_found');
});

test('toThrownError renders present details as a stdClass object', function () {
    $thrown = (new RequestBodyException(ApiErrorCode::VALIDATION_ERROR, 'bad', ['field' => 'name']))
        ->toThrownError();

    expect($thrown)->toHaveKey('details');
    expect($thrown['details'])->toBeInstanceOf(stdClass::class);
    expect($thrown['details']->field)->toBe('name');
});

test('toThrownError omits details when empty', function () {
    $thrown = (new RequestBodyException(ApiErrorCode::NOT_FOUND, 'gone', []))
        ->toThrownError();

    expect($thrown)->not->toHaveKey('details');
});
