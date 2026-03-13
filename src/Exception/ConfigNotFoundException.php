<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Exception;

final class ConfigNotFoundException extends \RuntimeException
{
    public static function forPath(string $path): self
    {
        return new self(sprintf('Config file not found: %s', $path));
    }

    public static function unreadable(string $path): self
    {
        return new self(sprintf('Failed to read config file: %s', $path));
    }
}
