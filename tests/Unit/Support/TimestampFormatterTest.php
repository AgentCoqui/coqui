<?php

declare(strict_types=1);

use CoquiBot\Coqui\Support\TimestampFormatter;

test('formats null timestamp as a dash placeholder', function () {
    expect(TimestampFormatter::formatNullable(null))->toBe('—');
});

test('formats empty string timestamp as a dash placeholder', function () {
    expect(TimestampFormatter::formatNullable(''))->toBe('—');
});

test('formats a valid ATOM timestamp', function () {
    expect(TimestampFormatter::formatNullable('2026-07-12T10:00:00+00:00'))
        ->toContain('2026-07-12 10:00');
});

test('returns unparseable strings unchanged', function () {
    expect(TimestampFormatter::formatNullable('not-a-timestamp'))->toBe('not-a-timestamp');
});
