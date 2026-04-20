<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl;

use CoquiBot\Coqui\Contract\ToolkitCommandExample;
use CoquiBot\Coqui\Contract\ToolkitCommandHandler;
use CoquiBot\Coqui\Contract\ToolkitCommandHelp;
use CoquiBot\Coqui\Contract\ToolkitCommandHelpEntry;
use CoquiBot\Coqui\Contract\ToolkitCommandHelpProvider;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shared formatter for toolkit-provided REPL command help pages.
 */
final class ToolkitCommandHelpRenderer
{
    public function render(SymfonyStyle $io, ToolkitCommandHandler $handler): void
    {
        $command = '/' . $handler->commandName();
        $help = $handler instanceof ToolkitCommandHelpProvider
            ? $handler->help()
            : new ToolkitCommandHelp();

        $title = trim($help->title ?? $command);
        $summary = trim($help->summary ?? $handler->description());
        $subcommands = $help->subcommands !== []
            ? $help->subcommands
            : $this->defaultSubcommands($handler);

        $io->section($title);

        if ($summary !== '') {
            $io->text($summary);
            $io->newLine();
        }

        $io->text('<fg=gray>Usage:</> ' . $handler->usage());

        if ($subcommands !== []) {
            $rows = array_map(
                static fn(ToolkitCommandHelpEntry $entry): array => [
                    $entry->name,
                    $entry->usage,
                    $entry->description,
                ],
                $subcommands,
            );

            $io->newLine();
            $io->table(['Subcommand', 'Usage', 'Description'], $rows);
        }

        if ($help->examples !== []) {
            $rows = array_map(
                static fn(ToolkitCommandExample $example): array => [
                    $example->command,
                    $example->description,
                ],
                $help->examples,
            );

            $io->newLine();
            $io->table(['Example', 'Description'], $rows);
        }

        if ($help->notes !== []) {
            $io->newLine();
            $io->text('<fg=gray>Notes:</>');
            $io->listing($help->notes);
        }
    }

    /**
     * @return list<ToolkitCommandHelpEntry>
     */
    private function defaultSubcommands(ToolkitCommandHandler $handler): array
    {
        $command = '/' . $handler->commandName();

        return array_map(
            static fn(string $subcommand): ToolkitCommandHelpEntry => new ToolkitCommandHelpEntry(
                $subcommand,
                trim($command . ' ' . $subcommand),
                '',
            ),
            $handler->subcommands(),
        );
    }
}