<?php

declare(strict_types=1);

namespace Tests\Unit\Repl;

describe('REPL slim — fullscreen bridge removed', function (): void {
    test('toolkit fullscreen bridge classes no longer exist', function (): void {
        expect(class_exists(\CoquiBot\Coqui\Repl\ToolkitScreenHost::class))->toBeFalse();
        expect(class_exists(\CoquiBot\Coqui\Repl\ToolkitScreenAdapter::class))->toBeFalse();
    });

    test('toolkit screen contracts no longer exist', function (): void {
        expect(interface_exists(\CoquiBot\Coqui\Contract\ToolkitScreenHostInterface::class))->toBeFalse();
        expect(interface_exists(\CoquiBot\Coqui\Contract\ToolkitScreenInterface::class))->toBeFalse();
        expect(class_exists(\CoquiBot\Coqui\Contract\ToolkitScreenAction::class))->toBeFalse();
        expect(class_exists(\CoquiBot\Coqui\Contract\ToolkitKeyEvent::class))->toBeFalse();
    });

    test('the fullscreen Tui framework no longer exists', function (): void {
        expect(class_exists(\CoquiBot\Coqui\Tui\ScreenRunner::class))->toBeFalse();
        expect(class_exists(\CoquiBot\Coqui\Tui\ScreenShell::class))->toBeFalse();
        expect(class_exists(\CoquiBot\Coqui\Tui\LoopDashboardScreen::class))->toBeFalse();
        expect(class_exists(\CoquiBot\Coqui\Tui\LoopDetailScreen::class))->toBeFalse();
        expect(is_dir(dirname(__DIR__, 3) . '/src/Tui'))->toBeFalse();
    });
});
