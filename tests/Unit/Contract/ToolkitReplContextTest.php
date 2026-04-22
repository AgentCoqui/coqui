<?php

declare(strict_types=1);

namespace Tests\Unit\Contract;

use CoquiBot\Coqui\Contract\ToolkitKeyEvent;
use CoquiBot\Coqui\Contract\ToolkitReplContext;
use CoquiBot\Coqui\Contract\ToolkitScreenAction;
use CoquiBot\Coqui\Contract\ToolkitScreenHostInterface;
use CoquiBot\Coqui\Contract\ToolkitScreenInterface;
use CoquiBot\Coqui\Repl\InterruptiblePrompt;
use CoquiBot\Coqui\Support\ToolkitDatabaseFactory;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

covers(ToolkitReplContext::class);

describe('ToolkitReplContext', function (): void {
    test('delegates interactive screen hosting to the configured host', function (): void {
        $screen = new class implements ToolkitScreenInterface {
            public function render(\Symfony\Component\Console\Output\OutputInterface $output, int $width, int $height): void {}
            public function handleKey(ToolkitKeyEvent $key): ?ToolkitScreenAction { return null; }
            public function tick(): bool { return false; }
            public function title(): string { return 'Example'; }
        };

        $captured = null;
        $host = new class($captured) implements ToolkitScreenHostInterface {
            public ?ToolkitScreenInterface $capturedScreen = null;

            public function __construct(?ToolkitScreenInterface &$capturedScreen)
            {
                $this->capturedScreen = &$capturedScreen;
            }

            public function isInteractiveTerminal(): bool
            {
                return true;
            }

            public function runScreen(ToolkitScreenInterface $screen): void
            {
                $this->capturedScreen = $screen;
            }
        };

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);
        $context = new ToolkitReplContext(
            io: $io,
            prompt: new InterruptiblePrompt($io),
            workspacePath: sys_get_temp_dir(),
            activeProfile: null,
            sessionId: 'session-1',
            output: $output,
            databaseFactory: new ToolkitDatabaseFactory(sys_get_temp_dir()),
            screenHost: $host,
        );

        expect($context->isInteractiveTerminal())->toBeTrue();

        $context->runScreen($screen);

        expect($captured)->toBe($screen);
    });

    test('gracefully reports non-interactive mode without a screen host', function (): void {
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);
        $context = new ToolkitReplContext(
            io: $io,
            prompt: new InterruptiblePrompt($io),
            workspacePath: sys_get_temp_dir(),
            activeProfile: null,
            sessionId: 'session-1',
            output: $output,
            databaseFactory: new ToolkitDatabaseFactory(sys_get_temp_dir()),
        );

        expect($context->isInteractiveTerminal())->toBeFalse();
    });
});