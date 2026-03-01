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

public function render(AgentTurnResult $result, bool $contentStreamed = false): void
    {
        $this->io->newLine();

        if ($result->isError()) {
            $this->io->error("Agent error: {$result->error}");
            return;
        }

        if (!$contentStreamed) {
            $this->io->writeln('<fg=green>Assistant:</>');
            $this->io->writeln($result->content);
        }

        $this->io->newLine();
        $this->renderStatsSummary($result);
        $this->io->newLine();
    }

    private function renderStatsSummary(AgentTurnResult $result): void
    {
        $line = "<fg=gray>  | Iteration </>{$result->iterations}";

        if ($result->totalTokens > 0) {
            $line .= '<fg=gray> | Tokens: </>' . number_format($result->totalTokens);
        }

        if ($result->durationMs > 0) {
            $seconds = round($result->durationMs / 1000, 1);
            $line .= "<fg=gray> | Duration: </>{$seconds}s";
        }

        $this->io->writeln($line);

        if (!empty($result->toolsUsed)) {
            $yellowTools = array_map(
                fn(string $tool): string => "<fg=yellow>{$tool}</>",
                $result->toolsUsed,
            );
            $this->io->writeln('<fg=gray>  | Tools: </>' . implode('<fg=gray>, </>', $yellowTools));
        }
    }

    public function renderError(string $message): void
    {
        $this->io->error($message);
    }
}
