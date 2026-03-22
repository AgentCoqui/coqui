<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

/**
 * Value object describing a pending role update.
 */
final readonly class RoleUpdateInfo
{
    public function __construct(
        public string $roleName,
        public bool $hasBuiltinUpdate,
        public bool $isUserModified,
        public bool $ignoreUpdates,
    ) {}
}
