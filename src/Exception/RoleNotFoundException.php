<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Exception;

final class RoleNotFoundException extends \RuntimeException
{
    public static function forName(string $name): self
    {
        return new self(sprintf('Role "%s" not found.', $name));
    }
}
