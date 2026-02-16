<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Exception;

final class SkillValidationException extends \RuntimeException
{
    /**
     * @param string[] $errors
     */
    private function __construct(
        string $message,
        public readonly array $errors,
    ) {
        parent::__construct($message);
    }

    /**
     * @param string[] $errors
     */
    public static function withErrors(array $errors): self
    {
        return new self(implode('; ', $errors), $errors);
    }
}
