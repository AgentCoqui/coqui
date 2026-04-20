<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Toolkit-provided REPL command handler.
 *
 * Toolkits implement this interface to self-register slash commands in the REPL.
 * The SlashCommandRouter discovers handlers from registered toolkits and dispatches
 * to them dynamically, so core does not need to know about toolkit-specific commands.
 */
interface ToolkitCommandHandler
{
    /**
     * The slash command name without the leading slash (e.g. 'image').
     */
    public function commandName(): string;

    /**
     * Subcommands for tab completion (e.g. ['generate', 'list', 'search', ...]).
     *
     * @return list<string>
     */
    public function subcommands(): array;

    /**
     * Short usage string for help output (e.g. '/image [action]').
     */
    public function usage(): string;

    /**
     * One-line description for help output.
     */
    public function description(): string;

    /**
     * Handle the command.
     *
     * @param string $arg Everything after the command name (e.g. 'generate "fox" --vendor=openai')
     * @param ?string $activeProfile Current active profile name
     * @param string $sessionId Current session ID
     */
    public function handle(SymfonyStyle $io, string $arg, ?string $activeProfile, string $sessionId): void;
}
