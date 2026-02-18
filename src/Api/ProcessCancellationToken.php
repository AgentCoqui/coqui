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

    public function cancel(): void
    {
        $this->cancelled = true;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
}
