<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl;

use CoquiBot\Coqui\Contract\ToolkitCommandHandler;

/**
 * Canonical result of toolkit REPL command registration.
 */
final readonly class ToolkitCommandRegistrationReport
{
    /**
     * @param list<ToolkitCommandHandler> $acceptedHandlers
     * @param list<ReplCommandSpec> $acceptedSpecs
     * @param list<ToolkitCommandCollision> $collisions
     */
    public function __construct(
        public array $acceptedHandlers,
        public array $acceptedSpecs,
        public array $collisions,
    ) {}

    public function hasCollisions(): bool
    {
        return $this->collisions !== [];
    }
}