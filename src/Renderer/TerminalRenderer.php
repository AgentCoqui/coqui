<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Renderer;

use CoquiBot\Coqui\Contract\AgentTurnResult;
use CoquiBot\Coqui\Contract\OutputRendererInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Renders agent turn results to the terminal using SymfonyStyle.
 *
 * Extracts the display logic that was previously inline in AgentRunner::run().
 */
final class TerminalRenderer implements OutputRendererInterface
{
    public function __construct(
        private readonly SymfonyStyle $io,
    ) {}

    public function render(AgentTurnResult $result): void
    {
        $this->io->newLine();

        if ($result->isError()) {
            $this->io->error("Agent error: {$result->error}");
            return;
        }

        $this->io->writeln('<fg=green>Assistant:</>');
        $this->io->writeln($result->content);
        $this->io->newLine();
        $this->io->comment($result->statsSummary());
        $this->io->newLine();
    }

    public function renderError(string $message): void
    {
        $this->io->error($message);
    }
}
