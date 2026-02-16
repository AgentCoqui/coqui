<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Prompt;

final class PromptNotFoundException extends \RuntimeException
{
    public static function forFile(string $filename, string $directory): self
    {
        return new self(sprintf(
            'Prompt file "%s" not found in directory "%s".',
            $filename,
            $directory,
        ));
    }
}
