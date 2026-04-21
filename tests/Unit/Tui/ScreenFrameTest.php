<?php

declare(strict_types=1);

namespace Tests\Unit\Tui;

use CoquiBot\Coqui\Tui\ScreenFrame;
use CoquiBot\Coqui\Tui\ScreenFramePatch;
use CoquiBot\Coqui\Tui\ScreenFrameRenderer;
use Symfony\Component\Console\Output\BufferedOutput;

covers(ScreenFrame::class, ScreenFramePatch::class, ScreenFrameRenderer::class);

describe('ScreenFrame', function (): void {
    test('normalizes rendered output to the viewport height', function (): void {
        $frame = ScreenFrame::fromRenderedOutput("alpha\n\n", 20, 4);

        expect($frame->lines)->toBe([
            'alpha',
            '',
            '',
            '',
        ]);
    });

    test('diffs only changed rows', function (): void {
        $previous = ScreenFrame::fromRenderedOutput("alpha\nbeta\ngamma", 20, 4);
        $next = ScreenFrame::fromRenderedOutput("alpha\nBETA\ngamma", 20, 4);

        $patches = $next->diffAgainst($previous);

        expect($patches)->toHaveCount(1)
            ->and($patches[0]->row)->toBe(1)
            ->and($patches[0]->line)->toBe('BETA');
    });

    test('screen frame renderer writes cursor-targeted line patches', function (): void {
        $output = new BufferedOutput(decorated: true);
        $renderer = new ScreenFrameRenderer($output);
        $previous = ScreenFrame::fromRenderedOutput("alpha\nbeta", 20, 2);
        $next = ScreenFrame::fromRenderedOutput("alpha\nBETA", 20, 2);

        $renderer->renderDiff($previous, $next);

        expect($output->fetch())->toBe("\e[2;1H\e[2KBETA");
    });
});