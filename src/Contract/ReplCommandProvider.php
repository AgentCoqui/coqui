<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Toolkit-level interface for declaring REPL slash commands.
 *
 * Toolkits implement this alongside ToolkitInterface to self-register
 * commands that the SlashCommandRouter discovers and dispatches dynamically.
 * Discovery checks `instanceof ReplCommandProvider` on instantiated toolkits.
 */
interface ReplCommandProvider
{
    /**
     * Return the command handlers this toolkit provides.
     *
     * Each handler registers one slash command (e.g. '/image', '/browser').
     * Core commands always take precedence over toolkit-provided commands.
     *
     * @return list<ToolkitCommandHandler>
     */
    public function commandHandlers(): array;
}
