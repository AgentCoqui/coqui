<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Optional interface for richer, standardized toolkit command help.
 *
 * When implemented, the REPL renders the provided help metadata using the
 * shared core formatter for both `/command` and `/command help`.
 */
interface ToolkitCommandHelpProvider
{
    public function help(): ToolkitCommandHelp;
}