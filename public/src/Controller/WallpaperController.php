<?php

declare(strict_types=1);

namespace CoquiBot\Dashboard\Controller;

/**
 * JSON API endpoints for managing wallpaper images.
 *
 * Wallpapers are stored in the workspace's wallpapers/ directory.
 * Supports listing, uploading, serving, and deleting wallpaper images.
 */
final class WallpaperController
{
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'];
    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 MB

    private readonly string $wallpaperDir;

    public function __construct(
        string $workspacePath,
    ) {
        $this->wallpaperDir = rtrim($workspacePath, '/') . '/wallpapers';
    }

    /**
     * GET /api/wallpapers — list available wallpapers.
     */
    public function list(): void
    {
        $this->ensureDir();

        $wallpapers = [];
        $entries = scandir($this->wallpaperDir);

        if ($entries === false) {
            $this->json(['wallpapers' => []]);
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = $this->wallpaperDir . '/' . $entry;

            if (!is_file($fullPath)) {
                continue;
            }

            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));

            if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                continue;
            }

            $wallpapers[] = [
                'name' => $entry,
                'size' => filesize($fullPath),
                'modified' => date('c', filemtime($fullPath) ?: 0),
                'url' => '/api/wallpapers/' . rawurlencode($entry) . '/file',
                'thumbnail' => '/api/wallpapers/' . rawurlencode($entry) . '/file',
            ];
        }

        usort($wallpapers, fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        $this->json(['wallpapers' => $wallpapers]);
    }

    /**
     * POST /api/wallpapers — upload a wallpaper image.
     */
    public function upload(): void
    {
        $this->ensureDir();

        if (!isset($_FILES['wallpaper'])) {
            $this->json(['error' => 'No file uploaded. Use multipart/form-data with field name "wallpaper".'], 400);
            return;
        }

        $file = $_FILES['wallpaper'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $this->json(['error' => 'Upload error: ' . $this->uploadErrorMessage($file['error'])], 400);
            return;
        }

        if ($file['size'] > self::MAX_FILE_SIZE) {
            $this->json(['error' => 'File too large. Maximum size is 10 MB.'], 400);
            return;
        }

        $originalName = basename($file['name']);
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            $this->json([
                'error' => 'Invalid file type. Allowed: ' . implode(', ', self::ALLOWED_EXTENSIONS),
            ], 400);
            return;
        }

        // Sanitize filename
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
        $destName = $safeName . '.' . $ext;
        $destPath = $this->wallpaperDir . '/' . $destName;

        // Avoid overwriting — append number if exists
        $counter = 1;
        while (is_file($destPath)) {
            $destName = $safeName . '_' . $counter . '.' . $ext;
            $destPath = $this->wallpaperDir . '/' . $destName;
            $counter++;
        }

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            $this->json(['error' => 'Failed to save file'], 500);
            return;
        }

        chmod($destPath, 0644);

        $this->json([
            'success' => true,
            'wallpaper' => [
                'name' => $destName,
                'size' => filesize($destPath),
                'url' => '/api/wallpapers/' . rawurlencode($destName) . '/file',
            ],
        ], 201);
    }

    /**
     * GET /api/wallpapers/{name}/file — serve the wallpaper image.
     */
    public function serve(string $name): void
    {
        $name = basename(urldecode($name));
        $fullPath = $this->wallpaperDir . '/' . $name;

        if (!is_file($fullPath)) {
            http_response_code(404);
            echo 'Not found';
            return;
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'avif' => 'image/avif',
        ];

        $mime = $mimeTypes[$ext] ?? 'application/octet-stream';

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($fullPath));
        header('Cache-Control: public, max-age=86400');
        readfile($fullPath);
    }

    /**
     * DELETE /api/wallpapers/{name} — delete a wallpaper.
     */
    public function delete(string $name): void
    {
        $name = basename(urldecode($name));
        $fullPath = $this->wallpaperDir . '/' . $name;

        if (!is_file($fullPath)) {
            $this->json(['error' => 'Wallpaper not found'], 404);
            return;
        }

        if (!unlink($fullPath)) {
            $this->json(['error' => 'Failed to delete wallpaper'], 500);
            return;
        }

        $this->json(['success' => true]);
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->wallpaperDir)) {
            mkdir($this->wallpaperDir, 0755, true);
        }
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File too large',
            UPLOAD_ERR_PARTIAL => 'Partial upload',
            UPLOAD_ERR_NO_FILE => 'No file sent',
            UPLOAD_ERR_NO_TMP_DIR => 'Server misconfigured (no tmp dir)',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write to disk',
            default => 'Unknown error (' . $code . ')',
        };
    }

    private function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
