<?php

declare(strict_types=1);

use CoquiBot\Coqui\Renderer\ProgressBar;
use CoquiBot\Coqui\Renderer\ProgressBarSegment;

test('renders empty bar when total is zero', function () {
    $bar = new ProgressBar(20);
    $result = $bar->build(0, []);

    expect($result)->toContain(str_repeat('░', 20));
    expect($result)->toContain('0.0%');
});

test('renders fully filled bar with one segment', function () {
    $bar = new ProgressBar(10);
    $segments = [new ProgressBarSegment('Used', 100, 'fg=green')];
    $result = $bar->build(100, $segments);

    expect($result)->toContain(str_repeat('█', 10));
    expect($result)->toContain('100.0%');
});

test('renders proportional segments', function () {
    $bar = new ProgressBar(10);
    $segments = [
        new ProgressBarSegment('A', 50, 'fg=blue'),
        new ProgressBarSegment('B', 25, 'fg=green'),
    ];
    $result = $bar->build(100, $segments);

    // A gets 5 chars (50%), B gets 3 chars (25% rounded), 2 empty
    // Verify total filled + empty = width
    $filled = substr_count($result, '█');
    $empty = substr_count($result, '░');
    expect($filled + $empty)->toBe(10);
    expect($filled)->toBeGreaterThanOrEqual(7); // at least A+B
    expect($empty)->toBeGreaterThanOrEqual(1);  // some available space
});

test('ensures minimum 1 char for tiny segments', function () {
    $bar = new ProgressBar(50);
    $segments = [
        new ProgressBarSegment('Tiny', 1, 'fg=red'),
    ];
    $result = $bar->build(10000, $segments);

    // Even though 1/10000 = ~0 chars, it should render at least 1 filled char
    $filled = substr_count($result, '█');
    expect($filled)->toBeGreaterThanOrEqual(1);
});

test('does not exceed bar width with many segments', function () {
    $bar = new ProgressBar(20);
    $segments = [];
    for ($i = 0; $i < 30; $i++) {
        $segments[] = new ProgressBarSegment("S{$i}", 10, 'fg=white');
    }
    // Total value (300) exceeds total (200), but bar should still clamp to width
    $result = $bar->build(200, $segments);

    $filled = substr_count($result, '█');
    $empty = substr_count($result, '░');
    expect($filled + $empty)->toBe(20);
});

test('skips zero-value segments', function () {
    $bar = new ProgressBar(10);
    $segments = [
        new ProgressBarSegment('Zero', 0, 'fg=red'),
        new ProgressBarSegment('Used', 50, 'fg=green'),
    ];
    $result = $bar->build(100, $segments);

    // Only the non-zero segment should contribute filled chars
    $filled = substr_count($result, '█');
    expect($filled)->toBe(5); // 50% of 10
});

test('percentage label can be disabled', function () {
    $bar = new ProgressBar(10);
    $result = $bar->build(100, [], showPercent: false);

    expect($result)->not->toContain('%');
});

test('custom label is included', function () {
    $bar = new ProgressBar(10);
    $result = $bar->build(100, [], label: 'Context');

    expect($result)->toContain('Context');
});

test('legend includes segment labels', function () {
    $bar = new ProgressBar(10);
    $segments = [
        new ProgressBarSegment('System', 30, 'fg=blue'),
        new ProgressBarSegment('User', 20, 'fg=green'),
    ];
    $legend = $bar->buildLegend($segments);

    expect($legend)->toContain('System');
    expect($legend)->toContain('User');
    expect($legend)->toContain('Available');
});

test('legend excludes zero-value segments', function () {
    $bar = new ProgressBar(10);
    $segments = [
        new ProgressBarSegment('Used', 50, 'fg=green'),
        new ProgressBarSegment('Empty', 0, 'fg=red'),
    ];
    $legend = $bar->buildLegend($segments);

    expect($legend)->toContain('Used');
    expect($legend)->not->toContain('Empty');
    expect($legend)->toContain('Available');
});

test('width is clamped to valid range', function () {
    // Min width
    $bar = new ProgressBar(3);
    $result = $bar->build(100, []);
    $empty = substr_count($result, '░');
    expect($empty)->toBe(10); // Clamped to MIN_WIDTH=10

    // Max width
    $bar = new ProgressBar(200);
    $result = $bar->build(100, []);
    $empty = substr_count($result, '░');
    expect($empty)->toBe(120); // Clamped to MAX_WIDTH=120
});

test('negative total renders empty bar', function () {
    $bar = new ProgressBar(10);
    $result = $bar->build(-5, [new ProgressBarSegment('X', 10, 'fg=red')]);

    $empty = substr_count($result, '░');
    expect($empty)->toBe(10);
    expect($result)->toContain('0.0%');
});
