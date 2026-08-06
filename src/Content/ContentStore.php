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
     * Store a blob and its bytes, addressed by the SHA-256 of the bytes.
     *
     * Content-addressed and idempotent: an identical blob (same sha256) is stored
     * once — a second `store()` returns the existing row's wire object (and its
     * original `content_ref`) rather than inserting a duplicate. Returns the wire
     * object exactly as it validates against `content.json`.
     *
     * @return array{content_ref: string, mime_type: string, size: int, sha256: string, created_at: string}
     */
    public function store(string $bytes, string $mimeType): array
    {
        $sha256 = hash('sha256', $bytes);

        $existing = $this->findBySha256($sha256);
        if ($existing !== null) {
            return self::toWire($existing);
        }

        $row = [
            'content_ref' => IdGenerator::hex(),
            'mime_type' => $mimeType,
            'size' => strlen($bytes),
            'sha256' => $sha256,
            'created_at' => Clock::nowUtc(),
        ];

        $stmt = $this->db->prepare(<<<SQL
            INSERT INTO content (content_ref, mime_type, size, sha256, created_at, bytes)
            VALUES (:content_ref, :mime_type, :size, :sha256, :created_at, :bytes)
        SQL);
        $stmt->bindValue(':content_ref', $row['content_ref'], PDO::PARAM_STR);
        $stmt->bindValue(':mime_type', $row['mime_type'], PDO::PARAM_STR);
        $stmt->bindValue(':size', $row['size'], PDO::PARAM_INT);
        $stmt->bindValue(':sha256', $row['sha256'], PDO::PARAM_STR);
        $stmt->bindValue(':created_at', $row['created_at'], PDO::PARAM_STR);
        $stmt->bindValue(':bytes', $bytes, PDO::PARAM_LOB);
        $stmt->execute();

        return self::toWire($row);
    }

    /**
     * Fetch a stored blob's metadata by `content_ref`, or null when absent.
     *
     * @return array{content_ref: string, mime_type: string, size: int, sha256: string, created_at: string}|null
     */
    public function get(string $contentRef): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT content_ref, mime_type, size, sha256, created_at FROM content WHERE content_ref = :ref',
        );
        $stmt->execute([':ref' => $contentRef]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::toWire($row) : null;
    }

    /**
     * Fetch a stored blob's raw bytes by `content_ref`, or null when absent.
     */
    public function readBytes(string $contentRef): ?string
    {
        $stmt = $this->db->prepare('SELECT bytes FROM content WHERE content_ref = :ref');
        $stmt->execute([':ref' => $contentRef]);
        $bytes = $stmt->fetchColumn();

        return is_string($bytes) ? $bytes : null;
    }

    /**
     * Fetch the raw persisted row for a blob addressed by its `sha256`, or null.
     *
     * Backs store()'s dedup. Returns the raw column map (not the wire projection)
     * so store() can re-emit it verbatim via {@see toWire()}.
     *
     * @return array<string, mixed>|null
     */
    public function findBySha256(string $sha256): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT content_ref, mime_type, size, sha256, created_at FROM content WHERE sha256 = :sha256',
        );
        $stmt->execute([':sha256' => $sha256]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
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
