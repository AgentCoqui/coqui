<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Support;

use PDO;

/**
 * Small SQLite schema-migration helpers shared by the storage layer.
 *
 * Centralizes the "add a column only if it does not already exist" idiom
 * that each store previously reimplemented with slight stylistic drift.
 */
final class SchemaHelper
{
    /**
     * Add a column to a table if it is not already present.
     *
     * @param string $definition Column type/definition, e.g. "TEXT" or "INTEGER DEFAULT 0".
     */
    public static function addColumnIfMissing(PDO $db, string $table, string $column, string $definition): void
    {
        $stmt = $db->query("PRAGMA table_info({$table})");
        if ($stmt === false) {
            return;
        }

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $existing) {
            if (($existing['name'] ?? null) === $column) {
                return;
            }
        }

        $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }
}
