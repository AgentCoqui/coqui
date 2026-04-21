<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl;

use CoquiBot\Coqui\Contract\ToolkitKeyEvent;
use CoquiBot\Coqui\Contract\ToolkitScreenAction;
use CoquiBot\Coqui\Contract\ToolkitScreenInterface;
use CoquiBot\Coqui\Tui\KeyEvent;
use CoquiBot\Coqui\Tui\ScreenAction;
use CoquiBot\Coqui\Tui\ScreenInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Adapts toolkit-facing fullscreen screens to the internal TUI runtime.
 */
final readonly class ToolkitScreenAdapter implements ScreenInterface
{
    public function __construct(
        private ToolkitScreenInterface $screen,
    ) {}

    public function render(OutputInterface $output, int $width, int $height): void
    {
        $this->screen->render($output, $width, $height);
    }

    public function handleKey(KeyEvent $key): ?ScreenAction
    {
        $action = $this->screen->handleKey(new ToolkitKeyEvent($key->type, $key->char));

        if ($action === null) {
            return null;
        }

        if ($action->isExit()) {
            return ScreenAction::exit();
        }

        if ($action->isPop()) {
            return ScreenAction::pop();
        }

        if ($action->isPush() && $action->screen !== null) {
            return ScreenAction::push(new self($action->screen));
        }

        return ScreenAction::refresh();
    }

    public function tick(): bool
    {
        return $this->screen->tick();
    }

    public function title(): string
    {
        return $this->screen->title();
    }
}