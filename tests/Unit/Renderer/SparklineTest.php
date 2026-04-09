<?php

declare(strict_types=1);

namespace Tests\Unit\Renderer;

use CoquiBot\Coqui\Renderer\Sparkline;

covers(Sparkline::class);

describe('Sparkline', function (): void {
    test('empty array returns empty string', function (): void {
        expect(Sparkline::render([]))->toBe('');
    });

    test('single value returns mid-level block', function (): void {
        $result = Sparkline::render([42]);
        expect($result)->toBe('<fg=cyan>▅</>');
    });

    test('ascending values produce ascending blocks', function (): void {
        $result = Sparkline::render([1, 2, 3, 4, 5, 6, 7, 8]);
        // Should contain blocks from low to high
        expect($result)->toStartWith('<fg=cyan>');
        expect($result)->toEndWith('</>');
        // Strip tags and verify ascending
        $chars = preg_replace('/<[^>]+>/', '', $result);
        $blocks = mb_str_split($chars);
        for ($i = 1; $i < count($blocks); $i++) {
            expect(mb_ord($blocks[$i]))->toBeGreaterThanOrEqual(mb_ord($blocks[$i - 1]));
        }
    });

    test('descending values produce descending blocks', function (): void {
        $result = Sparkline::render([8, 7, 6, 5, 4, 3, 2, 1]);
        $chars = preg_replace('/<[^>]+>/', '', $result);
        $blocks = mb_str_split($chars);
        for ($i = 1; $i < count($blocks); $i++) {
            expect(mb_ord($blocks[$i]))->toBeLessThanOrEqual(mb_ord($blocks[$i - 1]));
        }
    });

    test('all equal values produce uniform mid-level blocks', function (): void {
        $result = Sparkline::render([5, 5, 5, 5]);
        $chars = preg_replace('/<[^>]+>/', '', $result);
        $blocks = mb_str_split($chars);
        expect(count(array_unique($blocks)))->toBe(1);
        expect($blocks[0])->toBe('▅');
    });

    test('respects max width by taking most recent values', function (): void {
        $values = range(1, 20);
        $result = Sparkline::render($values, 'fg=cyan', 5);
        $chars = preg_replace('/<[^>]+>/', '', $result);
        expect(mb_strlen($chars))->toBe(5);
    });

    test('uses custom style', function (): void {
        $result = Sparkline::render([1, 2, 3], 'fg=green');
        expect($result)->toStartWith('<fg=green>');
        expect($result)->toEndWith('</>');
    });

    test('handles float values', function (): void {
        $result = Sparkline::render([0.1, 0.5, 0.9]);
        expect($result)->not->toBe('');
    });

    test('min and max get lowest and highest blocks', function (): void {
        $result = Sparkline::render([0, 100]);
        $chars = preg_replace('/<[^>]+>/', '', $result);
        $blocks = mb_str_split($chars);
        expect($blocks[0])->toBe('▁');
        expect($blocks[1])->toBe('█');
    });
});
