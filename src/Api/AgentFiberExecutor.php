<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

use CoquiBot\Coqui\Agent\AgentRunner;
use CoquiBot\Coqui\Config\AutoApprovalPolicy;
use CoquiBot\Coqui\Config\CatastrophicBlacklist;
use CoquiBot\Coqui\Contract\AgentTurnResult;
use CoquiBot\Coqui\Observer\SseObserver;
use CoquiBot\Coqui\Storage\SessionStorage;
use React\Stream\ThroughStream;

/**
 * Manages Fiber-based agent execution for the API server.
 *
 * Each prompt submission runs inside a PHP Fiber, allowing the ReactPHP
 * event loop to remain responsive while the synchronous agent loop
 * (which makes blocking HTTP calls to LLM providers) executes.
 *
 * For v1, the agent's HTTP calls block within their Fiber — the event loop
 * can service other HTTP requests while a Fiber is suspended. True async
 * HTTP can be added later by injecting a Fiber-aware HttpClientInterface.
 */
final class AgentFiberExecutor
{
    /** @var array<string, \Fiber> */
    private array $activeFibers = [];

    public function __construct(
        private readonly AgentRunner $agentRunner,
        private readonly SessionStorage $storage,
        private readonly CatastrophicBlacklist $blacklist,
    ) {}

    /**
     * Execute an agent turn in a Fiber, streaming SSE events to the given stream.
     *
     * @return ThroughStream  The readable stream that emits SSE data.
     */
    public function execute(string $sessionId, string $prompt): ThroughStream
    {
        $stream = new ThroughStream();
        $sseObserver = new SseObserver($stream);

        // Create a Fiber for this agent run
        $fiber = new \Fiber(function () use ($sessionId, $prompt, $stream, $sseObserver): void {
            $executionPolicy = new AutoApprovalPolicy(
                blacklist: $this->blacklist,
                storage: $this->storage,
                sessionId: $sessionId,
            );

            try {
                // Create a new AgentRunner with the SSE observer for this turn
                $result = $this->agentRunner->runWithObserver(
                    $prompt,
                    $sessionId,
                    $executionPolicy,
                    $sseObserver,
                );

                // Write the final complete event
                $sseObserver->writeComplete($result->toArray());
            } catch (\Throwable $e) {
                $sseObserver->handleEvent('agent.error', $e->getMessage());
                $sseObserver->writeComplete([
                    'error' => $e->getMessage(),
                    'content' => '',
                ]);
            } finally {
                // Clean up and close the stream
                unset($this->activeFibers[$sessionId]);
                if ($stream->isWritable()) {
                    $stream->end();
                }
            }
        });

        $this->activeFibers[$sessionId] = $fiber;

        // Start the Fiber — it will block on HTTP calls internally
        // but that's okay since we're inside a Fiber
        try {
            $fiber->start();
        } catch (\Throwable $e) {
            $sseObserver->handleEvent('agent.error', $e->getMessage());
            if ($stream->isWritable()) {
                $stream->end();
            }
        }

        // If the Fiber hasn't completed yet, schedule periodic resumption
        if (!$fiber->isTerminated()) {
            $this->scheduleFiberResumption($fiber, $sessionId, $stream);
        }

        return $stream;
    }

    /**
     * Schedule periodic Fiber resumption via the event loop.
     */
    private function scheduleFiberResumption(\Fiber $fiber, string $sessionId, ThroughStream $stream): void
    {
        // The Fiber will resume when it suspends (e.g., during HTTP I/O waits).
        // For v1 with synchronous HTTP, the Fiber runs to completion in start().
        // This method is a placeholder for v2 when we inject a Fiber-aware HTTP client
        // that calls Fiber::suspend() during socket waits.
        if ($fiber->isTerminated()) {
            unset($this->activeFibers[$sessionId]);
            return;
        }

        // Resume the fiber if it's suspended
        if ($fiber->isSuspended()) {
            try {
                $fiber->resume();
            } catch (\Throwable $e) {
                if ($stream->isWritable()) {
                    $stream->end();
                }
                unset($this->activeFibers[$sessionId]);
            }
        }
    }

    /**
     * Get count of currently active Fibers.
     */
    public function activeCount(): int
    {
        return count($this->activeFibers);
    }

    /**
     * Check if a session has an active agent run.
     */
    public function isActive(string $sessionId): bool
    {
        return isset($this->activeFibers[$sessionId]);
    }
}
