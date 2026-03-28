<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\CodeReviewResult;

test('builds approved summary', function () {
    $result = new CodeReviewResult(
        finalContent: 'The implementation code',
        approved: true,
        reviewFeedback: 'Code looks good, all tests pass.',
        roundsUsed: 1,
        totalTokens: 500,
        coderIterations: 0,
        reviewerIterations: 5,
    );

    $summary = $result->buildSummary();

    expect($summary)->toContain('✓ APPROVED');
    expect($summary)->toContain('round 1');
    expect($summary)->toContain('Code looks good');
});

test('builds needs changes summary', function () {
    $result = new CodeReviewResult(
        finalContent: 'The implementation code',
        approved: false,
        reviewFeedback: 'Missing error handling in foo()',
        roundsUsed: 2,
        totalTokens: 1500,
        coderIterations: 10,
        reviewerIterations: 8,
    );

    $summary = $result->buildSummary();

    expect($summary)->toContain('✗ NEEDS CHANGES');
    expect($summary)->toContain('round 2');
    expect($summary)->toContain('Missing error handling');
});

test('builds summary with empty feedback', function () {
    $result = new CodeReviewResult(
        finalContent: 'code',
        approved: true,
        reviewFeedback: '',
        roundsUsed: 1,
        totalTokens: 200,
        coderIterations: 0,
        reviewerIterations: 3,
    );

    $summary = $result->buildSummary();

    expect($summary)->toContain('✓ APPROVED');
    // Should not have extra blank lines when feedback is empty
    expect($summary)->not->toContain("\n\n\n");
});

test('serializes to array', function () {
    $result = new CodeReviewResult(
        finalContent: 'content',
        approved: true,
        reviewFeedback: 'feedback',
        roundsUsed: 1,
        totalTokens: 500,
        coderIterations: 5,
        reviewerIterations: 3,
    );

    $array = $result->toArray();

    expect($array)->toMatchArray([
        'approved' => true,
        'review_feedback' => 'feedback',
        'rounds_used' => 1,
        'total_tokens' => 500,
        'coder_iterations' => 5,
        'reviewer_iterations' => 3,
    ]);
});

test('readonly properties are accessible', function () {
    $result = new CodeReviewResult(
        finalContent: 'final output',
        approved: false,
        reviewFeedback: 'needs work',
        roundsUsed: 2,
        totalTokens: 1000,
        coderIterations: 12,
        reviewerIterations: 6,
    );

    expect($result->finalContent)->toBe('final output');
    expect($result->approved)->toBeFalse();
    expect($result->reviewFeedback)->toBe('needs work');
    expect($result->roundsUsed)->toBe(2);
    expect($result->totalTokens)->toBe(1000);
    expect($result->coderIterations)->toBe(12);
    expect($result->reviewerIterations)->toBe(6);
});
