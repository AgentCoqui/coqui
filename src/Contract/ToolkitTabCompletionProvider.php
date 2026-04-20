<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Optional interface for dynamic tab completion beyond static subcommands.
 *
 * A ToolkitCommandHandler can also implement this interface to provide
 * context-aware argument completions (e.g. record IDs, model names, tags).
 * If not implemented, only the static subcommands() list is used.
 */
interface ToolkitTabCompletionProvider
{
    /**
     * Complete arguments for a toolkit-provided command.
     *
     * Called when the user presses TAB after the command name and optional
     * subcommand. The parts array contains the already-typed tokens
     * (excluding the command name itself).
     *
     * @param string $commandName The command being completed (without leading slash)
     * @param list<string> $parts Tokens already typed after the command name
     * @return list<string> Completion candidates
     */
    public function completeArguments(string $commandName, array $parts): array;
}
