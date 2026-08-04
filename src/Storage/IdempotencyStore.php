<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Storage;

use PDO;

/**
 * CAP 0.5.0 Idempotency-Key dedup store (CORE-53).
 *
 * Records the response a creator produced under an `(key, route, actor)` tuple so
 * a repeated request carrying the same `Idempotency-Key` replays the original
 * response instead of minting a second resource. The `idempotency_keys` table is
 * owned by SessionStorage::createTables(); this class is the sole reader/writer.
 */
final class IdempotencyStore
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Return the response recorded for this tuple, or null if none was seen.
     *
     * @return array{status: int, body: string}|null
     */
    public function lookup(string $key, string $route, string $actor): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT status, body FROM idempotency_keys
             WHERE "key" = :key AND route = :route AND actor = :actor
             LIMIT 1',
        );
        $stmt->execute([':key' => $key, ':route' => $route, ':actor' => $actor]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return ['status' => (int) $row['status'], 'body' => (string) $row['body']];
    }

    /**
     * Record the response produced by a creator for this tuple.
     *
     * INSERT OR IGNORE: the first writer wins under a concurrent-duplicate race,
     * so the recorded response stays stable for later replays.
     */
    public function record(string $key, string $route, string $actor, int $status, string $body): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO idempotency_keys ("key", route, actor, status, body, created_at)
             VALUES (:key, :route, :actor, :status, :body, :created_at)',
        );
        $stmt->execute([
            ':key' => $key,
            ':route' => $route,
            ':actor' => $actor,
            ':status' => $status,
            ':body' => $body,
            ':created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }
}
