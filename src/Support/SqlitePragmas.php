<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Support;

use CoquiBot\Coqui\Contract\CoquiDefaults;
use PDO;

/**
 * Applies Coqui's standard SQLite tuning pragmas to a PDO connection.
 *
 * Centralizes the WAL + foreign-keys + synchronous + cache + temp-store
 * pragma set that every Coqui SQLite database shares, so the values live
 * in one place instead of being duplicated across each store and factory.
 */
final class SqlitePragmas
{
    /**
     * Apply the standard pragma set to a SQLite PDO connection.
     *
     * @param int $cacheSizeKb Page cache size in KiB (negative per SQLite convention).
     *                         Defaults to the core-database size; toolkit databases
     *                         pass the smaller toolkit size.
     */
    public static function applyTo(PDO $db, int $cacheSizeKb = CoquiDefaults::SQLITE_CACHE_SIZE_KB): void
    {
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA foreign_keys=ON');
        $db->exec('PRAGMA synchronous=NORMAL');
        $db->exec('PRAGMA cache_size=' . $cacheSizeKb);
        $db->exec('PRAGMA temp_store=MEMORY');
    }
}
