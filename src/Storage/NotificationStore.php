<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Storage;

use PDO;

/**
 * SQLite-backed notification persistence.
 *
 * Notifications are session-scoped transient messages that inform users about
 * background task completions, loop milestones, and other async events. Each
 * notification has a class — informational (user visibility only) or actionable
 * (eligible for autonomous continuation via claim semantics).
 *
 * Shares the PDO connection from SessionStorage (coqui.db). Fingerprint-based
 * deduplication prevents the same event from producing multiple unread notices.
 */
final class NotificationStore
{
    public function __construct(
        private readonly PDO $db,
    ) {
        $this->createTables();
        $this->createIndexes();
    }

    private function createTables(): void
    {
        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS notifications (
                id TEXT PRIMARY KEY,
                session_id TEXT NOT NULL,
                class TEXT NOT NULL DEFAULT 'informational' CHECK(class IN ('informational', 'actionable')),
                kind TEXT NOT NULL,
                source_type TEXT,
                source_id TEXT,
                title TEXT NOT NULL,
                message TEXT,
                metadata TEXT,
                priority TEXT NOT NULL DEFAULT 'normal' CHECK(priority IN ('low', 'normal', 'high', 'urgent')),
                fingerprint TEXT,
                created_at TEXT NOT NULL,
                read_at TEXT,
                expires_at TEXT,
                claim_status TEXT CHECK(claim_status IN ('pending', 'claimed', 'completed', 'failed')),
                claimed_by TEXT,
                claimed_at TEXT,
                FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
            )
        SQL);
    }

    private function createIndexes(): void
    {
        $this->db->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_notifications_session_read
                ON notifications(session_id, read_at, created_at)
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_notifications_session_class_claim
                ON notifications(session_id, class, claim_status)
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_notifications_created
                ON notifications(created_at)
        SQL);

        // Partial unique index: at most one unread notification per fingerprint per session.
        // Prevents duplicate producer writes from fanning out.
        $this->db->exec(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS idx_notifications_fingerprint_unread
                ON notifications(session_id, fingerprint)
                WHERE read_at IS NULL AND fingerprint IS NOT NULL
        SQL);
    }

    /**
     * Create a notification.
     *
     * Returns the notification ID, or null if a duplicate fingerprint blocked insertion.
     *
     * @param array<string, mixed>|null $metadata
     */
    public function create(
        string $sessionId,
        string $kind,
        string $title,
        ?string $message = null,
        string $class = 'informational',
        string $priority = 'normal',
        ?string $fingerprint = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?array $metadata = null,
        ?string $expiresAt = null,
    ): ?string {
        $id = bin2hex(random_bytes(12));
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $metadataJson = $metadata !== null ? json_encode($metadata, JSON_THROW_ON_ERROR) : null;

        // For actionable notifications, set initial claim status
        $claimStatus = $class === 'actionable' ? 'pending' : null;

        $stmt = $this->db->prepare(<<<'SQL'
            INSERT OR IGNORE INTO notifications
                (id, session_id, class, kind, source_type, source_id, title, message,
                 metadata, priority, fingerprint, created_at, expires_at, claim_status)
            VALUES
                (:id, :session_id, :class, :kind, :source_type, :source_id, :title, :message,
                 :metadata, :priority, :fingerprint, :created_at, :expires_at, :claim_status)
        SQL);

        $stmt->execute([
            ':id' => $id,
            ':session_id' => $sessionId,
            ':class' => $class,
            ':kind' => $kind,
            ':source_type' => $sourceType,
            ':source_id' => $sourceId,
            ':title' => $title,
            ':message' => $message,
            ':metadata' => $metadataJson,
            ':priority' => $priority,
            ':fingerprint' => $fingerprint,
            ':created_at' => $now,
            ':expires_at' => $expiresAt,
            ':claim_status' => $claimStatus,
        ]);

        // INSERT OR IGNORE returns 0 rows when fingerprint conflicts
        return $stmt->rowCount() > 0 ? $id : null;
    }

    /**
     * Get unread informational notifications for a session.
     *
     * @return list<array<string, mixed>>
     */
    public function getUnreadInformational(string $sessionId, int $limit = 10): array
    {
        $stmt = $this->db->prepare(<<<'SQL'
            SELECT id, class, kind, source_type, source_id, title, message, metadata, priority, fingerprint, created_at
            FROM notifications
            WHERE session_id = :session_id
                AND class = 'informational'
                AND read_at IS NULL
                AND (expires_at IS NULL OR expires_at > :now)
            ORDER BY
                CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END,
                created_at ASC
            LIMIT :limit
        SQL);

        $stmt->execute([
            ':session_id' => $sessionId,
            ':now' => gmdate('Y-m-d\TH:i:s\Z'),
            ':limit' => $limit,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Atomically snapshot unread informational notifications and mark them as read.
     *
     * Returns the snapshot of notifications that were marked. The caller receives
     * the data before the clear, ensuring no notifications are lost between read
     * and clear operations.
     *
     * @return list<array<string, mixed>>
     */
    public function snapshotAndClear(string $sessionId, int $limit = 10): array
    {
        $this->db->beginTransaction();

        try {
            $notifications = $this->getUnreadInformational($sessionId, $limit);

            if ($notifications !== []) {
                $ids = array_column($notifications, 'id');
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $now = gmdate('Y-m-d\TH:i:s\Z');

                $stmt = $this->db->prepare(
                    "UPDATE notifications SET read_at = ? WHERE id IN ({$placeholders})",
                );
                $stmt->execute([$now, ...$ids]);
            }

            $this->db->commit();

            return $notifications;
        } catch (\Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }
    }

    /**
     * Mark specific notifications as read.
     *
     * @param list<string> $ids
     */
    public function markRead(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $stmt = $this->db->prepare(
            "UPDATE notifications SET read_at = ? WHERE id IN ({$placeholders})",
        );
        $stmt->execute([$now, ...$ids]);
    }

    /**
     * Mark all unread informational notifications for a session as read.
     */
    public function markAllRead(string $sessionId): int
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $stmt = $this->db->prepare(<<<'SQL'
            UPDATE notifications
            SET read_at = :now
            WHERE session_id = :session_id
                AND class = 'informational'
                AND read_at IS NULL
        SQL);

        $stmt->execute([
            ':now' => $now,
            ':session_id' => $sessionId,
        ]);

        return $stmt->rowCount();
    }

    /**
     * Count unread informational notifications for a session.
     */
    public function countUnread(string $sessionId): int
    {
        $stmt = $this->db->prepare(<<<'SQL'
            SELECT COUNT(*) FROM notifications
            WHERE session_id = :session_id
                AND class = 'informational'
                AND read_at IS NULL
                AND (expires_at IS NULL OR expires_at > :now)
        SQL);

        $stmt->execute([
            ':session_id' => $sessionId,
            ':now' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Check whether a notification with the given fingerprint exists (unread) for a session.
     */
    public function existsByFingerprint(string $sessionId, string $fingerprint): bool
    {
        $stmt = $this->db->prepare(<<<'SQL'
            SELECT 1 FROM notifications
            WHERE session_id = :session_id
                AND fingerprint = :fingerprint
            LIMIT 1
        SQL);

        $stmt->execute([
            ':session_id' => $sessionId,
            ':fingerprint' => $fingerprint,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    // ──────────────────────────────────────────────
    //  Actionable Notice Claim Semantics
    // ──────────────────────────────────────────────

    /**
     * Get unclaimed actionable notifications eligible for automation.
     *
     * @param list<string>|null $kinds If provided, only return these notification kinds.
     * @return list<array<string, mixed>>
     */
    public function getUnclaimedActionable(string $sessionId, ?array $kinds = null, int $limit = 10): array
    {
        $kindFilter = '';
        $params = [
            ':session_id' => $sessionId,
            ':now' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        if ($kinds !== null && $kinds !== []) {
            $placeholders = [];
            foreach ($kinds as $i => $kind) {
                $key = ":kind_{$i}";
                $placeholders[] = $key;
                $params[$key] = $kind;
            }
            $kindFilter = 'AND kind IN (' . implode(',', $placeholders) . ')';
        }

        $stmt = $this->db->prepare(<<<SQL
            SELECT id, session_id, class, kind, source_type, source_id, title, message, metadata,
                   priority, fingerprint, created_at
            FROM notifications
            WHERE session_id = :session_id
                AND class = 'actionable'
                AND claim_status = 'pending'
                AND (expires_at IS NULL OR expires_at > :now)
                {$kindFilter}
            ORDER BY
                CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END,
                created_at ASC
            LIMIT {$limit}
        SQL);

        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Atomically claim an actionable notification for processing.
     *
     * Returns true if the claim succeeded (notification was still pending).
     * Uses optimistic locking — only updates if claim_status is still 'pending'.
     */
    public function claim(string $id, string $claimedBy): bool
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $stmt = $this->db->prepare(<<<'SQL'
            UPDATE notifications
            SET claim_status = 'claimed',
                claimed_by = :claimed_by,
                claimed_at = :claimed_at,
                read_at = COALESCE(read_at, :read_at)
            WHERE id = :id AND claim_status = 'pending'
        SQL);

        $stmt->execute([
            ':id' => $id,
            ':claimed_by' => $claimedBy,
            ':claimed_at' => $now,
            ':read_at' => $now,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Mark a claimed notification as completed.
     */
    public function completeClaim(string $id): void
    {
        $stmt = $this->db->prepare(<<<'SQL'
            UPDATE notifications
            SET claim_status = 'completed'
            WHERE id = :id AND claim_status = 'claimed'
        SQL);

        $stmt->execute([':id' => $id]);
    }

    /**
     * Mark a claimed notification as failed.
     */
    public function failClaim(string $id): void
    {
        $stmt = $this->db->prepare(<<<'SQL'
            UPDATE notifications
            SET claim_status = 'failed'
            WHERE id = :id AND claim_status = 'claimed'
        SQL);

        $stmt->execute([':id' => $id]);
    }

    // ──────────────────────────────────────────────
    //  Maintenance
    // ──────────────────────────────────────────────

    /**
     * Prune old notifications.
     *
     * Removes read informational notifications older than the informational retention
     * period, and all actionable notifications (regardless of claim state) older than
     * the actionable retention period.
     */
    public function prune(int $informationalRetentionHours = 24, int $actionableRetentionHours = 72): int
    {
        $informationalCutoff = gmdate('Y-m-d\TH:i:s\Z', time() - ($informationalRetentionHours * 3600));
        $actionableCutoff = gmdate('Y-m-d\TH:i:s\Z', time() - ($actionableRetentionHours * 3600));

        // Prune read informational notifications past retention
        $stmt1 = $this->db->prepare(<<<'SQL'
            DELETE FROM notifications
            WHERE class = 'informational'
                AND read_at IS NOT NULL
                AND created_at < :cutoff
        SQL);
        $stmt1->execute([':cutoff' => $informationalCutoff]);
        $count = $stmt1->rowCount();

        // Prune expired informational (regardless of read state)
        $stmt2 = $this->db->prepare(<<<'SQL'
            DELETE FROM notifications
            WHERE class = 'informational'
                AND expires_at IS NOT NULL
                AND expires_at < :now
        SQL);
        $stmt2->execute([':now' => gmdate('Y-m-d\TH:i:s\Z')]);
        $count += $stmt2->rowCount();

        // Prune old actionable notifications (completed or failed)
        $stmt3 = $this->db->prepare(<<<'SQL'
            DELETE FROM notifications
            WHERE class = 'actionable'
                AND claim_status IN ('completed', 'failed')
                AND created_at < :cutoff
        SQL);
        $stmt3->execute([':cutoff' => $actionableCutoff]);
        $count += $stmt3->rowCount();

        // Prune expired actionable
        $stmt4 = $this->db->prepare(<<<'SQL'
            DELETE FROM notifications
            WHERE class = 'actionable'
                AND expires_at IS NOT NULL
                AND expires_at < :now
        SQL);
        $stmt4->execute([':now' => gmdate('Y-m-d\TH:i:s\Z')]);
        $count += $stmt4->rowCount();

        return $count;
    }

    /**
     * Get recent notifications for a session (all classes, all states).
     *
     * @return list<array<string, mixed>>
     */
    public function getRecent(string $sessionId, int $limit = 20): array
    {
        $stmt = $this->db->prepare(<<<'SQL'
            SELECT *
            FROM notifications
            WHERE session_id = :session_id
            ORDER BY created_at DESC
            LIMIT :limit
        SQL);

        $stmt->execute([
            ':session_id' => $sessionId,
            ':limit' => $limit,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Delete a notification by ID.
     */
    public function delete(string $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM notifications WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Get a single notification by ID.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM notifications WHERE id = :id');
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }
}
