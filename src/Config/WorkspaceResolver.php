<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CarmeloSantana\PathHelper\PathHelper;

use CarmeloSantana\PHPAgents\Contract\ConfigInterface;

/**
 * Resolves the workspace directory from openclaw.json config.
 *
 * The workspace is the sandboxed directory where Coqui can read/write files.
 * Supports relative paths (resolved against project root), absolute paths,
 * and ~ expansion for home directory paths.
 *
 * Default: `~/.coqui/.workspace`. Override via `agents.defaults.workspace`
 * in openclaw.json or the `--workspace` CLI flag.
 */
final readonly class WorkspaceResolver
{
    private const DEFAULT_WORKSPACE = '~/.coqui/.workspace';

    public function __construct(
        private ConfigInterface $config,
        private string $projectRoot,
        private ?string $override = null,
    ) {}

    /**
     * Resolve the workspace path to an absolute directory.
     *
     * Creates the directory (and a .gitkeep) if it doesn't exist.
     * A non-null $override (e.g. from --workspace CLI flag or COQUI_WORKSPACE env)
     * takes precedence over both the config file and the default.
     */
    public function resolve(): string
    {
        if ($this->override !== null && $this->override !== '') {
            $path = $this->expandPath($this->override);
            $this->ensureDirectory($path);
            return $path;
        }

        $configured = $this->config->get('agents.defaults.workspace', self::DEFAULT_WORKSPACE);

        if (!is_string($configured) || $configured === '') {
            $configured = self::DEFAULT_WORKSPACE;
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
            $home = HomeDirectory::resolve();

            return $home . substr($path, 1);
        }

        // Absolute path — return as-is (Unix / and Windows C:\ or D:/)
        if (str_starts_with($path, '/') || (strlen($path) >= 3 && ctype_alpha($path[0]) && $path[1] === ':' && ($path[2] === '\\' || $path[2] === '/'))) {
            return $path;
        }

        // Relative path — resolve against project root
        return PathHelper::trimTrailingSlash($this->projectRoot) . '/' . $path;
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
