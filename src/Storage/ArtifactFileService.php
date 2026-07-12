<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Storage;

use CarmeloSantana\PathHelper\PathHelper;
use CoquiBot\Coqui\Contract\CoquiDefaults;

/**
 * Always-on filesystem backend for artifacts.
 *
 * Every artifact's content is a plain file under `artifacts/<type>/`. The DB
 * row is a pure index; this service owns path generation and file I/O. There
 * is no hybrid file/DB decision engine and no drift detection — the file is
 * always the source of truth, history comes from the user's own VCS.
 */
final class ArtifactFileService
{
    /** language → file extension for code artifacts. */
    private const array CODE_EXT = [
        'php' => 'php', 'python' => 'py', 'py' => 'py', 'javascript' => 'js',
        'js' => 'js', 'typescript' => 'ts', 'ts' => 'ts', 'bash' => 'sh',
        'sh' => 'sh', 'go' => 'go', 'rust' => 'rs', 'rs' => 'rs', 'ruby' => 'rb',
        'rb' => 'rb', 'java' => 'java', 'json' => 'json', 'yaml' => 'yaml',
        'yml' => 'yaml', 'sql' => 'sql', 'html' => 'html', 'css' => 'css',
    ];

    private readonly string $workspacePath;

    public function __construct(string $workspacePath)
    {
        $this->workspacePath = PathHelper::trimTrailingSlash($workspacePath);
    }

    /**
     * Workspace-relative canonical path: artifacts/<type>/<slug>-<shortid>.<ext>
     */
    public function pathFor(string $type, string $title, string $id, ?string $language = null): string
    {
        $slug = $this->slugify($title);
        $shortId = substr($id, 0, 8);
        $ext = $this->extensionFor($type, $language);

        return "artifacts/{$type}/{$slug}-{$shortId}.{$ext}";
    }

    /**
     * Write content to the workspace-relative path (creating directories),
     * and return the sha-256 hash of the content.
     */
    public function write(string $relativePath, string $content): string
    {
        $absolute = $this->toAbsolutePath($relativePath);
        $dir = dirname($absolute);

        if (!is_dir($dir) && !mkdir($dir, CoquiDefaults::DIRECTORY_MODE, true) && !is_dir($dir)) {
            throw new \RuntimeException("Unable to create artifact directory: {$dir}");
        }

        if (file_put_contents($absolute, $content) === false) {
            throw new \RuntimeException("Unable to write artifact file: {$relativePath}");
        }

        return $this->hash($content);
    }

    /**
     * Read the content at the workspace-relative path, or null if absent.
     */
    public function read(string $relativePath): ?string
    {
        $absolute = $this->toAbsolutePath($relativePath);

        if (!is_file($absolute)) {
            return null;
        }

        $content = file_get_contents($absolute);

        return $content !== false ? $content : null;
    }

    /**
     * Delete the file at the workspace-relative path. True if it is gone
     * (including when it never existed).
     */
    public function delete(string $relativePath): bool
    {
        $absolute = $this->toAbsolutePath($relativePath);

        if (!file_exists($absolute)) {
            return true;
        }

        return unlink($absolute);
    }

    /**
     * SHA-256 of a content string.
     */
    public function hash(string $content): string
    {
        return hash('sha256', $content);
    }

    private function extensionFor(string $type, ?string $language): string
    {
        if ($type === 'code') {
            $lang = $language !== null ? mb_strtolower(trim($language)) : '';

            return self::CODE_EXT[$lang] ?? 'txt';
        }

        if ($type === 'config') {
            $lang = $language !== null ? mb_strtolower(trim($language)) : '';

            if ($lang === 'yml' || $lang === 'yaml') {
                return 'yaml';
            }

            return $lang === 'json' ? 'json' : 'txt';
        }

        // plan, document, loop_output, and anything else → markdown
        return 'md';
    }

    private function toAbsolutePath(string $relativePath): string
    {
        return $this->workspacePath . '/' . ltrim($relativePath, '/');
    }

    private function slugify(string $title): string
    {
        $slug = mb_strtolower($title);
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        if (mb_strlen($slug) > 60) {
            $slug = rtrim(mb_substr($slug, 0, 60), '-');
        }

        return $slug !== '' ? $slug : 'artifact';
    }
}
