<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tui;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Writes rendered screen frames to the terminal using per-line cursor updates.
 */
final class ScreenFrameRenderer
{
    public function __construct(
        private readonly OutputInterface $output,
    ) {}

    public function renderFull(ScreenFrame $frame): void
    {
        foreach ($frame->lines as $row => $line) {
            $this->renderLine($row, $line);
        }
    }

    public function renderDiff(ScreenFrame $previous, ScreenFrame $next): void
    {
        foreach ($next->diffAgainst($previous) as $patch) {
            $this->renderLine($patch->row, $patch->line);
        }
    }

    private function renderLine(int $row, string $line): void
    {
        $this->output->write(sprintf("\e[%d;1H\e[2K%s", $row + 1, $line));
    }
}