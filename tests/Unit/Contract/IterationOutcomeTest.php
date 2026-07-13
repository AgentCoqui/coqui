<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\IterationOutcome;

test('Blocked case exists with the blocked value', function () {
    expect(IterationOutcome::Blocked->value)->toBe('blocked');
    expect(IterationOutcome::from('blocked'))->toBe(IterationOutcome::Blocked);
});
