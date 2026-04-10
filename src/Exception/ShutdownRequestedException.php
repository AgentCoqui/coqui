<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Exception;

final class ShutdownRequestedException extends \RuntimeException
{
    public static function bySignal(): self
    {
        return new self('Shutdown requested by Ctrl+C.');
    }
}