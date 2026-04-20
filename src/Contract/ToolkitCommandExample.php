<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Example invocation for a toolkit-provided REPL command.
 */
final readonly class ToolkitCommandExample
{
    public function __construct(
        public string $command,
        public string $description = '',
    ) {}
}