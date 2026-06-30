<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Storage;

use CoquiBot\Coqui\Contract\CoquiDefaults;
use CoquiBot\Coqui\Support\Clock;
use CoquiBot\Coqui\Support\IdGenerator;
use CoquiBot\Coqui\Support\SchemaHelper;
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
                bound_session_id TEXT,
                config_json TEXT NOT NULL DEFAULT '{}',
                allowed_scopes_json TEXT NOT NULL DEFAULT '[]',
                security_json TEXT NOT NULL DEFAULT '{}',
                capabilities_json TEXT NOT NULL DEFAULT '{}',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        SQL);

        $this->migrateAddColumn('channel_instances', 'bound_session_id', 'TEXT DEFAULT NULL');

        $this->db->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_channel_instances_driver
                ON channel_instances(driver)
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_channel_instances_enabled
                ON channel_instances(enabled)
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_channel_instances_bound_session
                ON channel_instances(bound_session_id)
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

        $this->migrateAddColumn('channel_inbound_events', 'session_id', 'TEXT DEFAULT NULL');
        $this->migrateAddColumn('channel_inbound_events', 'task_id', 'TEXT DEFAULT NULL');

        $this->db->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_channel_inbound_events_task
                ON channel_inbound_events(task_id)
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
            CREATE INDEX IF NOT EXISTS idx_channel_deliveries_reply_event
                ON channel_deliveries(reply_to_event_id)
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

    private function migrateAddColumn(string $table, string $column, string $definition): void
    {
        SchemaHelper::addColumnIfMissing($this->db, $table, $column, $definition);
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, bool>|null $capabilities
     */
    public function upsertConfiguredInstance(array $definition, ?array $capabilities = null): string
    {
        $existing = $this->getByName((string) $definition['name']);
        $now = Clock::nowUtc();

        if ($existing !== null) {
            $stmt = $this->db->prepare(<<<'SQL'
                UPDATE channel_instances
                SET driver = ?, source = ?, enabled = ?, display_name = ?, default_profile = ?,
                    bound_session_id = ?, config_json = ?, allowed_scopes_json = ?, security_json = ?, capabilities_json = ?, updated_at = ?
                WHERE id = ?
            SQL);

            $stmt->execute([
                (string) $definition['driver'],
                (string) ($definition['source'] ?? 'config'),
                (bool) ($definition['enabled'] ?? true) ? 1 : 0,
                (string) ($definition['display_name'] ?? $definition['name']),
                $definition['default_profile'] ?? null,
                $definition['bound_session_id'] ?? null,
                $this->encodeJson($definition['settings'] ?? []),
                $this->encodeJson($definition['allowed_scopes'] ?? []),
                $this->encodeJson($definition['security'] ?? []),
                $this->encodeJson($capabilities ?? []),
                $now,
                (string) $existing['id'],
            ]);

            return (string) $existing['id'];
        }

        $id = IdGenerator::hex();
        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO channel_instances
                (id, name, driver, source, enabled, display_name, default_profile, bound_session_id,
                 config_json, allowed_scopes_json, security_json, capabilities_json, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        SQL);

        $stmt->execute([
            $id,
            (string) $definition['name'],
            (string) $definition['driver'],
            (string) ($definition['source'] ?? 'config'),
            (bool) ($definition['enabled'] ?? true) ? 1 : 0,
            (string) ($definition['display_name'] ?? $definition['name']),
            $definition['default_profile'] ?? null,
            $definition['bound_session_id'] ?? null,
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
    public function get(string $id): ?array
    {
        $stmt = $this->db->prepare(<<<'SQL'
            SELECT ci.*, crs.worker_status, crs.ready, crs.summary, crs.last_heartbeat_at, crs.last_receive_at,
                   crs.last_send_at, crs.inbound_backlog, crs.outbound_backlog, crs.consecutive_failures,
                   crs.lease_owner, crs.last_error, crs.updated_at AS runtime_updated_at
            FROM channel_instances ci
            LEFT JOIN channel_runtime_state crs ON crs.channel_instance_id = ci.id
            WHERE ci.id = ?
            LIMIT 1
        SQL);
        $stmt->execute([$id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrateInstanceRow($row) : null;
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

        return $row !== false ? $this->hydrateInstanceRow($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getByIdOrName(string $idOrName): ?array
    {
        $row = $this->get($idOrName);

        return $row ?? $this->getByName($idOrName);
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

        return array_values(array_map($this->hydrateInstanceRow(...), $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listLinks(string $channelInstanceId, int $limit = 100): array
    {
        $stmt = $this->db->prepare(<<<'SQL'
            SELECT *
            FROM channel_identity_links
            WHERE channel_instance_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        SQL);
        $stmt->bindValue(1, $channelInstanceId);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_values(array_map($this->hydrateLinkRow(...), $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getConversationByRemote(string $channelInstanceId, string $remoteConversationKey, ?string $remoteThreadKey = null): ?array
    {
        $stmt = $this->db->prepare(<<<'SQL'
            SELECT *
            FROM channel_conversations
            WHERE channel_instance_id = ?
              AND remote_conversation_key = ?
              AND ((remote_thread_key IS NULL AND ? IS NULL) OR remote_thread_key = ?)
            LIMIT 1
        SQL);
        $stmt->execute([$channelInstanceId, $remoteConversationKey, $remoteThreadKey, $remoteThreadKey]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrateJsonRow($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getConversation(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM channel_conversations WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrateJsonRow($row) : null;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function upsertConversation(
        string $channelInstanceId,
        string $remoteConversationKey,
        ?string $remoteThreadKey = null,
        ?string $sessionId = null,
        ?string $profile = null,
        ?string $lastInboundEventId = null,
        ?string $lastMessageAt = null,
        array $metadata = [],
    ): string {
        $existing = $this->getConversationByRemote($channelInstanceId, $remoteConversationKey, $remoteThreadKey);
        $now = Clock::nowUtc();

        if ($existing !== null) {
            $stmt = $this->db->prepare(<<<'SQL'
                UPDATE channel_conversations
                SET session_id = COALESCE(?, session_id),
                    profile = COALESCE(?, profile),
                    last_inbound_event_id = COALESCE(?, last_inbound_event_id),
                    last_message_at = COALESCE(?, last_message_at),
                    metadata_json = ?,
                    updated_at = ?
                WHERE id = ?
            SQL);
            $stmt->execute([
                $sessionId,
                $profile,
                $lastInboundEventId,
                $lastMessageAt,
                $this->encodeJson($metadata),
                $now,
                (string) $existing['id'],
            ]);

            return (string) $existing['id'];
        }

        $id = IdGenerator::hex();
        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO channel_conversations
                (id, channel_instance_id, remote_conversation_key, remote_thread_key, session_id, profile,
                 last_inbound_event_id, last_message_at, metadata_json, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        SQL);
        $stmt->execute([
            $id,
            $channelInstanceId,
            $remoteConversationKey,
            $remoteThreadKey,
            $sessionId,
            $profile,
            $lastInboundEventId,
            $lastMessageAt,
            $this->encodeJson($metadata),
            $now,
            $now,
        ]);

        return $id;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $normalized
     */
    public function createInboundEvent(
        string $channelInstanceId,
        ?string $conversationId,
        ?string $providerEventId,
        string $dedupeKey,
        string $eventType,
        ?string $remoteUserKey,
        array $payload,
        array $normalized,
        string $status = 'received',
        ?string $receivedAt = null,
    ): ?string {
        $id = IdGenerator::hex();
        $stmt = $this->db->prepare(<<<'SQL'
            INSERT OR IGNORE INTO channel_inbound_events
                (id, channel_instance_id, conversation_id, provider_event_id, dedupe_key, event_type,
                 remote_user_key, payload_json, normalized_json, status, received_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        SQL);
        $stmt->execute([
            $id,
            $channelInstanceId,
            $conversationId,
            $providerEventId,
            $dedupeKey,
            $eventType,
            $remoteUserKey,
            $this->encodeJson($payload),
            $this->encodeJson($normalized),
            $status,
            $receivedAt ?? Clock::nowUtc(),
        ]);

        return $stmt->rowCount() > 0 ? $id : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getInboundEvent(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM channel_inbound_events WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrateJsonRow($row) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listInboundEventsByStatus(string $status, int $limit = 100, ?string $channelInstanceId = null): array
    {
        $sql = <<<'SQL'
            SELECT *
            FROM channel_inbound_events
            WHERE status = ?
        SQL;
        $params = [$status];

        if ($channelInstanceId !== null) {
            $sql .= ' AND channel_instance_id = ?';
            $params[] = $channelInstanceId;
        }

        $sql .= ' ORDER BY received_at ASC LIMIT ?';

        $stmt = $this->db->prepare($sql);
        foreach ($params as $index => $value) {
            $stmt->bindValue($index + 1, $value);
        }
        $stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_values(array_map($this->hydrateJsonRow(...), $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    public function updateInboundEventState(
        string $eventId,
        string $status,
        ?string $error = null,
        ?string $processedAt = null,
        ?string $sessionId = null,
        ?string $taskId = null,
    ): void {
        $stmt = $this->db->prepare(<<<'SQL'
            UPDATE channel_inbound_events
            SET status = ?,
                error = ?,
                processed_at = ?,
                session_id = COALESCE(?, session_id),
                task_id = COALESCE(?, task_id)
            WHERE id = ?
        SQL);
        $stmt->execute([
            $status,
            $error,
            $processedAt,
            $sessionId,
            $taskId,
            $eventId,
        ]);
    }

    public function countInboundBacklog(string $channelInstanceId): int
    {
        $stmt = $this->db->prepare(<<<'SQL'
            SELECT COUNT(*)
            FROM channel_inbound_events
            WHERE channel_instance_id = ? AND status = 'received'
        SQL);
        $stmt->execute([$channelInstanceId]);

        return max(0, (int) $stmt->fetchColumn());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findLinkForRemoteIdentity(string $channelInstanceId, string $remoteUserKey, ?string $remoteScopeKey = null): ?array
    {
        if ($remoteScopeKey !== null && $remoteScopeKey !== '') {
            $stmt = $this->db->prepare(<<<'SQL'
                SELECT *
                FROM channel_identity_links
                WHERE channel_instance_id = ?
                  AND remote_user_key = ?
                  AND remote_scope_key = ?
                ORDER BY created_at DESC
                LIMIT 1
            SQL);
            $stmt->execute([$channelInstanceId, $remoteUserKey, $remoteScopeKey]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row !== false) {
                return $this->hydrateLinkRow($row);
            }
        }

        $stmt = $this->db->prepare(<<<'SQL'
            SELECT *
            FROM channel_identity_links
            WHERE channel_instance_id = ?
              AND remote_user_key = ?
            ORDER BY CASE WHEN remote_scope_key IS NULL THEN 0 ELSE 1 END ASC, created_at DESC
            LIMIT 1
        SQL);
        $stmt->execute([$channelInstanceId, $remoteUserKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrateLinkRow($row) : null;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function createLink(string $channelInstanceId, string $remoteUserKey, string $profile, ?string $remoteScopeKey = null, string $trustLevel = 'linked', array $metadata = []): string
    {
        $id = IdGenerator::hex();
        $now = Clock::nowUtc();
        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO channel_identity_links
                (id, channel_instance_id, remote_user_key, remote_scope_key, profile, trust_level, metadata_json, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        SQL);
        $stmt->execute([
            $id,
            $channelInstanceId,
            $remoteUserKey,
            $remoteScopeKey,
            $profile,
            $trustLevel,
            $this->encodeJson($metadata),
            $now,
            $now,
        ]);

        return $id;
    }

    public function deleteLink(string $channelInstanceId, string $linkId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM channel_identity_links WHERE channel_instance_id = ? AND id = ?');
        $stmt->execute([$channelInstanceId, $linkId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listConversations(string $channelInstanceId, int $limit = 100): array
    {
        $stmt = $this->db->prepare(<<<'SQL'
            SELECT *
            FROM channel_conversations
            WHERE channel_instance_id = ?
            ORDER BY updated_at DESC
            LIMIT ?
        SQL);
        $stmt->bindValue(1, $channelInstanceId);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_values(array_map($this->hydrateJsonRow(...), $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listEvents(string $channelInstanceId, int $limit = 100): array
    {
        $stmt = $this->db->prepare(<<<'SQL'
            SELECT *
            FROM channel_inbound_events
            WHERE channel_instance_id = ?
            ORDER BY received_at DESC
            LIMIT ?
        SQL);
        $stmt->bindValue(1, $channelInstanceId);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_values(array_map($this->hydrateJsonRow(...), $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listDeliveries(string $channelInstanceId, int $limit = 100): array
    {
        $stmt = $this->db->prepare(<<<'SQL'
            SELECT *
            FROM channel_deliveries
            WHERE channel_instance_id = ?
            ORDER BY queued_at DESC
            LIMIT ?
        SQL);
        $stmt->bindValue(1, $channelInstanceId);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_values(array_map($this->hydrateJsonRow(...), $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDelivery(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM channel_deliveries WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrateJsonRow($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDeliveryByReplyToEventId(string $replyToEventId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM channel_deliveries WHERE reply_to_event_id = ? ORDER BY queued_at DESC LIMIT 1');
        $stmt->execute([$replyToEventId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrateJsonRow($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDeliveryByIdempotencyKey(string $channelInstanceId, string $idempotencyKey): ?array
    {
        $stmt = $this->db->prepare(<<<'SQL'
            SELECT *
            FROM channel_deliveries
            WHERE channel_instance_id = ? AND idempotency_key = ?
            LIMIT 1
        SQL);
        $stmt->execute([$channelInstanceId, $idempotencyKey]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrateJsonRow($row) : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function queueDelivery(
        string $channelInstanceId,
        ?string $conversationId,
        ?string $sessionId,
        ?string $replyToEventId,
        string $idempotencyKey,
        array $payload,
    ): string {
        $existing = $this->getDeliveryByIdempotencyKey($channelInstanceId, $idempotencyKey);
        if ($existing !== null) {
            return (string) $existing['id'];
        }

        $id = IdGenerator::hex();
        $queuedAt = Clock::nowUtc();
        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO channel_deliveries
                (id, channel_instance_id, conversation_id, session_id, reply_to_event_id, idempotency_key,
                 payload_json, status, attempt_count, queued_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'queued', 0, ?)
        SQL);
        $stmt->execute([
            $id,
            $channelInstanceId,
            $conversationId,
            $sessionId,
            $replyToEventId,
            $idempotencyKey,
            $this->encodeJson($payload),
            $queuedAt,
        ]);

        return $id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listQueuedDeliveries(string $channelInstanceId, int $limit = 100): array
    {
        $stmt = $this->db->prepare(<<<'SQL'
            SELECT *
            FROM channel_deliveries
            WHERE channel_instance_id = ? AND status = 'queued'
            ORDER BY queued_at ASC
            LIMIT ?
        SQL);
        $stmt->bindValue(1, $channelInstanceId);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_values(array_map($this->hydrateJsonRow(...), $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    public function countQueuedDeliveries(string $channelInstanceId): int
    {
        $stmt = $this->db->prepare(<<<'SQL'
            SELECT COUNT(*)
            FROM channel_deliveries
            WHERE channel_instance_id = ? AND status = 'queued'
        SQL);
        $stmt->execute([$channelInstanceId]);

        return max(0, (int) $stmt->fetchColumn());
    }

    public function recordDeliveryAttempt(
        string $deliveryId,
        string $resultStatus,
        ?int $providerResponseCode = null,
        ?string $providerResponseBody = null,
        ?int $retryAfterSeconds = null,
    ): int {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM channel_delivery_attempts WHERE delivery_id = ?');
        $stmt->execute([$deliveryId]);
        $attemptNumber = ((int) $stmt->fetchColumn()) + 1;

        $insert = $this->db->prepare(<<<'SQL'
            INSERT INTO channel_delivery_attempts
                (id, delivery_id, attempt_number, result_status, retry_after_seconds, provider_response_code,
                 provider_response_body, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        SQL);
        $insert->execute([
            IdGenerator::hex(),
            $deliveryId,
            $attemptNumber,
            $resultStatus,
            $retryAfterSeconds,
            $providerResponseCode,
            $providerResponseBody,
            Clock::nowUtc(),
        ]);

        return $attemptNumber;
    }

    public function markDeliverySent(string $deliveryId, int $attemptCount, ?string $providerMessageId = null, ?string $sentAt = null): void
    {
        $stmt = $this->db->prepare(<<<'SQL'
            UPDATE channel_deliveries
            SET status = 'sent',
                attempt_count = ?,
                provider_message_id = ?,
                last_error = NULL,
                sent_at = ?,
                failed_at = NULL
            WHERE id = ?
        SQL);
        $stmt->execute([
            $attemptCount,
            $providerMessageId,
            $sentAt ?? Clock::nowUtc(),
            $deliveryId,
        ]);
    }

    public function markDeliveryFailed(string $deliveryId, int $attemptCount, string $error, ?string $failedAt = null): void
    {
        $stmt = $this->db->prepare(<<<'SQL'
            UPDATE channel_deliveries
            SET status = 'failed',
                attempt_count = ?,
                last_error = ?,
                failed_at = ?
            WHERE id = ?
        SQL);
        $stmt->execute([
            $attemptCount,
            $error,
            $failedAt ?? Clock::nowUtc(),
            $deliveryId,
        ]);
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
            Clock::nowUtc(),
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

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateInstanceRow(array $row): array
    {
        $row['settings'] = $this->decodeJsonColumn($row['config_json'] ?? '{}', []);
        $row['allowed_scopes'] = $this->decodeJsonColumn($row['allowed_scopes_json'] ?? '[]', []);
        $row['security'] = $this->decodeJsonColumn($row['security_json'] ?? '{}', []);
        $row['capabilities'] = $this->decodeJsonColumn($row['capabilities_json'] ?? '{}', []);
        $row['bound_session_id'] = is_string($row['bound_session_id'] ?? null) && trim((string) $row['bound_session_id']) !== ''
            ? trim((string) $row['bound_session_id'])
            : null;

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateLinkRow(array $row): array
    {
        $row['metadata'] = $this->decodeJsonColumn($row['metadata_json'] ?? '{}', []);

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateJsonRow(array $row): array
    {
        foreach ($row as $key => $value) {
            if (!is_string($value) || !str_ends_with($key, '_json')) {
                continue;
            }

            $row[substr($key, 0, -5)] = $this->decodeJsonColumn($value, []);
        }

        return $row;
    }

    private function decodeJsonColumn(mixed $value, mixed $default): mixed
    {
        if (!is_string($value) || trim($value) === '') {
            return $default;
        }

        try {
            $decoded = json_decode($value, true, CoquiDefaults::JSON_DECODE_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $default;
        }

        return $decoded;
    }
}