<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Storage;

use CoquiBot\Coqui\Support\Clock;
use PDO;

/**
 * Small persistent runtime state store shared across REPL and API processes.
 */
final class RuntimeStateStore
{
    public function __construct(
        private readonly PDO $db,
    ) {
        $this->initialize();
    }

    /**
     * @return array{required: bool, reason: ?string, source: ?string, required_at: ?string, context: array<string, mixed>}
     */
    public function apiRestartState(): array
    {
        $state = $this->get('api.restart_required');
        if ($state === null) {
            return [
                'required' => false,
                'reason' => null,
                'source' => null,
                'required_at' => null,
                'context' => [],
            ];
        }

        return [
            'required' => true,
            'reason' => isset($state['reason']) && is_string($state['reason']) ? $state['reason'] : null,
            'source' => isset($state['source']) && is_string($state['source']) ? $state['source'] : null,
            'required_at' => isset($state['required_at']) && is_string($state['required_at']) ? $state['required_at'] : null,
            'context' => isset($state['context']) && is_array($state['context']) ? $state['context'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    public function markApiRestartRequired(string $reason, string $source, array $context = []): void
    {
        $this->put('api.restart_required', [
            'reason' => $reason,
            'source' => $source,
            'required_at' => Clock::nowUtc(),
            'context' => $context,
        ]);
    }

    public function clearApiRestartRequired(): void
    {
        $stmt = $this->db->prepare('DELETE FROM runtime_state WHERE state_key = ?');
        $stmt->execute(['api.restart_required']);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function get(string $key): ?array
    {
        $stmt = $this->db->prepare('SELECT value_json FROM runtime_state WHERE state_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        if (!is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $value
     */
    private function put(string $key, array $value): void
    {
        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO runtime_state (state_key, value_json, updated_at)
            VALUES (?, ?, ?)
            ON CONFLICT(state_key) DO UPDATE SET
                value_json = excluded.value_json,
                updated_at = excluded.updated_at
        SQL);
        $stmt->execute([
            $key,
            json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            Clock::nowUtc(),
        ]);
    }

    private function initialize(): void
    {
        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS runtime_state (
                state_key TEXT PRIMARY KEY,
                value_json TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        SQL);
    }
}