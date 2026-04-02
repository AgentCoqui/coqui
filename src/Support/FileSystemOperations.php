<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Support;

use CoquiBot\Coqui\Contract\CoquiDefaults;

/**
 * Shared file I/O helpers with path sandboxing, atomic writes, and line ending detection.
 *
 * Combines the path resolution logic from php-agents' FilesystemToolkit (mount-aware
 * sandbox) with the atomic write and EOL detection from the code-edit toolkit.
 *
 * All file operations go through resolvePath() to prevent directory traversal.
 * Write operations additionally check mount read-only status.
 */
final readonly class FileSystemOperations
{
    private string $realRoot;

    /**
     * @param string $rootPath      Primary sandbox root directory (workspace).
     * @param array<int, array{realPath: string, readOnly: bool}> $allowedPaths
     *        Additional allowed directories (mounts). Each entry has a realPath
     *        (absolute canonical path) and a readOnly flag.
     */
    public function __construct(
        private string $rootPath,
        private array $allowedPaths = [],
    ) {
        $real = realpath($this->rootPath);
        $this->realRoot = $real !== false ? $real : $this->rootPath;
    }

    // ---------------------------------------------------------------
    // Read operations
    // ---------------------------------------------------------------

    /**
     * Read the full contents of a file.
     *
     * @throws FileSystemException If the file does not exist or cannot be read.
     */
    public function read(string $relativePath): string
    {
        $path = $this->resolvePath($relativePath);

        if (!is_file($path)) {
            throw FileSystemException::fileNotFound($relativePath);
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            throw FileSystemException::readFailed($relativePath);
        }

        return $content;
    }

    /**
     * Read a file and split into lines, preserving the detected line ending style.
     *
     * @return array{lines: string[], eol: non-empty-string}
     * @throws FileSystemException
     */
    public function readLines(string $relativePath): array
    {
        $content = $this->read($relativePath);
        $eol = self::detectEol($content);
        $lines = explode($eol, $content);

        return ['lines' => $lines, 'eol' => $eol];
    }

    /**
     * Check if a file exists within the sandbox.
     */
    public function exists(string $relativePath): bool
    {
        $path = $this->resolvePath($relativePath);

        return file_exists($path);
    }

    /**
     * Check if a path is a regular file.
     */
    public function isFile(string $relativePath): bool
    {
        return is_file($this->resolvePath($relativePath));
    }

    /**
     * Check if a path is a directory.
     */
    public function isDir(string $relativePath): bool
    {
        return is_dir($this->resolvePath($relativePath));
    }

    // ---------------------------------------------------------------
    // Write operations (all check mount read-only status)
    // ---------------------------------------------------------------

    /**
     * Write content to a file atomically (temp file + rename).
     *
     * Creates parent directories automatically. Preserves original file
     * permissions when overwriting.
     *
     * @throws FileSystemException If the path is read-only or writing fails.
     */
    public function write(string $relativePath, string $content): void
    {
        $path = $this->resolvePath($relativePath);
        $this->guardReadOnly($path, $relativePath);

        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));

        if (@file_put_contents($tmp, $content) === false) {
            @unlink($tmp);
            throw FileSystemException::writeFailed($relativePath);
        }

        // Preserve original permissions if file exists
        if (is_file($path)) {
            $perms = fileperms($path);
            if ($perms !== false) {
                @chmod($tmp, $perms);
            }
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw FileSystemException::writeFailed($relativePath);
        }
    }

    /**
     * Join lines with the given EOL and write atomically.
     *
     * @param string[] $lines
     */
    public function writeLines(string $relativePath, array $lines, string $eol = "\n"): void
    {
        $this->write($relativePath, implode($eol, $lines));
    }

    /**
     * Append content to a file. Creates the file if it does not exist.
     *
     * @return int Bytes appended.
     * @throws FileSystemException
     */
    public function append(string $relativePath, string $content): int
    {
        $path = $this->resolvePath($relativePath);
        $this->guardReadOnly($path, $relativePath);

        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $bytes = @file_put_contents($path, $content, FILE_APPEND | LOCK_EX);
        if ($bytes === false) {
            throw FileSystemException::writeFailed($relativePath);
        }

        return $bytes;
    }

    /**
     * Delete a file within the sandbox.
     *
     * @throws FileSystemException
     */
    public function delete(string $relativePath): void
    {
        $path = $this->resolvePath($relativePath);
        $this->guardReadOnly($path, $relativePath);

        if (!file_exists($path)) {
            throw FileSystemException::fileNotFound($relativePath);
        }

        if (is_dir($path)) {
            throw new FileSystemException(sprintf('Cannot delete directory with this operation: %s', $relativePath));
        }

        if (!@unlink($path)) {
            throw new FileSystemException(sprintf('Failed to delete file: %s', $relativePath));
        }
    }

    /**
     * Create a directory recursively within the sandbox.
     *
     * @throws FileSystemException
     */
    public function createDir(string $relativePath): void
    {
        $path = $this->resolvePath($relativePath);
        $this->guardReadOnly($path, $relativePath);

        if (is_dir($path)) {
            return; // Already exists
        }

        if (!@mkdir($path, 0755, true)) {
            throw FileSystemException::directoryCreationFailed($relativePath);
        }
    }

    // ---------------------------------------------------------------
    // Copy and move operations
    // ---------------------------------------------------------------

    /**
     * Copy a file or directory to a destination within the sandbox.
     *
     * For files: single copy. For directories: recursive copy.
     * Creates destination parent directories automatically.
     *
     * @return int Number of items copied (1 for a file, N for a directory tree).
     * @throws FileSystemException
     */
    public function copyPath(string $source, string $destination): int
    {
        $srcPath = $this->resolvePath($source);
        $dstPath = $this->resolvePath($destination);
        $this->guardReadOnly($dstPath, $destination);

        if (!file_exists($srcPath)) {
            throw FileSystemException::fileNotFound($source);
        }

        $realSrc = realpath($srcPath);
        $realDst = realpath($dstPath);
        if ($realSrc !== false && $realDst !== false && $realSrc === $realDst) {
            throw FileSystemException::cannotCopyToSelf($source);
        }

        if (is_file($srcPath)) {
            return $this->copySingleFile($srcPath, $dstPath, $source, $destination);
        }

        return $this->copyDirectory($srcPath, $dstPath, $source, $destination);
    }

    /**
     * Move a file or directory to a destination within the sandbox.
     *
     * Attempts a rename first; falls back to copy+delete for cross-device moves.
     *
     * @return int Number of items moved (1 for a file, N for a directory tree).
     * @throws FileSystemException
     */
    public function movePath(string $source, string $destination): int
    {
        $srcPath = $this->resolvePath($source);
        $dstPath = $this->resolvePath($destination);
        $this->guardReadOnly($srcPath, $source);
        $this->guardReadOnly($dstPath, $destination);

        if (!file_exists($srcPath)) {
            throw FileSystemException::fileNotFound($source);
        }

        $realSrc = realpath($srcPath);
        $realDst = realpath($dstPath);
        if ($realSrc !== false && $realDst !== false && $realSrc === $realDst) {
            throw FileSystemException::cannotCopyToSelf($source);
        }

        // Attempt rename (atomic, same filesystem)
        $dstDir = dirname($dstPath);
        if (!is_dir($dstDir)) {
            @mkdir($dstDir, 0755, true);
        }

        if (@rename($srcPath, $dstPath)) {
            // Count items for consistency
            if (is_dir($dstPath)) {
                return $this->countItems($dstPath);
            }

            return 1;
        }

        // Cross-device fallback: copy then delete source
        $count = $this->copyPath($source, $destination);
        $this->deleteDirectory($srcPath);

        return $count;
    }

    /**
     * Recursively delete a directory and all its contents.
     *
     * @throws FileSystemException
     */
    public function deleteDirectory(string $path): void
    {
        // Accept both relative and absolute paths
        if (!str_starts_with($path, '/')) {
            $path = $this->resolvePath($path);
        }

        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                if (!@rmdir($item->getPathname())) {
                    throw FileSystemException::deletionFailed($item->getPathname());
                }
            } else {
                if (!@unlink($item->getPathname())) {
                    throw FileSystemException::deletionFailed($item->getPathname());
                }
            }
        }

        if (!@rmdir($path)) {
            throw FileSystemException::deletionFailed($path);
        }
    }

    private function copySingleFile(string $srcPath, string $dstPath, string $srcDisplay, string $dstDisplay): int
    {
        $dstDir = dirname($dstPath);
        if (!is_dir($dstDir)) {
            @mkdir($dstDir, 0755, true);
        }

        if (!@copy($srcPath, $dstPath)) {
            throw FileSystemException::copyFailed($srcDisplay, $dstDisplay);
        }

        return 1;
    }

    private function copyDirectory(string $srcPath, string $dstPath, string $srcDisplay, string $dstDisplay): int
    {
        if (!is_dir($dstPath)) {
            if (!@mkdir($dstPath, 0755, true)) {
                throw FileSystemException::directoryCreationFailed($dstDisplay);
            }
        }

        $dirIterator = new \RecursiveDirectoryIterator($srcPath, \FilesystemIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator(
            $dirIterator,
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        $count = 0;
        $srcLen = strlen($srcPath);

        foreach ($iterator as $item) {
            if ($count >= CoquiDefaults::MAX_RECURSIVE_ITEMS) {
                throw FileSystemException::maxRecursiveItemsExceeded(CoquiDefaults::MAX_RECURSIVE_ITEMS);
            }

            $subPath = substr($item->getPathname(), $srcLen + 1);
            $target = $dstPath . '/' . $subPath;

            if ($item->isDir()) {
                if (!is_dir($target)) {
                    if (!@mkdir($target, 0755, true)) {
                        throw FileSystemException::directoryCreationFailed($subPath);
                    }
                }
            } else {
                $targetDir = dirname($target);
                if (!is_dir($targetDir)) {
                    @mkdir($targetDir, 0755, true);
                }

                if (!@copy($item->getPathname(), $target)) {
                    throw FileSystemException::copyFailed($srcDisplay, $dstDisplay, $subPath);
                }
            }

            $count++;
        }

        return max(1, $count);
    }

    private function countItems(string $dirPath): int
    {
        if (!is_dir($dirPath)) {
            return 1;
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dirPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $_) {
            $count++;
        }

        return max(1, $count);
    }

    // ---------------------------------------------------------------
    // Path resolution and sandbox enforcement
    // ---------------------------------------------------------------

    /**
     * Resolve a relative path to an absolute path within the sandbox.
     *
     * Prevents directory traversal by canonicalizing path segments
     * and verifying the result stays within the root or an allowed mount.
     *
     * @throws FileSystemException If the resolved path escapes the sandbox.
     */
    public function resolvePath(string $relativePath): string
    {
        // Canonicalize relative path segments to prevent traversal
        $segments = explode('/', str_replace('\\', '/', $relativePath));
        $resolved = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($resolved);
            } else {
                $resolved[] = $segment;
            }
        }
        $canonicalized = implode('/', $resolved);
        $path = $this->rootPath . '/' . $canonicalized;

        // For existing paths — verify via realpath
        $realPath = realpath($path);
        if ($realPath !== false) {
            if (str_starts_with($realPath, $this->realRoot)) {
                return $realPath;
            }
            if ($this->isUnderAllowedPath($realPath)) {
                return $realPath;
            }

            throw FileSystemException::pathEscapesSandbox($relativePath);
        }

        // Path doesn't exist yet — verify the deepest existing ancestor
        $parentPath = dirname($path);
        $realParent = realpath($parentPath);
        if ($realParent !== false) {
            if (!str_starts_with($realParent, $this->realRoot) && !$this->isUnderAllowedPath($realParent)) {
                throw FileSystemException::pathEscapesSandbox($relativePath);
            }
        }

        return $path;
    }

    /**
     * Maximum results returned from resolveGlob to prevent context overflow.
     */
    private const int MAX_GLOB_RESULTS = 500;

    /**
     * Resolve a glob pattern to absolute paths within the sandbox.
     *
     * Supports `**` for recursive directory traversal (e.g. `src/**\/*.php`).
     * When `**` is not present, falls back to PHP's native glob().
     *
     * @return string[] Matching file paths (absolute), all within the sandbox.
     */
    public function resolveGlob(string $pattern): array
    {
        $pattern = ltrim($pattern, "/\\");

        // If the pattern contains **, use recursive matching
        if (str_contains($pattern, '**')) {
            return $this->resolveGlobRecursive($pattern);
        }

        // Standard glob — no ** present
        $globPattern = $this->rootPath . '/' . $pattern;
        $matches = glob($globPattern, GLOB_NOSORT | GLOB_BRACE) ?: [];

        return array_values(array_filter(
            $matches,
            fn(string $path): bool => $this->isWithinSandbox($path),
        ));
    }

    /**
     * Recursive glob matching using directory iteration.
     *
     * Walks the entire directory tree under the root (and allowed mounts),
     * matching each file's relative path against the pattern via fnmatch().
     *
     * @return string[] Matching absolute paths, capped at MAX_GLOB_RESULTS.
     */
    private function resolveGlobRecursive(string $pattern): array
    {
        $matches = [];
        $searchRoots = [$this->rootPath];

        // Also search mounted directories
        foreach ($this->allowedPaths as $allowed) {
            $searchRoots[] = $allowed['realPath'];
        }

        foreach ($searchRoots as $searchRoot) {
            if (!is_dir($searchRoot)) {
                continue;
            }

            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator(
                        $searchRoot,
                        \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS,
                    ),
                    \RecursiveIteratorIterator::SELF_FIRST,
                );
            } catch (\UnexpectedValueException) {
                continue;
            }

            foreach ($iterator as $file) {
                if (count($matches) >= self::MAX_GLOB_RESULTS) {
                    return $matches;
                }

                $absolutePath = $file->getPathname();
                $relativePath = $this->makeRelative($absolutePath);

                if (fnmatch($pattern, $relativePath, FNM_PATHNAME)) {
                    if ($this->isWithinSandbox($absolutePath)) {
                        $matches[] = $absolutePath;
                    }
                }
            }
        }

        return $matches;
    }

    /**
     * Check if a path is within the sandbox (root or allowed mount).
     */
    private function isWithinSandbox(string $path): bool
    {
        $real = realpath($path);
        if ($real === false) {
            return false;
        }

        return str_starts_with($real, $this->realRoot) || $this->isUnderAllowedPath($real);
    }

    /**
     * Convert an absolute path back to a workspace-relative path.
     */
    public function makeRelative(string $absolutePath): string
    {
        if (str_starts_with($absolutePath, $this->rootPath . '/')) {
            return substr($absolutePath, strlen($this->rootPath) + 1);
        }

        if (str_starts_with($absolutePath, $this->realRoot . '/')) {
            return substr($absolutePath, strlen($this->realRoot) + 1);
        }

        return $absolutePath;
    }

    /**
     * Check if an absolute real path falls under any allowed (mounted) path.
     */
    public function isUnderAllowedPath(string $realPath): bool
    {
        foreach ($this->allowedPaths as $allowed) {
            if (str_starts_with($realPath, $allowed['realPath'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if an absolute path falls under a read-only mount.
     *
     * Returns false if the path is under the primary root (not a mount).
     */
    public function isReadOnlyMountPath(string $absolutePath): bool
    {
        $realPath = realpath($absolutePath);

        // For non-existent files, resolve via parent directory
        if ($realPath === false) {
            $realPath = realpath(dirname($absolutePath));
        }

        if ($realPath === false) {
            return false;
        }

        // Path under the primary root is never a read-only mount
        if (str_starts_with($realPath, $this->realRoot)) {
            return false;
        }

        // Path is outside root — check if it's under a read-only mount
        foreach ($this->allowedPaths as $allowed) {
            if (str_starts_with($realPath, $allowed['realPath'])) {
                return $allowed['readOnly'];
            }
        }

        return false;
    }

    /**
     * Detect the dominant line ending in a string.
     *
     * @return non-empty-string
     */
    public static function detectEol(string $content): string
    {
        $crlf = substr_count($content, "\r\n");
        $lf = substr_count($content, "\n") - $crlf;
        $cr = substr_count($content, "\r") - $crlf;

        if ($crlf >= $lf && $crlf >= $cr && $crlf > 0) {
            return "\r\n";
        }

        if ($cr > $lf && $cr > 0) {
            return "\r";
        }

        return "\n";
    }

    public function rootPath(): string
    {
        return $this->rootPath;
    }

    // ---------------------------------------------------------------
    // Internal helpers
    // ---------------------------------------------------------------

    /**
     * @throws FileSystemException If the path is under a read-only mount.
     */
    private function guardReadOnly(string $absolutePath, string $displayPath): void
    {
        if ($this->isReadOnlyMountPath($absolutePath)) {
            throw FileSystemException::readOnlyMount($displayPath);
        }
    }
}
