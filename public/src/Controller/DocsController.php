<?php

declare(strict_types=1);

namespace CoquiBot\Dashboard\Controller;

/**
 * JSON API endpoints for project documentation (docs/ directory).
 *
 * Reads markdown files from the project's docs/ directory (read-only).
 */
final class DocsController
{
    public function __construct(
        private readonly string $docsPath,
    ) {}

    /**
     * GET /api/docs — list available documentation files.
     */
    public function list(): void
    {
        if (!is_dir($this->docsPath)) {
            $this->json(['files' => []]);
            return;
        }

        $files = [];
        $entries = scandir($this->docsPath);

        if ($entries === false) {
            $this->json(['files' => []]);
            return;
        }

        // Also include README.md from project root if it exists
        $projectRoot = dirname($this->docsPath);
        $readme = $projectRoot . '/README.md';

        if (is_file($readme)) {
            $files[] = [
                'name' => 'README.md',
                'size' => filesize($readme),
                'modified' => date('c', filemtime($readme) ?: 0),
            ];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = $this->docsPath . '/' . $entry;

            if (!is_file($fullPath)) {
                continue;
            }

            // Only serve markdown files
            if (!str_ends_with(strtolower($entry), '.md')) {
                continue;
            }

            $files[] = [
                'name' => $entry,
                'size' => filesize($fullPath),
                'modified' => date('c', filemtime($fullPath) ?: 0),
            ];
        }

        // Sort alphabetically, keeping README first
        usort($files, function (array $a, array $b): int {
            if ($a['name'] === 'README.md') {
                return -1;
            }
            if ($b['name'] === 'README.md') {
                return 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        $this->json(['files' => $files]);
    }

    /**
     * GET /api/docs/{name} — read a documentation file.
     */
    public function read(string $name): void
    {
        // Sanitize: only allow simple filenames, no path traversal
        if (preg_match('/[\/\\\\]/', $name) || str_contains($name, '..')) {
            $this->json(['error' => 'Invalid filename'], 400);
            return;
        }

        // README.md lives in project root, others in docs/
        if ($name === 'README.md') {
            $fullPath = dirname($this->docsPath) . '/README.md';
        } else {
            $fullPath = $this->docsPath . '/' . $name;
        }

        if (!is_file($fullPath)) {
            $this->json(['error' => 'File not found'], 404);
            return;
        }

        $content = file_get_contents($fullPath);

        if ($content === false) {
            $this->json(['error' => 'Failed to read file'], 500);
            return;
        }

        $this->json([
            'name' => $name,
            'content' => $content,
            'size' => strlen($content),
            'modified' => date('c', filemtime($fullPath) ?: 0),
        ]);
    }

    private function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
