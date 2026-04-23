<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Support;

final readonly class GroupSessionOperationResult
{
    /**
     * @param array<string, mixed> $session
     * @param list<string> $closedSessionIds
     */
    public function __construct(
        public array $session,
        public bool $created = false,
        public array $closedSessionIds = [],
    ) {}
}