<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Storage;

use PDO;

/**
 * SQLite-backed persistence for channel runtime metadata and delivery scaffolding.
 */
final class ChannelStore
{
    public function __construct(
        private readonly PDO $db,
    ) {
        $this->createTables();
    }

    private function createTables(): void
    {
        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS channel_instances (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL UNIQUE,
                driver TEXT NOT NULL,
                source TEXT NOT NULL DEFAULT 'config',
                enabled INTEGER NOT NULL DEFAULT 1,
                display_name TEXT,
                default_profile TEXT,
                config_json TEXT NOT NULL DEFAULT '{}',
                allowed_scopes_json TEXT NOT NULL DEFAULT '[]',
                security_json TEXT NOT NULL DEFAULT '{}',
                capabilities_json TEXT NOT NULL DEFAULT '{}',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_channel_instances_driver
                ON channel_instances(driver)
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_channel_instances_enabled
                ON channel_instances(enabled)
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS channel_identity_links (
                id TEXT PRIMARY KEY,
                channel_instance_id TEXT NOT NULL,
                remote_user_key TEXT NOT NULL,
                remote_scope_key TEXT,
                profile TEXT NOT NULL,
                trust_level TEXT NOT NULL DEFAULT 'linked',
                metadata_json TEXT NOT NULL DEFAULT '{}',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (channel_instance_id) REFERENCES channel_instances(id) ON DELETE CASCADE
            )
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_channel_identity_links_instance
                ON channel_identity_links(channel_instance_id)
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS channel_conversations (
                id TEXT PRIMARY KEY,
                channel_instance_id TEXT NOT NULL,
                remote_conversation_key TEXT NOT NULL,
                remote_thread_key TEXT,
                session_id TEXT,
                profile TEXT,
                last_inbound_event_id TEXT,
                last_message_at TEXT,
                metadata_json TEXT NOT NULL DEFAULT '{}',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (channel_instance_id) REFERENCES channel_instances(id) ON DELETE CASCADE,
                FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE SET NULL
            )
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_channel_conversations_instance
                ON channel_conversations(channel_instance_id)
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS idx_channel_conversations_remote
                ON channel_conversations(channel_instance_id, remote_conversation_key, remote_thread_key)
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS channel_inbound_events (
                id TEXT PRIMARY KEY,
                channel_instance_id TEXT NOT NULL,
                conversation_id TEXT,
                provider_event_id TEXT,
                dedupe_key TEXT NOT NULL,
                event_type TEXT NOT NULL,
                remote_user_key TEXT,
                payload_json TEXT NOT NULL,
                normalized_json TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'received',
                error TEXT,
                received_at TEXT NOT NULL,
                processed_at TEXT,
                FOREIGN KEY (channel_instance_id) REFERENCES channel_instances(id) ON DELETE CASCADE,
                FOREIGN KEY (conversation_id) REFERENCES channel_conversations(id) ON DELETE SET NULL
            )
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS idx_channel_inbound_events_dedupe
                ON channel_inbound_events(channel_instance_id, dedupe_key)
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_channel_inbound_events_status
                ON channel_inbound_events(status)
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS channel_deliveries (
                id TEXT PRIMARY KEY,
                channel_instance_id TEXT NOT NULL,
                conversation_id TEXT,
                session_id TEXT,
                reply_to_event_id TEXT,
                idempotency_key TEXT NOT NULL,
                payload_json TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'queued',
                attempt_count INTEGER NOT NULL DEFAULT 0,
                provider_message_id TEXT,
                last_error TEXT,
                queued_at TEXT NOT NULL,
                sent_at TEXT,
                failed_at TEXT,
                FOREIGN KEY (channel_instance_id) REFERENCES channel_instances(id) ON DELETE CASCADE,
                FOREIGN KEY (conversation_id) REFERENCES channel_conversations(id) ON DELETE SET NULL,
                FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE SET NULL
            )
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_channel_deliveries_status
                ON channel_deliveries(status)
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS idx_channel_deliveries_idempotency
                ON channel_deliveries(channel_instance_id, idempotency_key)
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS channel_delivery_attempts (
                id TEXT PRIMARY KEY,
                delivery_id TEXT NOT NULL,
                attempt_number INTEGER NOT NULL,
                result_status TEXT NOT NULL,
                retry_after_seconds INTEGER,
                provider_response_code INTEGER,
                provider_response_body TEXT,
                created_at TEXT NOT NULL,
                FOREIGN KEY (delivery_id) REFERENCES channel_deliveries(id) ON DELETE CASCADE
            )
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_channel_delivery_attempts_delivery
                ON channel_delivery_attempts(delivery_id)
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS channel_runtime_state (
                channel_instance_id TEXT PRIMARY KEY,
                worker_status TEXT NOT NULL,
                ready INTEGER NOT NULL DEFAULT 0,
                summary TEXT NOT NULL DEFAULT '',
                last_heartbeat_at TEXT,
                last_receive_at TEXT,
                last_send_at TEXT,
                inbound_backlog INTEGER NOT NULL DEFAULT 0,
                outbound_backlog INTEGER NOT NULL DEFAULT 0,
                consecutive_failures INTEGER NOT NULL DEFAULT 0,
                lease_owner TEXT,
                last_error TEXT,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (channel_instance_id) REFERENCES channel_instances(id) ON DELETE CASCADE
            )
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_channel_runtime_state_status
                ON channel_runtime_state(worker_status)
        SQL);
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, bool>|null $capabilities
     */
    public function upsertConfiguredInstance(array $definition, ?array $capabilities = null): string
    {
        $existing = $this->getByName((string) $definition['name']);
        $now = gmdate('Y-m-d\TH:i:s\Z');

        if ($existing !== null) {
            $stmt = $this->db->prepare(<<<'SQL'
                UPDATE channel_instances
                SET driver = ?, source = ?, enabled = ?, display_name = ?, default_profile = ?,
                    config_json = ?, allowed_scopes_json = ?, security_json = ?, capabilities_json = ?, updated_at = ?
                WHERE id = ?
            SQL);

            $stmt->execute([
                (string) $definition['driver'],
                (string) ($definition['source'] ?? 'config'),
                (bool) ($definition['enabled'] ?? true) ? 1 : 0,
                (string) ($definition['display_name'] ?? $definition['name']),
                $definition['default_profile'] ?? null,
                $this->encodeJson($definition['settings'] ?? []),
                $this->encodeJson($definition['allowed_scopes'] ?? []),
                $this->encodeJson($definition['security'] ?? []),
                $this->encodeJson($capabilities ?? []),
                $now,
                (string) $existing['id'],
            ]);

            return (string) $existing['id'];
        }

        $id = bin2hex(random_bytes(16));
        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO channel_instances
                (id, name, driver, source, enabled, display_name, default_profile,
                 config_json, allowed_scopes_json, security_json, capabilities_json, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        SQL);

        $stmt->execute([
            $id,
            (string) $definition['name'],
            (string) $definition['driver'],
            (string) ($definition['source'] ?? 'config'),
            (bool) ($definition['enabled'] ?? true) ? 1 : 0,
            (string) ($definition['display_name'] ?? $definition['name']),
            $definition['default_profile'] ?? null,
            $this->encodeJson($definition['settings'] ?? []),
            $this->encodeJson($definition['allowed_scopes'] ?? []),
            $this->encodeJson($definition['security'] ?? []),
            $this->encodeJson($capabilities ?? []),
            $now,
            $now,
        ]);

        return $id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getByName(string $name): ?array
    {
        $stmt = $this->db->prepare(<<<'SQL'
            SELECT ci.*, crs.worker_status, crs.ready, crs.summary, crs.last_heartbeat_at, crs.last_receive_at,
                   crs.last_send_at, crs.inbound_backlog, crs.outbound_backlog, crs.consecutive_failures,
                   crs.lease_owner, crs.last_error, crs.updated_at AS runtime_updated_at
            FROM channel_instances ci
            LEFT JOIN channel_runtime_state crs ON crs.channel_instance_id = ci.id
            WHERE ci.name = ?
            LIMIT 1
        SQL);
        $stmt->execute([$name]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listInstances(int $limit = 100): array
    {
        $stmt = $this->db->prepare(<<<'SQL'
            SELECT ci.*, crs.worker_status, crs.ready, crs.summary, crs.last_heartbeat_at, crs.last_receive_at,
                   crs.last_send_at, crs.inbound_backlog, crs.outbound_backlog, crs.consecutive_failures,
                   crs.lease_owner, crs.last_error, crs.updated_at AS runtime_updated_at
            FROM channel_instances ci
            LEFT JOIN channel_runtime_state crs ON crs.channel_instance_id = ci.id
            ORDER BY ci.name ASC
            LIMIT ?
        SQL);
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param string[] $names
     */
    public function pruneConfigInstances(array $names): int
    {
        if ($names === []) {
            $stmt = $this->db->prepare('DELETE FROM channel_instances WHERE source = ?');
            $stmt->execute(['config']);

            return $stmt->rowCount();
        }

        $placeholders = implode(', ', array_fill(0, count($names), '?'));
        $params = array_merge(['config'], $names);
        $stmt = $this->db->prepare(
            sprintf('DELETE FROM channel_instances WHERE source = ? AND name NOT IN (%s)', $placeholders),
        );
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * @param array<string, mixed> $health
     */
    public function updateRuntimeState(string $channelInstanceId, array $health): void
    {
        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO channel_runtime_state
                (channel_instance_id, worker_status, ready, summary, last_heartbeat_at, last_receive_at,
                 last_send_at, inbound_backlog, outbound_backlog, consecutive_failures, lease_owner,
                 last_error, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(channel_instance_id) DO UPDATE SET
                worker_status = excluded.worker_status,
                ready = excluded.ready,
                summary = excluded.summary,
                last_heartbeat_at = excluded.last_heartbeat_at,
                last_receive_at = excluded.last_receive_at,
                last_send_at = excluded.last_send_at,
                inbound_backlog = excluded.inbound_backlog,
                outbound_backlog = excluded.outbound_backlog,
                consecutive_failures = excluded.consecutive_failures,
                lease_owner = excluded.lease_owner,
                last_error = excluded.last_error,
                updated_at = excluded.updated_at
        SQL);

        $stmt->execute([
            $channelInstanceId,
            (string) ($health['worker_status'] ?? 'stopped'),
            (bool) ($health['ready'] ?? false) ? 1 : 0,
            (string) ($health['summary'] ?? ''),
            $health['last_heartbeat_at'] ?? null,
            $health['last_receive_at'] ?? null,
            $health['last_send_at'] ?? null,
            (int) ($health['inbound_backlog'] ?? 0),
            (int) ($health['outbound_backlog'] ?? 0),
            (int) ($health['consecutive_failures'] ?? 0),
            $health['lease_owner'] ?? null,
            $health['last_error'] ?? null,
            gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /**
     * @return array{total: int, enabled: int, ready: int, errors: int}
     */
    public function getStats(): array
    {
        $stmt = $this->db->query(<<<'SQL'
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN ci.enabled = 1 THEN 1 ELSE 0 END) AS enabled,
                SUM(CASE WHEN crs.ready = 1 THEN 1 ELSE 0 END) AS ready,
                SUM(CASE WHEN crs.last_error IS NOT NULL AND crs.last_error != '' THEN 1 ELSE 0 END) AS errors
            FROM channel_instances ci
            LEFT JOIN channel_runtime_state crs ON crs.channel_instance_id = ci.id
        SQL);

        $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : false;

        return [
            'total' => (int) ($row['total'] ?? 0),
            'enabled' => (int) ($row['enabled'] ?? 0),
            'ready' => (int) ($row['ready'] ?? 0),
            'errors' => (int) ($row['errors'] ?? 0),
        ];
    }

    /**
     * @param mixed $value
     */
    private function encodeJson(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '{}';
    }
}