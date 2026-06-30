<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Support;

use CoquiBot\Coqui\Contract\CoquiDefaults;

/**
 * Factory for toolkit-scoped SQLite databases.
 *
 * Toolkits use this to create and access their own databases within the
 * workspace. Each database is stored at `.workspace/{name}.db` with WAL
 * mode and standard safety pragmas. Toolkits MUST NOT modify the core
 * database — they create and manage their own.
 */
final readonly class ToolkitDatabaseFactory
{
    public function __construct(
        private string $workspacePath,
    ) {}

    /**
     * Open (or create) a toolkit-owned SQLite database.
     *
     * The database is stored at `{workspacePath}/{name}.db`.
     * WAL mode, foreign keys, and normal synchronous mode are enabled.
     *
     * @param string $name Database name (alphanumeric, hyphens, underscores only)
     * @throws \InvalidArgumentException If name contains invalid characters
     */
    public function open(string $name): \PDO
    {
        $this->validateName($name);

        $dir = $this->workspacePath;
        if (!is_dir($dir)) {
            mkdir($dir, CoquiDefaults::DIRECTORY_MODE, true);
        }

        $dbPath = $dir . '/' . $name . '.db';

        $pdo = new \PDO("sqlite:{$dbPath}");
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        SqlitePragmas::applyTo($pdo, CoquiDefaults::SQLITE_TOOLKIT_CACHE_SIZE_KB);

        return $pdo;
    }

    private function validateName(string $name): void
    {
        if ($name === '') {
            throw new \InvalidArgumentException('Database name must not be empty.');
        }

        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/', $name)) {
            throw new \InvalidArgumentException(
                sprintf('Database name "%s" contains invalid characters. Use only alphanumeric, hyphens, and underscores.', $name),
            );
        }

        if (str_contains($name, '..') || str_contains($name, '/') || str_contains($name, '\\')) {
            throw new \InvalidArgumentException('Database name must not contain path traversal sequences.');
        }
    }
}
