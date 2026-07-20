<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Removes secrets from audit-log payloads before they are persisted.
 *
 * SessionStorage depends on this contract rather than the concrete redactor so
 * the fail-closed write path can be tested with a deliberately throwing
 * implementation.
 */
interface AuditRedactorInterface
{
    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function redact(array $arguments): array;

    public function redactScalar(?string $value): ?string;
}
