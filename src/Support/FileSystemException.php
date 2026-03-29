<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Support;

/**
 * Domain exception for filesystem operations.
 */
final class FileSystemException extends \RuntimeException
{
    public static function fileNotFound(string $path): self
    {
        return new self(sprintf('File not found: %s', $path));
    }

    public static function readFailed(string $path): self
    {
        return new self(sprintf('Unable to read file: %s', $path));
    }

    public static function writeFailed(string $path): self
    {
        return new self(sprintf('Failed to write file: %s', $path));
    }

    public static function readOnlyMount(string $path): self
    {
        return new self(sprintf('Cannot write — mount is read-only: %s', $path));
    }

    public static function directoryCreationFailed(string $path): self
    {
        return new self(sprintf('Failed to create directory: %s', $path));
    }

    public static function invalidRange(int $from, int $to): self
    {
        return new self(sprintf('Invalid line range: %d–%d (from must be ≥ 1 and ≤ to)', $from, $to));
    }

    public static function invalidRegex(string $pattern): self
    {
        return new self(sprintf('Invalid regex pattern: %s', $pattern));
    }

    public static function pathEscapesSandbox(string $path): self
    {
        return new self(sprintf('Path escapes workspace boundary: %s', $path));
    }
}
