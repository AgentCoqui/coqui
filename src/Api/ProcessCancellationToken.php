<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

use CarmeloSantana\PHPAgents\Contract\CancellationTokenInterface;

/**
 * Mutable cancellation token for background task processes.
 *
 * The cancel() method is called from external signals (SIGTERM handler)
 * to request cooperative cancellation. The agent loop checks
 * isCancelled() at the top of each iteration.
 */
final class ProcessCancellationToken implements CancellationTokenInterface
{
    private bool $cancelled = false;

    /** @var array<int, \Closure(): void> */
    private array $listeners = [];

    public function cancel(): void
    {
        if ($this->cancelled) {
            return;
        }

        $this->cancelled = true;

        foreach ($this->listeners as $listener) {
            try {
                $listener();
            } catch (\Throwable) {
                // Best effort only — cancellation should continue even if a listener fails.
            }
        }
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    public function onCancel(\Closure $listener): void
    {
        if ($this->cancelled) {
            $listener();
            return;
        }

        $this->listeners[] = $listener;
    }
}
