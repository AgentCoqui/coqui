<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Toolkit-provided REPL command handler.
 *
 * Toolkits implement this interface to self-register slash commands in the REPL.
 * The SlashCommandRouter discovers handlers from registered toolkits and dispatches
 * to them dynamically, so core does not need to know about toolkit-specific commands.
 *
 * Handlers receive a ToolkitReplContext that provides access to terminal I/O,
 * interactive prompts, spinners, databases, and workspace context.
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
     * Concise one-line description for global help output.
     *
     * Richer command-specific help should be exposed via ToolkitCommandHelpProvider.
     */
    public function description(): string;

    /**
     * Handle the command.
     *
     * @param ToolkitReplContext $context Services object with I/O, prompts, workspace context, and factories
     * @param string $arg Everything after the command name (e.g. 'generate "fox" --vendor=openai')
     */
    public function handle(ToolkitReplContext $context, string $arg): void;
}
