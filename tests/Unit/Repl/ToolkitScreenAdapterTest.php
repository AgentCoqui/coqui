<?php

declare(strict_types=1);

namespace Tests\Unit\Repl;

use CoquiBot\Coqui\Contract\ToolkitKeyEvent;
use CoquiBot\Coqui\Contract\ToolkitScreenAction;
use CoquiBot\Coqui\Contract\ToolkitScreenInterface;
use CoquiBot\Coqui\Repl\ToolkitScreenAdapter;
use CoquiBot\Coqui\Tui\KeyEvent;
use CoquiBot\Coqui\Tui\ScreenAction;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

covers(ToolkitScreenAdapter::class);

describe('ToolkitScreenAdapter', function (): void {
    test('delegates render, title, and tick to the toolkit screen', function (): void {
        $screen = new class implements ToolkitScreenInterface {
            public function render(OutputInterface $output, int $width, int $height): void
            {
                $output->writeln("screen {$width}x{$height}");
            }

            public function handleKey(ToolkitKeyEvent $key): ?ToolkitScreenAction
            {
                return null;
            }

            public function tick(): bool
            {
                return true;
            }

            public function title(): string
            {
                return 'Toolkit Screen';
            }
        };

        $adapter = new ToolkitScreenAdapter($screen);
        $output = new BufferedOutput();
        $adapter->render($output, 80, 24);

        expect($output->fetch())->toContain('screen 80x24')
            ->and($adapter->title())->toBe('Toolkit Screen')
            ->and($adapter->tick())->toBeTrue();
    });

    test('maps push actions back into core screen actions', function (): void {
        $child = new class implements ToolkitScreenInterface {
            public function render(OutputInterface $output, int $width, int $height): void {}
            public function handleKey(ToolkitKeyEvent $key): ?ToolkitScreenAction { return null; }
            public function tick(): bool { return false; }
            public function title(): string { return 'Child'; }
        };

        $screen = new class($child) implements ToolkitScreenInterface {
            public function __construct(private ToolkitScreenInterface $child) {}

            public function render(OutputInterface $output, int $width, int $height): void {}

            public function handleKey(ToolkitKeyEvent $key): ?ToolkitScreenAction
            {
                return $key->type === ToolkitKeyEvent::ENTER
                    ? ToolkitScreenAction::push($this->child)
                    : ToolkitScreenAction::refresh();
            }

            public function tick(): bool { return false; }

            public function title(): string { return 'Root'; }
        };

        $adapter = new ToolkitScreenAdapter($screen);
        $action = $adapter->handleKey(KeyEvent::fromBytes("\n"));

        expect($action)->toBeInstanceOf(ScreenAction::class)
            ->and($action?->isPush())->toBeTrue();
    });
});