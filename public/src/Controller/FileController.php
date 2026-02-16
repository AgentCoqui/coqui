<?php

declare(strict_types=1);

namespace CoquiBot\Dashboard\Controller;

/**
 * JSON API endpoints for browsing and editing files in .workspace/.
 *
 * All paths are sandboxed to the workspace directory via realpath validation.
 */
final class FileController
{
    public function __construct(
        private readonly string $workspacePath,
    ) {}

    /**
     * GET /api/files — list directory contents.
     */
    public function listFiles(): void
    {
        $relativePath = $_GET['path'] ?? '';
        $absolutePath = $this->resolveSafePath($relativePath);

        if ($absolutePath === null) {
            $this->json(['error' => 'Invalid path'], 403);
            return;
        }

        if (!is_dir($absolutePath)) {
            $this->json(['error' => 'Not a directory'], 400);
            return;
        }

        $items = [];
        $entries = scandir($absolutePath);

        if ($entries === false) {
            $this->json(['error' => 'Failed to read directory'], 500);
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = $absolutePath . '/' . $entry;
            $isDir = is_dir($fullPath);

            $item = [
                'name' => $entry,
                'path' => ltrim($relativePath . '/' . $entry, '/'),
                'type' => $isDir ? 'directory' : 'file',
                'size' => $isDir ? null : filesize($fullPath),
                'modified' => date('c', filemtime($fullPath) ?: 0),
            ];

            if (!$isDir) {
                $item['extension'] = pathinfo($entry, PATHINFO_EXTENSION);
            }

            $items[] = $item;
        }

        // Sort: directories first, then alphabetical
        usort($items, function (array $a, array $b): int {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'directory' ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        $this->json([
            'path' => $relativePath ?: '/',
            'items' => $items,
        ]);
    }

    /**
     * GET /api/files/read — read file content.
     */
    public function readFile(): void
    {
        $relativePath = $_GET['path'] ?? '';

        if ($relativePath === '') {
            $this->json(['error' => 'Path is required'], 400);
            return;
        }

        $absolutePath = $this->resolveSafePath($relativePath);

        if ($absolutePath === null) {
            $this->json(['error' => 'Invalid path'], 403);
            return;
        }

        if (!is_file($absolutePath)) {
            $this->json(['error' => 'Not a file'], 400);
            return;
        }

        $size = filesize($absolutePath);

        // Reject large files (>1MB)
        if ($size !== false && $size > 1_048_576) {
            $this->json([
                'error' => 'File too large to read',
                'size' => $size,
                'path' => $relativePath,
            ], 413);
            return;
        }

        // Check if binary
        if ($this->isBinary($absolutePath)) {
            $this->json([
                'path' => $relativePath,
                'binary' => true,
                'size' => $size,
                'extension' => pathinfo($absolutePath, PATHINFO_EXTENSION),
                'modified' => date('c', filemtime($absolutePath) ?: 0),
            ]);
            return;
        }

        $content = file_get_contents($absolutePath);

        $this->json([
            'path' => $relativePath,
            'content' => $content !== false ? $content : '',
            'size' => $size,
            'language' => $this->detectLanguage($absolutePath),
            'modified' => date('c', filemtime($absolutePath) ?: 0),
        ]);
    }

    /**
     * PUT /api/files/write — write file content.
     */
    public function writeFile(): void
    {
        $body = json_decode(file_get_contents('php://input') ?: '{}', true);
        $relativePath = $body['path'] ?? $_GET['path'] ?? '';
        $content = $body['content'] ?? null;

        if ($relativePath === '') {
            $this->json(['error' => 'Path is required'], 400);
            return;
        }

        if ($content === null) {
            $this->json(['error' => 'Content is required'], 400);
            return;
        }

        $absolutePath = $this->resolveSafePath($relativePath, allowNew: true);

        if ($absolutePath === null) {
            $this->json(['error' => 'Invalid path'], 403);
            return;
        }

        $dir = dirname($absolutePath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $result = file_put_contents($absolutePath, $content);

        if ($result === false) {
            $this->json(['error' => 'Failed to write file'], 500);
            return;
        }

        $this->json([
            'success' => true,
            'path' => $relativePath,
            'size' => $result,
        ]);
    }

    /**
     * GET /api/files/tree — recursive directory tree.
     */
    public function tree(): void
    {
        $maxDepth = min(10, max(1, (int) ($_GET['depth'] ?? 5)));
        $tree = $this->buildTree($this->workspacePath, '', $maxDepth, 0);

        $this->json([
            'path' => '/',
            'tree' => $tree,
        ]);
    }

    /**
     * Validate that a path resolves within the workspace directory.
     */
    private function resolveSafePath(string $relativePath, bool $allowNew = false): ?string
    {
        $relativePath = ltrim($relativePath, '/');
        $candidate = $this->workspacePath . '/' . $relativePath;

        if ($relativePath === '') {
            return $this->workspacePath;
        }

        if ($allowNew) {
            // For new files, validate the parent directory
            $parent = dirname($candidate);
            $realParent = realpath($parent);

            if ($realParent === false) {
                // Parent doesn't exist yet — check if it would be within workspace
                $normalized = realpath($this->workspacePath);

                if ($normalized === false) {
                    return null;
                }

                // Simple prefix check on the non-resolved path
                if (!str_starts_with(
                    str_replace('\\', '/', $candidate),
                    str_replace('\\', '/', $normalized),
                )) {
                    return null;
                }

                return $candidate;
            }

            $normalizedWorkspace = realpath($this->workspacePath);

            if ($normalizedWorkspace === false) {
                return null;
            }

            if (!str_starts_with($realParent, $normalizedWorkspace)) {
                return null;
            }

            return $candidate;
        }

        $realPath = realpath($candidate);

        if ($realPath === false) {
            return null;
        }

        $normalizedWorkspace = realpath($this->workspacePath);

        if ($normalizedWorkspace === false) {
            return null;
        }

        if (!str_starts_with($realPath, $normalizedWorkspace)) {
            return null;
        }

        return $realPath;
    }

    /**
     * @return array<array{name: string, path: string, type: string, children?: array}>
     */
    private function buildTree(string $basePath, string $relativePath, int $maxDepth, int $currentDepth): array
    {
        if ($currentDepth >= $maxDepth) {
            return [];
        }

        $fullPath = $relativePath !== '' ? $basePath . '/' . $relativePath : $basePath;
        $entries = scandir($fullPath);

        if ($entries === false) {
            return [];
        }

        $items = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $entryRelative = $relativePath !== '' ? $relativePath . '/' . $entry : $entry;
            $entryFull = $fullPath . '/' . $entry;
            $isDir = is_dir($entryFull);

            $item = [
                'name' => $entry,
                'path' => $entryRelative,
                'type' => $isDir ? 'directory' : 'file',
            ];

            if ($isDir) {
                $item['children'] = $this->buildTree($basePath, $entryRelative, $maxDepth, $currentDepth + 1);
            } else {
                $item['extension'] = pathinfo($entry, PATHINFO_EXTENSION);
                $item['size'] = filesize($entryFull);
            }

            $items[] = $item;
        }

        // Sort: directories first, then alphabetical
        usort($items, function (array $a, array $b): int {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'directory' ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        return $items;
    }

    private function isBinary(string $path): bool
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $chunk = fread($handle, 512);
        fclose($handle);

        if ($chunk === false || $chunk === '') {
            return false;
        }

        // Check for null bytes
        return str_contains($chunk, "\0");
    }

    private function detectLanguage(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'php' => 'php',
            'json' => 'json',
            'js' => 'javascript',
            'ts' => 'typescript',
            'md', 'markdown' => 'markdown',
            'yml', 'yaml' => 'yaml',
            'xml' => 'xml',
            'html', 'htm' => 'html',
            'css' => 'css',
            'sql' => 'sql',
            'sh', 'bash' => 'shell',
            'env' => 'ini',
            'ini', 'cfg' => 'ini',
            'txt', 'log' => 'plaintext',
            'lock' => 'json',
            default => 'plaintext',
        };
    }

    private function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
