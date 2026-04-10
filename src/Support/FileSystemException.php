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

    public static function absolutePathNotInSandbox(string $path): self
    {
        return new self(sprintf('Absolute path is outside the workspace and not under any mount: %s', $path));
    }

    public static function copyFailed(string $source, string $destination, string $reason = ''): self
    {
        $msg = sprintf('Failed to copy %s → %s', $source, $destination);
        if ($reason !== '') {
            $msg .= ': ' . $reason;
        }

        return new self($msg);
    }

    public static function moveFailed(string $source, string $destination, string $reason = ''): self
    {
        $msg = sprintf('Failed to move %s → %s', $source, $destination);
        if ($reason !== '') {
            $msg .= ': ' . $reason;
        }

        return new self($msg);
    }

    public static function cannotCopyToSelf(string $path): self
    {
        return new self(sprintf('Cannot copy a path onto itself: %s', $path));
    }

    public static function maxRecursiveItemsExceeded(int $limit): self
    {
        return new self(sprintf('Recursive operation exceeds the safety limit of %d items', $limit));
    }

    public static function deletionFailed(string $path): self
    {
        return new self(sprintf('Failed to delete: %s', $path));
    }

    public static function binaryFileNotEditable(string $path): self
    {
        return new self(sprintf('Cannot perform surgical edit on binary file: %s — use write_file for full replacement.', $path));
    }

    public static function fileTooLarge(string $path, int $sizeBytes, int $limitBytes): self
    {
        $sizeMb = round($sizeBytes / 1_048_576, 1);
        $limitMb = round($limitBytes / 1_048_576, 1);

        return new self(sprintf('File too large for surgical edit: %s (%s MB, limit %s MB) — use write_file for full replacement.', $path, $sizeMb, $limitMb));
    }
}
