<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Sse\SseCursor;

test('encoded cursors sort lexicographically in numeric order', function (): void {
    // The whole point of the string cursor: string comparison must agree with
    // numeric comparison, so a bare decimal counter ("9" > "10") is unacceptable.
    expect(SseCursor::encode(9) < SseCursor::encode(10))->toBeTrue();
    expect(SseCursor::encode(100) < SseCursor::encode(1000))->toBeTrue();
    expect(SseCursor::encode(0) < SseCursor::encode(1))->toBeTrue();
});

test('encode is reversed by decode', function (): void {
    foreach ([0, 1, 9, 10, 4242, 999999, PHP_INT_MAX] as $n) {
        expect(SseCursor::decode(SseCursor::encode($n)))->toBe($n);
    }
});

test('encode output is fixed width digits only', function (): void {
    $small = SseCursor::encode(1);
    $large = SseCursor::encode(123456789);

    expect($small)->toMatch('/^[0-9]+$/');
    expect($large)->toMatch('/^[0-9]+$/');
    expect(strlen($small))->toBe(strlen($large));
});
