<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CarmeloSantana\PHPAgents\Contract\ConfigInterface;

/**
 * Resolves the workspace directory from openclaw.json config.
 *
 * The workspace is the sandboxed directory where Coqui can read/write files.
 * Supports relative paths (resolved against project root), absolute paths,
 * and ~ expansion for home directory paths.
 *
 * Default behavior (when no explicit workspace is configured):
 *   1. If a `.workspace/` directory exists in the project root, use it (dev mode).
 *   2. Otherwise, default to `~/.coqui/workspace` inside the install directory.
 *
 * This keeps all Coqui data under `~/.coqui/` while preserving the
 * developer workflow where `.workspace/` lives alongside the project.
 */
final readonly class WorkspaceResolver
{
    private const DEFAULT_WORKSPACE = '~/.coqui/workspace';

    public function __construct(
        private ConfigInterface $config,
        private string $projectRoot,
    ) {}

    /**
     * Resolve the workspace path to an absolute directory.
     *
     * Creates the directory (and a .gitkeep) if it doesn't exist.
     */
    public function resolve(): string
    {
        $configured = $this->config->get('agents.defaults.workspace', self::DEFAULT_WORKSPACE);

        if (!is_string($configured) || $configured === '') {
            $configured = self::DEFAULT_WORKSPACE;
        }

        // When using the default, check for an existing local .workspace/ first.
        // This supports dev environments where the workspace lives alongside the project.
        if ($configured === self::DEFAULT_WORKSPACE) {
            $localWorkspace = rtrim($this->projectRoot, '/') . '/.workspace';
            if (is_dir($localWorkspace)) {
                $this->ensureDirectory($localWorkspace);
                return $localWorkspace;
            }
        }

        $path = $this->expandPath($configured);

        $this->ensureDirectory($path);

        return $path;
    }

    /**
     * Expand a path string to an absolute path.
     *
     * Handles ~ (home dir), relative paths (resolved against project root),
     * and absolute paths (returned as-is).
     */
    private function expandPath(string $path): string
    {
        // Expand ~ to home directory
        if (str_starts_with($path, '~/') || $path === '~') {
            $home = $_SERVER['HOME'] ?? $_ENV['HOME'] ?? '';
            if ($home === '') {
                $home = posix_getpwuid(posix_getuid())['dir'] ?? '/tmp';
            }

            return $home . substr($path, 1);
        }

        // Absolute path — return as-is
        if (str_starts_with($path, '/')) {
            return $path;
        }

        // Relative path — resolve against project root
        return rtrim($this->projectRoot, '/') . '/' . $path;
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        // Create .gitkeep so the directory can be committed (but contents ignored)
        $gitkeep = $path . '/.gitkeep';
        if (!file_exists($gitkeep)) {
            file_put_contents($gitkeep, '');
        }
    }
}
