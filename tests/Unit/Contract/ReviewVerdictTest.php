<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\ReviewVerdict;

test('parses APPROVED verdict', function () {
    $output = "The code looks great.\n\nVERDICT: APPROVED";
    expect(ReviewVerdict::fromReviewerOutput($output))->toBe(ReviewVerdict::Approved);
});

test('parses NEEDS_CHANGES verdict', function () {
    $output = "Several issues found:\n1. Missing validation\n\nVERDICT: NEEDS_CHANGES";
    expect(ReviewVerdict::fromReviewerOutput($output))->toBe(ReviewVerdict::NeedsChanges);
});

test('case insensitive verdict parsing', function () {
    expect(ReviewVerdict::fromReviewerOutput('verdict: approved'))->toBe(ReviewVerdict::Approved);
    expect(ReviewVerdict::fromReviewerOutput('Verdict: Needs_Changes'))->toBe(ReviewVerdict::NeedsChanges);
    expect(ReviewVerdict::fromReviewerOutput('VERDICT: APPROVED'))->toBe(ReviewVerdict::Approved);
});

test('defaults to NeedsChanges when no verdict marker', function () {
    $output = "The code has some issues but I forgot the verdict marker.";
    expect(ReviewVerdict::fromReviewerOutput($output))->toBe(ReviewVerdict::NeedsChanges);
});

test('defaults to NeedsChanges on empty string', function () {
    expect(ReviewVerdict::fromReviewerOutput(''))->toBe(ReviewVerdict::NeedsChanges);
});

test('picks last verdict when multiple markers exist', function () {
    $output = "VERDICT: NEEDS_CHANGES\n\nAfter further review...\n\nVERDICT: APPROVED";
    expect(ReviewVerdict::fromReviewerOutput($output))->toBe(ReviewVerdict::Approved);
});

test('strips markdown formatting around verdict', function () {
    $output = "Review complete.\n\n**VERDICT: APPROVED**";
    expect(ReviewVerdict::fromReviewerOutput($output))->toBe(ReviewVerdict::Approved);
});

test('strips backtick formatting around verdict', function () {
    $output = "Review complete.\n\n`VERDICT: NEEDS_CHANGES`";
    expect(ReviewVerdict::fromReviewerOutput($output))->toBe(ReviewVerdict::NeedsChanges);
});

test('handles extra whitespace in verdict', function () {
    $output = "VERDICT :  APPROVED";
    expect(ReviewVerdict::fromReviewerOutput($output))->toBe(ReviewVerdict::Approved);
});

test('isApproved returns true only for Approved', function () {
    expect(ReviewVerdict::Approved->isApproved())->toBeTrue();
    expect(ReviewVerdict::NeedsChanges->isApproved())->toBeFalse();
    expect(ReviewVerdict::Error->isApproved())->toBeFalse();
});

test('enum cases map to expected string values', function () {
    expect(ReviewVerdict::Approved->value)->toBe('approved');
    expect(ReviewVerdict::NeedsChanges->value)->toBe('needs_changes');
    expect(ReviewVerdict::Error->value)->toBe('error');
});
