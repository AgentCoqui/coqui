<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Holds non-critical closures to execute after the stats summary is rendered.
 *
 * Allows the REPL to display output immediately while deferring
 * work like memory extraction and title generation.
 */
final class DeferredWorkQueue
{
    /** @var list<\Closure(): void> */
    private array $tasks = [];

    /**
     * Enqueue a closure to run later.
     *
     * @param \Closure(): void $task
     */
    public function enqueue(\Closure $task): void
    {
        $this->tasks[] = $task;
    }

    /**
     * Execute all queued tasks and clear the queue.
     *
     * Exceptions are caught per-task so one failure doesn't block the rest.
     */
    public function process(): void
    {
        foreach ($this->tasks as $task) {
            try {
                $task();
            } catch (\Throwable) {
                // Deferred work is best-effort — never interrupt user flow
            }
        }
        $this->tasks = [];
    }

    public function isEmpty(): bool
    {
        return $this->tasks === [];
    }
}
