<?php

declare(strict_types=1);

namespace Tests\Unit\Tui;

use CoquiBot\Coqui\Tui\ScreenShell;
use CoquiBot\Coqui\Tui\ShellRegion;

covers(ScreenShell::class, ShellRegion::class);

describe('ScreenShell', function (): void {
    test('renders optional regions when space permits', function (): void {
        $shell = new ScreenShell(
            contentLines: ['alpha', 'beta'],
            header: new ShellRegion(['HEADER'], collapsePriority: 10),
            footer: new ShellRegion(['FOOTER'], collapsePriority: 10),
            sidebar: new ShellRegion(['SIDE', 'INFO'], collapsePriority: 50, preferredWidth: 6),
            contentMinWidth: 8,
            contentMinHeight: 2,
        );

        $lines = $shell->render(20, 4);

        expect($lines[0])->toContain('HEADER')
            ->and($lines[1])->toContain('alpha')
            ->and($lines[1])->toContain('SIDE')
            ->and($lines[2])->toContain('beta')
            ->and($lines[3])->toContain('FOOTER');
    });

    test('drops sidebar when width is too tight', function (): void {
        $shell = new ScreenShell(
            contentLines: ['alpha', 'beta'],
            sidebar: new ShellRegion(['SIDE'], collapsePriority: 100, preferredWidth: 4),
            contentMinWidth: 10,
            contentMinHeight: 2,
        );

        $lines = $shell->render(12, 3);

        expect(implode("\n", $lines))->not->toContain('SIDE')
            ->and($lines[0])->toContain('alpha');
    });

    test('drops the highest priority chrome first when height is constrained', function (): void {
        $shell = new ScreenShell(
            contentLines: ['alpha', 'beta'],
            header: new ShellRegion(['HEADER'], collapsePriority: 10),
            footer: new ShellRegion(['FOOTER'], collapsePriority: 100),
            contentMinWidth: 8,
            contentMinHeight: 2,
        );

        $lines = $shell->render(16, 3);

        expect(implode("\n", $lines))->toContain('HEADER')
            ->and(implode("\n", $lines))->not->toContain('FOOTER');
    });
});