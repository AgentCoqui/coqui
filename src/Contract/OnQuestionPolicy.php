<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Non-interactive question policy for loops / background tasks.
 */
enum OnQuestionPolicy: string
{
    case Block = 'block';
    case DefaultAnswer = 'default';

    public static function fromString(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Block;
    }
}
