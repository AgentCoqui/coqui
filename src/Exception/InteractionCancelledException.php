<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Exception;

final class InteractionCancelledException extends \RuntimeException
{
    public static function byEsc(): self
    {
        return new self('Interaction cancelled by ESC.');
    }
}