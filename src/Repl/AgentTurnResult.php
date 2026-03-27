<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl;

/**
 * Result of executing a single agent turn.
 */
final readonly class AgentTurnResult
{
    public function __construct(
        public ?int $exitCode = null,
        public ?string $continuationPrompt = null,
    ) {}

    public function shouldExit(): bool
    {
        return $this->exitCode !== null;
    }
}
