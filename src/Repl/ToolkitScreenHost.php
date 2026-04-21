<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl;

use CoquiBot\Coqui\Contract\ToolkitScreenHostInterface;
use CoquiBot\Coqui\Contract\ToolkitScreenInterface;
use CoquiBot\Coqui\Tui\ScreenRunner;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Toolkit-facing bridge into the internal fullscreen TUI runtime.
 */
final readonly class ToolkitScreenHost implements ToolkitScreenHostInterface
{
    public function __construct(
        private OutputInterface $output,
        private ?TerminalStateManager $terminalState = null,
    ) {}

    public function isInteractiveTerminal(): bool
    {
        return $this->resolveTerminalState()->isInteractiveTty();
    }

    public function runScreen(ToolkitScreenInterface $screen): void
    {
        $terminalState = $this->resolveTerminalState();
        if (!$terminalState->isInteractiveTty()) {
            return;
        }

        $runner = new ScreenRunner($terminalState, $this->output);
        $runner->run(new ToolkitScreenAdapter($screen));
    }

    private function resolveTerminalState(): TerminalStateManager
    {
        return $this->terminalState ?? new TerminalStateManager();
    }
}