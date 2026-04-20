<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Structured subcommand metadata for toolkit help rendering.
 */
final readonly class ToolkitCommandHelpEntry
{
    public function __construct(
        public string $name,
        public string $usage,
        public string $description,
    ) {}
}