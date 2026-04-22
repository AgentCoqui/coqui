<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Storage;

use PDO;

/**
 * SQLite-backed persistence for webhook subscriptions and delivery logs.
 *
 * Webhook subscriptions map incoming HTTP requests to background task
 * creation. Each subscription defines a source type (github, slack, generic),
 * an HMAC signing secret, a prompt template, and an optional event filter.
 *
 * Delivery logs provide an audit trail for debugging webhook integrations.
 */
final class WebhookStore
{
    private const int DELIVERY_RETENTION_DAYS = 7;

    /** Supported webhook source types for signature verification. */
    public const array VALID_SOURCES = ['generic', 'github', 'slack'];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->createTables();
        $this->migrate();
    }

    private function createTables(): void
    {
        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS webhook_subscriptions (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL UNIQUE,
                description TEXT,
                source TEXT NOT NULL DEFAULT 'generic',
                secret TEXT NOT NULL,
                prompt_template TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'orchestrator',
                profile TEXT,
                max_iterations INTEGER NOT NULL DEFAULT 48,
                enabled INTEGER NOT NULL DEFAULT 1,
                event_filter TEXT,
                created_by TEXT,
                last_triggered_at TEXT,
                trigger_count INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS idx_webhook_subscriptions_name
                ON webhook_subscriptions(name)
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS webhook_deliveries (
                id TEXT PRIMARY KEY,
                webhook_id TEXT NOT NULL,
                event_type TEXT,
                payload_summary TEXT,
                task_id TEXT,
                status TEXT NOT NULL,
                source_ip TEXT,
                created_at TEXT NOT NULL,
                FOREIGN KEY (webhook_id) REFERENCES webhook_subscriptions(id) ON DELETE CASCADE
            )
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_webhook_deliveries_webhook
                ON webhook_deliveries(webhook_id)
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_webhook_deliveries_created
                ON webhook_deliveries(created_at)
        SQL);
    }

    private function migrate(): void
    {
        $stmt = $this->db->query('PRAGMA table_info(webhook_subscriptions)');
        $columns = $stmt !== false ? array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name') : [];

        if (!in_array('profile', $columns, true)) {
            $this->db->exec('ALTER TABLE webhook_subscriptions ADD COLUMN profile TEXT');
        }
    }

    // =========================================================================
    // Subscription CRUD
    // =========================================================================

    /**
     * Create a new webhook subscription.
     *
     * Generates a signing secret automatically if not provided.
     */
    public function create(
        string $name,
        string $promptTemplate,
        string $source = 'generic',
        string $role = 'orchestrator',
        ?string $profile = null,
        int $maxIterations = 48,
        ?string $description = null,
        ?string $secret = null,
        ?string $eventFilter = null,
        ?string $createdBy = null,
    ): string {
        $id = bin2hex(random_bytes(16));
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $secret ??= bin2hex(random_bytes(32));

        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO webhook_subscriptions
                (id, name, description, source, secret, prompt_template, role,
                 profile, max_iterations, enabled, event_filter, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)
        SQL);

        $stmt->execute([
            $id,
            $name,
            $description,
            $source,
            $secret,
            $promptTemplate,
            $role,
            $profile,
            $maxIterations,
            $eventFilter,
            $createdBy,
            $now,
            $now,
        ]);

        return $id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM webhook_subscriptions WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getByName(string $name): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM webhook_subscriptions WHERE name = ?');
        $stmt->execute([$name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Update a webhook subscription. Only non-null parameters are applied.
     */
    public function update(
        string $id,
        ?string $name = null,
        ?string $description = null,
        ?string $source = null,
        ?string $promptTemplate = null,
        ?string $role = null,
        ?string $profile = null,
        ?int $maxIterations = null,
        ?bool $enabled = null,
        ?string $eventFilter = null,
    ): bool {
        $webhook = $this->get($id);
        if ($webhook === null) {
            return false;
        }

        $sets = ['updated_at = ?'];
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $params = [$now];

        if ($name !== null) {
            $sets[] = 'name = ?';
            $params[] = $name;
        }
        if ($description !== null) {
            $sets[] = 'description = ?';
            $params[] = $description;
        }
        if ($source !== null) {
            $sets[] = 'source = ?';
            $params[] = $source;
        }
        if ($promptTemplate !== null) {
            $sets[] = 'prompt_template = ?';
            $params[] = $promptTemplate;
        }
        if ($role !== null) {
            $sets[] = 'role = ?';
            $params[] = $role;
        }
        if ($profile !== null) {
            $sets[] = 'profile = ?';
            $params[] = $profile !== '' ? $profile : null;
        }
        if ($maxIterations !== null) {
            $sets[] = 'max_iterations = ?';
            $params[] = $maxIterations;
        }
        if ($enabled !== null) {
            $sets[] = 'enabled = ?';
            $params[] = $enabled ? 1 : 0;
        }
        if ($eventFilter !== null) {
            $sets[] = 'event_filter = ?';
            $params[] = $eventFilter;
        }

        $params[] = $id;
        $sql = 'UPDATE webhook_subscriptions SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $this->db->prepare($sql)->execute($params);

        return true;
    }

    public function delete(string $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM webhook_subscriptions WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    /**
     * @return list<array<string, mixed>>
     */
    public function list(?bool $enabled = null, int $limit = 100): array
    {
        $where = [];
        $params = [];

        if ($enabled !== null) {
            $where[] = 'enabled = ?';
            $params[] = $enabled ? 1 : 0;
        }

        $whereClause = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->db->prepare(<<<SQL
            SELECT * FROM webhook_subscriptions
            {$whereClause}
            ORDER BY created_at ASC
            LIMIT ?
        SQL);

        $params[] = $limit;
        $stmt->execute($params);

        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Rotate the signing secret for a subscription.
     */
    public function rotateSecret(string $id): ?string
    {
        $webhook = $this->get($id);
        if ($webhook === null) {
            return null;
        }

        $newSecret = bin2hex(random_bytes(32));
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $stmt = $this->db->prepare(<<<'SQL'
            UPDATE webhook_subscriptions SET secret = ?, updated_at = ? WHERE id = ?
        SQL);
        $stmt->execute([$newSecret, $now, $id]);

        return $newSecret;
    }

    /**
     * Record that a webhook was triggered and increment the counter.
     */
    public function markTriggered(string $id): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->db->prepare(<<<'SQL'
            UPDATE webhook_subscriptions
            SET last_triggered_at = ?, trigger_count = trigger_count + 1, updated_at = ?
            WHERE id = ?
        SQL);
        $stmt->execute([$now, $now, $id]);
    }

    /**
     * Get aggregate stats.
     *
     * @return array{total: int, enabled: int, disabled: int, total_triggers: int}
     */
    public function getStats(): array
    {
        $stmt = $this->db->query(<<<'SQL'
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN enabled = 1 THEN 1 ELSE 0 END) AS enabled,
                SUM(CASE WHEN enabled = 0 THEN 1 ELSE 0 END) AS disabled,
                SUM(trigger_count) AS total_triggers
            FROM webhook_subscriptions
        SQL);

        $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : false;

        return [
            'total' => (int) ($row['total'] ?? 0),
            'enabled' => (int) ($row['enabled'] ?? 0),
            'disabled' => (int) ($row['disabled'] ?? 0),
            'total_triggers' => (int) ($row['total_triggers'] ?? 0),
        ];
    }

    // =========================================================================
    // Delivery Logging
    // =========================================================================

    /**
     * Log a webhook delivery attempt.
     */
    public function logDelivery(
        string $webhookId,
        string $status,
        ?string $eventType = null,
        ?string $payloadSummary = null,
        ?string $taskId = null,
        ?string $sourceIp = null,
    ): string {
        $id = bin2hex(random_bytes(16));
        $now = gmdate('Y-m-d\TH:i:s\Z');

        // Truncate payload summary to 2KB
        if ($payloadSummary !== null && mb_strlen($payloadSummary) > 2048) {
            $payloadSummary = mb_substr($payloadSummary, 0, 2048);
        }

        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO webhook_deliveries
                (id, webhook_id, event_type, payload_summary, task_id, status, source_ip, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        SQL);

        $stmt->execute([
            $id,
            $webhookId,
            $eventType,
            $payloadSummary,
            $taskId,
            $status,
            $sourceIp,
            $now,
        ]);

        return $id;
    }

    /**
     * Get recent deliveries for a webhook.
     *
     * @return list<array<string, mixed>>
     */
    public function getDeliveries(string $webhookId, int $limit = 50): array
    {
        $stmt = $this->db->prepare(<<<'SQL'
            SELECT * FROM webhook_deliveries
            WHERE webhook_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        SQL);

        $stmt->execute([$webhookId, $limit]);

        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDelivery(string $deliveryId, ?string $webhookId = null): ?array
    {
        if ($webhookId !== null) {
            $stmt = $this->db->prepare('SELECT * FROM webhook_deliveries WHERE id = ? AND webhook_id = ?');
            $stmt->execute([$deliveryId, $webhookId]);
        } else {
            $stmt = $this->db->prepare('SELECT * FROM webhook_deliveries WHERE id = ?');
            $stmt->execute([$deliveryId]);
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Purge old delivery records.
     */
    public function purgeOldDeliveries(int $retentionDays = self::DELIVERY_RETENTION_DAYS): int
    {
        $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify("-{$retentionDays} days")
            ->format('Y-m-d\TH:i:s\Z');

        $stmt = $this->db->prepare('DELETE FROM webhook_deliveries WHERE created_at < ?');
        $stmt->execute([$cutoff]);

        return $stmt->rowCount();
    }
}
