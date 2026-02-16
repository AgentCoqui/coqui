<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Renderer;

use CoquiBot\Coqui\Contract\AgentTurnResult;
use CoquiBot\Coqui\Contract\OutputRendererInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders agent turn results as JSON to stdout.
 *
 * Used with --no-terminal --format json for programmatic consumption.
 */
final class JsonRenderer implements OutputRendererInterface
{
    public function __construct(
        private readonly OutputInterface $output,
    ) {}

    public function render(AgentTurnResult $result): void
    {
        $json = json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->output->writeln($json);
    }

    public function renderError(string $message): void
    {
        $json = json_encode([
            'error' => ['message' => $message],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->output->writeln($json);
    }
}
