<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Storage;

use CoquiBot\Coqui\Config\PathHelper;

/**
 * Filesystem-backed content resolution for hybrid artifacts.
 *
 * Handles canonical path generation, file I/O, and drift detection
 * for artifacts whose content lives on disk as the source of truth.
 * The DB remains the index/coordination layer (stage, version history,
 * project/sprint linking), while the filesystem holds canonical content.
 *
 * Artifact types eligible for filesystem backing:
 * - plan, document: auto-generate canonical paths under project or session dirs
 * - code, config: use supplied filepath as canonical; DB-only if no path given
 *
 * Types that stay DB-only: loop_output, data, other, ephemeral drafts
 */
final class ArtifactFileService
{
    /** Artifact types that auto-generate canonical file paths when none is supplied. */
    private const array AUTO_PATH_TYPES = ['plan', 'document'];

    /** Artifact types eligible for filesystem backing when an explicit filepath is supplied. */
    private const array EXPLICIT_PATH_TYPES = ['code', 'config'];

    /** Types that are never filesystem-backed. */
    private const array DB_ONLY_TYPES = ['loop_output', 'data', 'other'];

    private readonly string $workspacePath;

    public function __construct(string $workspacePath)
    {
        $this->workspacePath = PathHelper::trimTrailingSlash($workspacePath);
    }

    /**
     * Determine whether an artifact should be filesystem-backed.
     */
    public function isFilesystemBacked(string $type, ?string $filepath, ?string $projectId): bool
    {
        if (in_array($type, self::DB_ONLY_TYPES, true)) {
            return false;
        }

        // Auto-path types get filesystem backing when linked to a project or when an explicit path is set
        if (in_array($type, self::AUTO_PATH_TYPES, true)) {
            return $projectId !== null && $projectId !== '' || $filepath !== null && $filepath !== '';
        }

        // code/config only get filesystem backing when an explicit filepath is supplied
        if (in_array($type, self::EXPLICIT_PATH_TYPES, true)) {
            return $filepath !== null && $filepath !== '';
        }

        return false;
    }

    /**
     * Resolve the canonical filesystem path for an artifact.
     *
     * For plan/document types without an explicit filepath, generates a stable
     * path under the project directory (workspace/projects/{dir}/artifacts/)
     * or returns null if no project context is available.
     *
     * For code/config types, returns the explicit filepath resolved against workspace.
     *
     * @return string|null Workspace-relative path, or null if not filesystem-backed.
     */
    public function resolveCanonicalPath(
        string $artifactId,
        string $type,
        string $title,
        ?string $filepath,
        ?string $projectId,
        ?string $projectDirectory,
    ): ?string {
        if (!$this->isFilesystemBacked($type, $filepath, $projectId)) {
            return null;
        }

        // Explicit filepath takes precedence for all types
        if ($filepath !== null && $filepath !== '') {
            return $filepath;
        }

        // Auto-generate path for plan/document under project directory
        if (in_array($type, self::AUTO_PATH_TYPES, true) && $projectDirectory !== null && $projectDirectory !== '') {
            $filename = $this->slugify($title);
            $shortId = substr($artifactId, 0, 8);

            return "projects/{$projectDirectory}/artifacts/{$filename}-{$shortId}.md";
        }

        return null;
    }

    /**
     * Write artifact content to its canonical file path.
     *
     * @param string $relativePath Workspace-relative path (from resolveCanonicalPath)
     * @param string $content The artifact content to write
     * @return bool True if the file was written successfully
     */
    public function writeContent(string $relativePath, string $content): bool
    {
        $absolutePath = $this->toAbsolutePath($relativePath);
        $dir = dirname($absolutePath);

        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                return false;
            }
        }

        return file_put_contents($absolutePath, $content) !== false;
    }

    /**
     * Read the current content from disk for a filesystem-backed artifact.
     *
     * @param string $relativePath Workspace-relative path
     * @return string|null File content, or null if the file does not exist
     */
    public function readContent(string $relativePath): ?string
    {
        $absolutePath = $this->toAbsolutePath($relativePath);

        if (!file_exists($absolutePath) || !is_file($absolutePath)) {
            return null;
        }

        $content = file_get_contents($absolutePath);

        return $content !== false ? $content : null;
    }

    /**
     * Check if the file exists on disk.
     */
    public function fileExists(string $relativePath): bool
    {
        return file_exists($this->toAbsolutePath($relativePath));
    }

    /**
     * Compute a SHA-256 hash of the file content for drift detection.
     *
     * @return string|null Hash, or null if file does not exist
     */
    public function computeFileHash(string $relativePath): ?string
    {
        $absolutePath = $this->toAbsolutePath($relativePath);

        if (!file_exists($absolutePath)) {
            return null;
        }

        $hash = hash_file('sha256', $absolutePath);

        return $hash !== false ? $hash : null;
    }

    /**
     * Compute a SHA-256 hash of a content string (for comparing with file hash).
     */
    public function computeContentHash(string $content): string
    {
        return hash('sha256', $content);
    }

    /**
     * Detect whether the file on disk has drifted from the DB snapshot.
     *
     * @return array{drifted: bool, file_hash: string|null, db_hash: string}
     */
    public function detectDrift(string $relativePath, string $dbContent): array
    {
        $dbHash = $this->computeContentHash($dbContent);
        $fileHash = $this->computeFileHash($relativePath);

        return [
            'drifted' => $fileHash !== null && $fileHash !== $dbHash,
            'file_hash' => $fileHash,
            'db_hash' => $dbHash,
        ];
    }

    /**
     * Delete the canonical file for an artifact.
     *
     * @return bool True if the file was deleted or did not exist
     */
    public function deleteFile(string $relativePath): bool
    {
        $absolutePath = $this->toAbsolutePath($relativePath);

        if (!file_exists($absolutePath)) {
            return true;
        }

        return unlink($absolutePath);
    }

    /**
     * Get the absolute filesystem path for a workspace-relative path.
     */
    public function toAbsolutePath(string $relativePath): string
    {
        return $this->workspacePath . '/' . ltrim($relativePath, '/');
    }

    /**
     * Convert a title to a filesystem-safe slug.
     */
    private function slugify(string $title): string
    {
        $slug = mb_strtolower($title);
        // Replace non-alphanumeric with hyphens
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        // Trim leading/trailing hyphens
        $slug = trim($slug, '-');
        // Cap length
        if (mb_strlen($slug) > 60) {
            $slug = mb_substr($slug, 0, 60);
            $slug = rtrim($slug, '-');
        }

        return $slug !== '' ? $slug : 'artifact';
    }
}
