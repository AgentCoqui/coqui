<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Support;

final readonly class InteractiveSessionOperationResult
{
    /**
     * @param array<string, mixed> $session
     * @param list<string> $closedSessionIds
     */
    public function __construct(
        public array $session,
        public bool $created,
        public array $closedSessionIds = [],
    ) {}
}