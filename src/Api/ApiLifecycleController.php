<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

use CoquiBot\Coqui\Storage\RuntimeStateStore;

/**
 * Tracks restart-required state and coordinates managed API restarts.
 */
final class ApiLifecycleController
{
    /** @var null|callable(string): void */
    private $restartHandler = null;

    public function __construct(
        private readonly RuntimeStateStore $runtimeStateStore,
        private readonly bool $managedByLauncher,
        private readonly string $startedAt,
        private readonly int $pid,
    ) {}

    public function markBooted(): void
    {
        $this->runtimeStateStore->clearApiRestartRequired();
    }

    /**
     * @param callable(string): void $handler
     */
    public function configureRestartHandler(callable $handler): void
    {
        $this->restartHandler = $handler;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function markRestartRequired(string $reason, string $source, array $context = []): array
    {
        $this->runtimeStateStore->markApiRestartRequired($reason, $source, $context);

        return $this->restartState();
    }

    /**
     * @param array<string, mixed> $context
     */
    public function requestRestart(string $reason, string $source = 'api', array $context = []): bool
    {
        $this->markRestartRequired($reason, $source, $context);

        if (!$this->restartSupported()) {
            return false;
        }

        $handler = $this->restartHandler;
        if ($handler === null) {
            return false;
        }

        $handler($reason);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function restartState(): array
    {
        return $this->runtimeStateStore->apiRestartState() + [
            'supported' => $this->restartSupported(),
            'managed_by_launcher' => $this->managedByLauncher,
            'pid' => $this->pid,
            'started_at' => $this->startedAt,
        ];
    }

    public function restartSupported(): bool
    {
        return $this->managedByLauncher && $this->restartHandler !== null;
    }
}