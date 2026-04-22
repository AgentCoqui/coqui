<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Session;

final readonly class SessionTypeOperationResult
{
    /**
     * @param array<string, mixed> $session
     */
    public function __construct(
        public array $session,
        public bool $created,
    ) {}
}