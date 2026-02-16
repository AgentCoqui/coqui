<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Exception;

final class SkillParseException extends \RuntimeException
{
    public static function missingSkillMd(string $directory): self
    {
        return new self(sprintf(
            'No SKILL.md or skill.md found in directory "%s".',
            $directory,
        ));
    }

    public static function malformedFrontmatter(string $path, string $reason): self
    {
        return new self(sprintf(
            'Malformed YAML frontmatter in "%s": %s',
            $path,
            $reason,
        ));
    }

    public static function missingRequiredField(string $field, string $path): self
    {
        return new self(sprintf(
            'Required field "%s" is missing in "%s".',
            $field,
            $path,
        ));
    }
}
