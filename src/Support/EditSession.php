<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Support;

/**
 * Transactional edit session for atomic multi-file edits.
 *
 * Holds pending edits in memory. On commit, validates that all target files
 * still match their expected originals (concurrent edit protection via SHA-256),
 * then applies all edits atomically. If any file was modified externally since
 * the session started, the entire commit fails with no partial writes.
 *
 * Sessions are ephemeral — not persisted to SQLite. They expire after a
 * configurable timeout (default 5 minutes).
 */
final class EditSession
{
    /** Default session timeout in seconds. */
    private const int DEFAULT_TIMEOUT_SECONDS = 300;

    /** @var list<array{path: string, original: string, modified: string, originalHash: string, operation: string, metadata: array<string, mixed>}> */
    private array $pendingEdits = [];

    private bool $committed = false;
    private bool $rolledBack = false;

    public readonly string $id;
    public readonly float $createdAt;
    public readonly float $expiresAt;

    public function __construct(
        int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ) {
        $this->id = bin2hex(random_bytes(16));
        $this->createdAt = microtime(true);
        $this->expiresAt = $this->createdAt + $timeoutSeconds;
    }

    /**
     * Queue an edit for later commit.
     *
     * @param string $path           Resolved absolute file path.
     * @param string $original       File content at the time of the edit.
     * @param string $modified       New content to write on commit.
     * @param string $operation      Edit operation name (e.g. 'replace_in_file').
     * @param array<string, mixed> $metadata   Operation-specific metadata.
     */
    public function addEdit(
        string $path,
        string $original,
        string $modified,
        string $operation,
        array $metadata = [],
    ): void {
        $this->guardMutable();

        $this->pendingEdits[] = [
            'path' => $path,
            'original' => $original,
            'modified' => $modified,
            'originalHash' => hash('sha256', $original),
            'operation' => $operation,
            'metadata' => $metadata,
        ];
    }

    /**
     * Validate that all pending files still match their expected originals.
     *
     * @return list<string> List of conflicting file paths (empty = all clear).
     */
    public function validate(): array
    {
        $this->guardMutable();
        $conflicts = [];

        foreach ($this->pendingEdits as $edit) {
            if (!file_exists($edit['path'])) {
                // File was deleted externally — conflict
                $conflicts[] = $edit['path'];
                continue;
            }

            $currentContent = @file_get_contents($edit['path']);
            if ($currentContent === false) {
                $conflicts[] = $edit['path'];
                continue;
            }

            $currentHash = hash('sha256', $currentContent);
            if ($currentHash !== $edit['originalHash']) {
                $conflicts[] = $edit['path'];
            }
        }

        return array_values(array_unique($conflicts));
    }

    /**
     * Mark the session as committed. Caller is responsible for applying writes.
     *
     * Returns the pending edits for the caller to apply atomically.
     *
     * @return list<array{path: string, original: string, modified: string, operation: string, metadata: array<string, mixed>}>
     */
    public function commit(): array
    {
        $this->guardMutable();
        $this->committed = true;

        return array_map(
            fn(array $edit) => [
                'path' => $edit['path'],
                'original' => $edit['original'],
                'modified' => $edit['modified'],
                'operation' => $edit['operation'],
                'metadata' => $edit['metadata'],
            ],
            $this->pendingEdits,
        );
    }

    /**
     * Discard all pending edits.
     */
    public function rollback(): void
    {
        $this->guardMutable();
        $this->rolledBack = true;
        $this->pendingEdits = [];
    }

    /**
     * Get information about pending edits for status display.
     *
     * @return list<array{path: string, operation: string, metadata: array<string, mixed>}>
     */
    public function pendingEdits(): array
    {
        return array_map(
            fn(array $edit) => [
                'path' => $edit['path'],
                'operation' => $edit['operation'],
                'metadata' => $edit['metadata'],
            ],
            $this->pendingEdits,
        );
    }

    public function pendingCount(): int
    {
        return count($this->pendingEdits);
    }

    public function isExpired(): bool
    {
        return microtime(true) > $this->expiresAt;
    }

    public function isCommitted(): bool
    {
        return $this->committed;
    }

    public function isRolledBack(): bool
    {
        return $this->rolledBack;
    }

    public function isActive(): bool
    {
        return !$this->committed && !$this->rolledBack && !$this->isExpired();
    }

    public function status(): string
    {
        if ($this->committed) {
            return 'committed';
        }
        if ($this->rolledBack) {
            return 'rolled_back';
        }
        if ($this->isExpired()) {
            return 'expired';
        }

        return 'active';
    }

    /**
     * @throws \RuntimeException If the session is no longer mutable.
     */
    private function guardMutable(): void
    {
        if ($this->committed) {
            throw new \RuntimeException("Edit session {$this->id} is already committed.");
        }
        if ($this->rolledBack) {
            throw new \RuntimeException("Edit session {$this->id} was rolled back.");
        }
        if ($this->isExpired()) {
            throw new \RuntimeException("Edit session {$this->id} has expired.");
        }
    }
}
