<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Exception;

final class RoleParseException extends \RuntimeException
{
    public static function missingRoleFile(string $path): self
    {
        return new self(sprintf(
            'Role file not found: "%s".',
            $path,
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

    public static function invalidFieldValue(string $field, string $value, string $path): self
    {
        return new self(sprintf(
            'Invalid value "%s" for field "%s" in "%s".',
            $value,
            $field,
            $path,
        ));
    }
}
