<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl;

/**
 * Describes a skipped toolkit command registration.
 */
final readonly class ToolkitCommandCollision
{
    /**
     * @param 'core'|'toolkit' $reason
     */
    public function __construct(
        public string $command,
        public string $reason,
        public string $winnerPackage,
        public string $winner,
        public string $winnerUsage,
        public string $skippedPackage,
        public string $skipped,
        public string $skippedUsage,
    ) {}
}