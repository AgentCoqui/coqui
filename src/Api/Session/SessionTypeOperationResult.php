<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Session;

final readonly class SessionTypeOperationResult
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

    /**
     * @return list<string>
     */
    public function closedSessionIds(): array
    {
        return $this->closedSessionIds;
    }
}