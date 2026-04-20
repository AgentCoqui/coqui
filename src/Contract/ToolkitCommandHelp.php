<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Optional structured help metadata for toolkit-provided REPL commands.
 */
final readonly class ToolkitCommandHelp
{
    /**
     * @param list<ToolkitCommandHelpEntry> $subcommands
     * @param list<ToolkitCommandExample> $examples
     * @param list<string> $notes
     */
    public function __construct(
        public ?string $title = null,
        public ?string $summary = null,
        public array $subcommands = [],
        public array $examples = [],
        public array $notes = [],
    ) {}
}