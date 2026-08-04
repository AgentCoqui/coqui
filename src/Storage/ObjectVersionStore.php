<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Storage;

use CoquiBot\Coqui\Support\Clock;
use PDO;

/**
 * Optimistic-concurrency counter for file-authored Core objects.
 *
 * File-authored objects (personas, roles, loop definitions) keep their content
 * on disk, but CAP 0.5.0 requires each to expose a monotonic `version` token
 * that increments on every write so clients can guard mutations with `If-Match`.
 * This store holds that counter in the `object_versions` table, keyed by
 * (object_type, object_name), decoupled from the authoring files themselves.
 *
 * A `current()` of 0 means "no row yet". Callers serving a pre-existing
 * file-authored object with no row treat it as the implicit version 1, and the
 * first write lazily materializes the counter.
 */
final class ObjectVersionStore
{
    public function __construct(private readonly PDO $db) {}

    /**
     * The stored version, or 0 when no counter row exists yet.
     */
    public function current(string $type, string $name): int
    {
        $stmt = $this->db->prepare(
            'SELECT version FROM object_versions WHERE object_type = :type AND object_name = :name'
        );
        $stmt->execute([':type' => $type, ':name' => $name]);
        $version = $stmt->fetchColumn();

        return $version === false ? 0 : (int) $version;
    }

    /**
     * Seed the counter at version 1 for a newly created object.
     *
     * @throws \RuntimeException when a counter already exists for this object.
     */
    public function create(string $type, string $name): int
    {
        if ($this->current($type, $name) !== 0) {
            throw new \RuntimeException(
                sprintf('A version counter already exists for %s "%s".', $type, $name)
            );
        }

        $stmt = $this->db->prepare(<<<SQL
            INSERT INTO object_versions (object_type, object_name, version, updated_at)
            VALUES (:type, :name, 1, :updated_at)
        SQL);
        $stmt->execute([':type' => $type, ':name' => $name, ':updated_at' => Clock::nowUtc()]);

        return 1;
    }

    /**
     * Advance the counter and return the new version.
     *
     * An absent row is treated as the implicit version 1 (a pre-existing
     * file-authored object never touched through the versioned API), so the
     * first bump materializes the counter at 2.
     */
    public function bump(string $type, string $name): int
    {
        $current = $this->current($type, $name);
        $next = ($current === 0 ? 1 : $current) + 1;

        if ($current === 0) {
            $stmt = $this->db->prepare(<<<SQL
                INSERT INTO object_versions (object_type, object_name, version, updated_at)
                VALUES (:type, :name, :version, :updated_at)
            SQL);
        } else {
            $stmt = $this->db->prepare(<<<SQL
                UPDATE object_versions
                SET version = :version, updated_at = :updated_at
                WHERE object_type = :type AND object_name = :name
            SQL);
        }

        $stmt->execute([
            ':type' => $type,
            ':name' => $name,
            ':version' => $next,
            ':updated_at' => Clock::nowUtc(),
        ]);

        return $next;
    }

    /**
     * Remove the counter row for a deleted object.
     */
    public function delete(string $type, string $name): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM object_versions WHERE object_type = :type AND object_name = :name'
        );
        $stmt->execute([':type' => $type, ':name' => $name]);
    }
}
