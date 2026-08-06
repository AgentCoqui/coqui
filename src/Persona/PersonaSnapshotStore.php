<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Persona;

use CoquiBot\Coqui\Config\PersonaDiscovery;
use CoquiBot\Coqui\Config\PersonaParser;
use CoquiBot\Coqui\Support\Clock;
use PDO;
use stdClass;

/**
 * Projects file-authored personas into the `personas` index table and
 * serializes a stored row into a CAP 0.5.0 `persona.json` wire object.
 *
 * The `personas` table is an index/snapshot of the file-based authoring source
 * (PersonaDiscovery + PersonaParser), not the source of truth. Full API-serving
 * with optimistic-concurrency semantics arrives in a later phase; this store
 * exists so the Persona wire object is producible for conformance (CORE-1).
 */
final class PersonaSnapshotStore
{
    /**
     * Model echoed for a persona whose soul frontmatter declares none. ModelId
     * only requires a non-empty implementation-defined string; the real resolved
     * model is a runtime concern layered on later.
     */
    private const FALLBACK_MODEL = 'anthropic/claude-sonnet-4';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Serialize a stored `personas` row into a schema-valid CAP Persona object.
     *
     * Object-typed JSON columns (`avatar`, `preferences`) are decoded as objects,
     * never associative arrays: `json_encode([])` yields `[]`, which fails the
     * schema's `object` type, so an empty avatar must serialize as `{}`.
     * Array-typed columns (`allowed_roles`, `context`) decode as arrays.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function toWire(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'avatar' => self::decodeObject((string) $row['avatar']),
            'model' => (string) $row['model'],
            'allowed_roles' => json_decode((string) $row['allowed_roles'], true),
            'soul' => (string) $row['soul'],
            'backstory' => $row['backstory'] !== null ? (string) $row['backstory'] : null,
            'context' => $row['context'] !== null
                ? json_decode((string) $row['context'], true)
                : null,
            'preferences' => $row['preferences'] !== null
                ? self::decodeObject((string) $row['preferences'])
                : null,
            'version' => (int) $row['version'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * Decode a stored JSON object column as a stdClass. An empty JSON object
     * (or an empty `[]`) is normalized to an empty stdClass so it never
     * serializes back to a JSON array under an `object`-typed schema.
     */
    private static function decodeObject(string $json): stdClass
    {
        $decoded = json_decode($json, false);
        if ($decoded instanceof stdClass) {
            return $decoded;
        }

        return new stdClass();
    }

    /**
     * Upsert each file-authored persona into the `personas` index table.
     *
     * The row `id` is a stable, deterministic, file-driven opaque token derived
     * from the persona slug (`persona_<slug>`) rather than a random ULID, so a
     * fresh sync of the same files reproduces the same ids. `version` starts at 1
     * and bumps only when a persona's indexed content changes.
     */
    public function syncFromFiles(PersonaDiscovery $discovery, PersonaParser $parser): void
    {
        foreach ($discovery->discoverAll() as $slug => $persona) {
            $soulPath = $persona['path'] . '/soul.md';
            $parsed = $parser->readFile($soulPath);
            $metadata = $parsed['metadata'];

            $model = is_string($metadata['model'] ?? null) && trim($metadata['model']) !== ''
                ? trim($metadata['model'])
                : self::FALLBACK_MODEL;

            $avatar = new stdClass();
            if (is_string($metadata['tint'] ?? null) && $metadata['tint'] !== '') {
                $avatar->tint = $metadata['tint'];
            }

            $this->upsert(
                id: 'persona_' . $slug,
                name: $persona['display_name'],
                avatar: json_encode($avatar, JSON_THROW_ON_ERROR),
                model: $model,
                allowedRoles: json_encode(['orchestrator'], JSON_THROW_ON_ERROR),
                soul: $parsed['body'],
            );
        }
    }

    /**
     * Insert a new persona row, or bump its version when indexed content drifted.
     */
    private function upsert(
        string $id,
        string $name,
        string $avatar,
        string $model,
        string $allowedRoles,
        string $soul,
    ): void {
        $now = Clock::nowUtc();

        $existing = $this->db->prepare(
            'SELECT avatar, model, allowed_roles, soul, version FROM personas WHERE id = :id'
        );
        $existing->execute(['id' => $id]);
        $current = $existing->fetch(PDO::FETCH_ASSOC);

        if ($current === false) {
            $insert = $this->db->prepare(<<<SQL
                INSERT INTO personas (id, name, avatar, model, allowed_roles, soul, version, created_at, updated_at)
                VALUES (:id, :name, :avatar, :model, :allowed_roles, :soul, 1, :created_at, :updated_at)
            SQL);
            $insert->execute([
                'id' => $id,
                'name' => $name,
                'avatar' => $avatar,
                'model' => $model,
                'allowed_roles' => $allowedRoles,
                'soul' => $soul,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        $unchanged = (string) $current['avatar'] === $avatar
            && (string) $current['model'] === $model
            && (string) $current['allowed_roles'] === $allowedRoles
            && (string) $current['soul'] === $soul;
        if ($unchanged) {
            return;
        }

        $update = $this->db->prepare(<<<SQL
            UPDATE personas
            SET name = :name, avatar = :avatar, model = :model, allowed_roles = :allowed_roles,
                soul = :soul, version = version + 1, updated_at = :updated_at
            WHERE id = :id
        SQL);
        $update->execute([
            'name' => $name,
            'avatar' => $avatar,
            'model' => $model,
            'allowed_roles' => $allowedRoles,
            'soul' => $soul,
            'updated_at' => $now,
            'id' => $id,
        ]);
    }
}
