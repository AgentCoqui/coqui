<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\ProcessCancellationToken;

test('onCancel listeners run when token is cancelled', function () {
    $token = new ProcessCancellationToken();
    $calls = 0;

    $token->onCancel(static function () use (&$calls): void {
        $calls++;
    });

    $token->cancel();

    expect($token->isCancelled())->toBeTrue()
        ->and($calls)->toBe(1);
});

test('onCancel listener runs immediately when token already cancelled', function () {
    $token = new ProcessCancellationToken();
    $token->cancel();
    $calls = 0;

    $token->onCancel(static function () use (&$calls): void {
        $calls++;
    });

    expect($calls)->toBe(1);
});

test('cancel is idempotent', function () {
    $token = new ProcessCancellationToken();
    $calls = 0;

    $token->onCancel(static function () use (&$calls): void {
        $calls++;
    });

    $token->cancel();
    $token->cancel();

    expect($calls)->toBe(1);
});