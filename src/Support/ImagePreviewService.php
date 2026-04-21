<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Support;

use CarmeloSantana\CoquiToolkitImages\Support\ImagePreviewFormatter;

final class ImagePreviewService
{
    private const int DEFAULT_WIDTH = 40;
    private const array SUPPORTED_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'bmp'];

    /**
     * @var \Closure(string, int): array{preview: string|null, preview_format: string|null, unavailable_reason: string|null}
     */
    private readonly \Closure $formatter;

    /**
     * @param null|\Closure(string, int): array{preview: string|null, preview_format: string|null, unavailable_reason: string|null} $formatter
     */
    public function __construct(
        private readonly string $workspacePath,
        ?\Closure $formatter = null,
    ) {
        $this->formatter = $formatter ?? static function (string $path, int $width): array {
            static $previewFormatter = null;

            if (!$previewFormatter instanceof ImagePreviewFormatter) {
                $previewFormatter = new ImagePreviewFormatter();
            }

            return $previewFormatter->format($path, $width);
        };
    }

    public function canPreviewPath(mixed $path): bool
    {
        if (!is_string($path)) {
            return false;
        }

        $normalized = $this->normalizeInputPath($path);
        if ($normalized === null) {
            return false;
        }

        $extension = strtolower(pathinfo($normalized, PATHINFO_EXTENSION));

        return $extension !== '' && in_array($extension, self::SUPPORTED_EXTENSIONS, true);
    }

    public function resolvePath(string $path): string
    {
        $normalized = $this->normalizeInputPath($path);
        if ($normalized === null) {
            throw FileSystemException::fileNotFound($path);
        }

        $workspace = $this->workspaceRealPath();
        $candidate = str_starts_with($normalized, '/')
            ? $normalized
            : $this->workspacePath . '/' . ltrim($normalized, '/');

        $directory = realpath(dirname($candidate));
        if ($directory === false) {
            throw FileSystemException::fileNotFound($path);
        }

        if (!$this->isWithinWorkspace($directory, $workspace)) {
            throw FileSystemException::pathEscapesSandbox($path);
        }

        $resolved = realpath($directory . '/' . basename($candidate));
        if ($resolved === false || !is_file($resolved)) {
            throw FileSystemException::fileNotFound($path);
        }

        if (!$this->isWithinWorkspace($resolved, $workspace)) {
            throw FileSystemException::pathEscapesSandbox($path);
        }

        return $resolved;
    }

    /**
     * @return array{path: string, preview: string|null, preview_format: string|null, unavailable_reason: string|null}
     */
    public function preview(string $path, int $width = self::DEFAULT_WIDTH): array
    {
        $resolved = $this->resolvePath($path);

        return [
            'path' => $resolved,
            ...($this->formatter)($resolved, $width),
        ];
    }

    private function normalizeInputPath(string $path): ?string
    {
        $trimmed = trim($path);
        if ($trimmed === '') {
            return null;
        }

        $scheme = parse_url($trimmed, PHP_URL_SCHEME);
        if (is_string($scheme) && $scheme !== '' && $scheme !== 'file') {
            return null;
        }

        if ($scheme === 'file') {
            $parsedPath = parse_url($trimmed, PHP_URL_PATH);
            if (!is_string($parsedPath) || $parsedPath === '') {
                return null;
            }

            return rawurldecode($parsedPath);
        }

        return $trimmed;
    }

    private function workspaceRealPath(): string
    {
        $resolved = realpath($this->workspacePath);
        if ($resolved === false) {
            throw FileSystemException::pathEscapesSandbox($this->workspacePath);
        }

        return $resolved;
    }

    private function isWithinWorkspace(string $resolvedPath, string $resolvedWorkspace): bool
    {
        return $resolvedPath === $resolvedWorkspace || str_starts_with($resolvedPath, $resolvedWorkspace . '/');
    }
}