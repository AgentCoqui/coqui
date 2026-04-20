<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl;

use CoquiBot\Coqui\Contract\ToolkitCommandHandler;

/**
 * Package-aware candidate for toolkit REPL command registration.
 */
final readonly class ToolkitCommandCandidate
{
    public function __construct(
        public string $package,
        public ToolkitCommandHandler $handler,
    ) {}
}