<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Content;

use CoquiBot\Coqui\Support\Clock;
use CoquiBot\Coqui\Support\IdGenerator;
use PDO;

/**
 * Content-addressed blob index (CAP 0.5.0 `content.json`).
 *
 * Persists immutable metadata for a byte blob referenced by attachments and
 * artifacts. `content_ref` is the opaque handle the spec never interprets;
 * `sha256` is the lowercase-hex digest that enables dedup and integrity.
 *
 * Shares the caller's PDO connection (the `content` table lives in
 * SessionStorage's schema) so it can be composed without a second connection.
 */
final class ContentStore
{
    public function __construct(
        private readonly PDO $db,
    ) {}

    /**
     * Store a blob's metadata, addressed by the SHA-256 of its bytes.
     *
     * The bytes themselves are not persisted here — this indexes the blob so
     * attachments/artifacts can reference it by `content_ref`. Returns the wire
     * object exactly as it validates against `content.json`.
     *
     * @return array{content_ref: string, mime_type: string, size: int, sha256: string, created_at: string}
     */
    public function store(string $bytes, string $mimeType): array
    {
        $row = [
            'content_ref' => IdGenerator::hex(),
            'mime_type' => $mimeType,
            'size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'created_at' => Clock::nowUtc(),
        ];

        $stmt = $this->db->prepare(<<<SQL
            INSERT INTO content (content_ref, mime_type, size, sha256, created_at)
            VALUES (:content_ref, :mime_type, :size, :sha256, :created_at)
        SQL);
        $stmt->execute($row);

        return self::toWire($row);
    }

    /**
     * Project a persisted `content` row onto the `content.json` wire shape.
     *
     * Emits only the five schema-declared properties, keeping the object clean
     * under `additionalProperties: false`. `size` is coerced back to int (SQLite
     * hands integer columns back as strings via PDO).
     *
     * @param array<string, mixed> $row
     * @return array{content_ref: string, mime_type: string, size: int, sha256: string, created_at: string}
     */
    public static function toWire(array $row): array
    {
        return [
            'content_ref' => (string) $row['content_ref'],
            'mime_type' => (string) $row['mime_type'],
            'size' => (int) $row['size'],
            'sha256' => (string) $row['sha256'],
            'created_at' => (string) $row['created_at'],
        ];
    }
}
