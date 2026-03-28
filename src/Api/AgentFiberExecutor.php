<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

use CoquiBot\Coqui\Agent\AgentRunner;
use CoquiBot\Coqui\Agent\TitleGenerator;
use CoquiBot\Coqui\Config\AutoApprovalPolicy;
use CoquiBot\Coqui\Config\CatastrophicBlacklist;
use CoquiBot\Coqui\Contract\AgentTurnResult;
use CoquiBot\Coqui\Observer\NullObserver;
use CoquiBot\Coqui\Observer\SseObserver;
use CoquiBot\Coqui\Storage\SessionStorage;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
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
    /** @var array<string, \Fiber<void, void, void, void>> */
    private array $activeFibers = [];

    public function __construct(
        private readonly AgentRunner $agentRunner,
        private readonly SessionStorage $storage,
        private readonly CatastrophicBlacklist $blacklist,
        private readonly ?TitleGenerator $titleGenerator = null,
    ) {}

    /**
     * Execute an agent turn in a Fiber, streaming SSE events to the given stream.
     *
     * @param string[]|null $filePaths  Optional file paths to attach to the message.
     * @return ThroughStream  The readable stream that emits SSE data.
     */
    public function execute(string $sessionId, string $prompt, ?array $filePaths = null): ThroughStream
    {
        $stream = new ThroughStream();
        $sseObserver = new SseObserver($stream);

        // Create a Fiber for this agent run
        $fiber = new \Fiber(function () use ($sessionId, $prompt, $filePaths, $stream, $sseObserver): void {
            $executionPolicy = new AutoApprovalPolicy(
                blacklist: $this->blacklist,
                storage: $this->storage,
                sessionId: $sessionId,
            );

            try {
                // Resolve the active role from the session record
                $session = $this->storage->getSession($sessionId);
                $sessionRole = ($session !== null && isset($session['model_role']))
                    ? (string) $session['model_role']
                    : 'orchestrator';
                $role = ($sessionRole !== '' && $sessionRole !== 'orchestrator') ? $sessionRole : null;

                // Create a new AgentRunner with the SSE observer for this turn
                $result = $this->agentRunner->runWithObserver(
                    $prompt,
                    $sessionId,
                    $executionPolicy,
                    $sseObserver,
                    $filePaths,
                    $role,
                );

                // Write the final complete event
                $sseObserver->writeComplete($result->toArray());

                // Process deferred work (memory extraction) after response is sent
                $result->deferredWork?->process();

                // Generate title on first turn if no title exists
                $this->maybeGenerateTitle($sessionId, $prompt, $sseObserver);
            } catch (\Throwable $e) {
                // Log the full error for operators, surface only a safe message to clients
                error_log(sprintf(
                    '[Coqui API] Agent error in session %s: %s in %s:%d',
                    $sessionId,
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                ));

                $sseObserver->handleEvent('agent.error', 'Internal error');
                $sseObserver->writeComplete([
                    'error' => 'Internal error',
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
            error_log(sprintf(
                '[Coqui API] Fiber start error in session %s: %s',
                $sessionId,
                $e->getMessage(),
            ));
            $sseObserver->handleEvent('agent.error', 'Internal error');
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
     *
     * @param \Fiber<void, void, void, void> $fiber
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
     * Execute an agent turn and return a Promise that resolves with the result.
     *
     * Used for blocking (non-streaming) mode. The agent runs in a Fiber
     * without SSE output, and the Promise resolves with the AgentTurnResult
     * once the Fiber completes.
     *
     * @param string[]|null $filePaths  Optional file paths to attach to the message.
     * @return PromiseInterface<array<string, mixed>>
     */
    public function executeBlocking(string $sessionId, string $prompt, ?array $filePaths = null): PromiseInterface
    {
        $deferred = new Deferred();

        $fiber = new \Fiber(function () use ($sessionId, $prompt, $filePaths, $deferred): void {
            $executionPolicy = new AutoApprovalPolicy(
                blacklist: $this->blacklist,
                storage: $this->storage,
                sessionId: $sessionId,
            );

            try {
                $result = $this->agentRunner->runWithObserver(
                    $prompt,
                    $sessionId,
                    $executionPolicy,
                    new NullObserver(),
                    $filePaths,
                );

                // Process deferred work (memory extraction) before resolving
                $result->deferredWork?->process();

                // Generate title on first turn (best-effort, no observer needed)
                $this->maybeGenerateTitleBlocking($sessionId, $prompt);

                $deferred->resolve($result->toArray());
            } catch (\Throwable $e) {
                $deferred->resolve([
                    'error' => 'Internal error',
                    'content' => '',
                ]);
            } finally {
                unset($this->activeFibers[$sessionId]);
            }
        });

        $this->activeFibers[$sessionId] = $fiber;

        try {
            $fiber->start();
        } catch (\Throwable) {
            $deferred->resolve([
                'error' => 'Internal error',
                'content' => '',
            ]);
        }

        // In v1, the Fiber runs synchronously to completion during start().
        // The Promise is already resolved by this point.
        if ($fiber->isTerminated()) {
            unset($this->activeFibers[$sessionId]);
        }

        return $deferred->promise();
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

    /**
     * Generate a title for the session if it doesn't already have one.
     *
     * Runs after writeComplete() so the main response is delivered first.
     * Title generation is best-effort — failures are silently ignored.
     */
    private function maybeGenerateTitle(string $sessionId, string $prompt, SseObserver $observer): void
    {
        if ($this->titleGenerator === null) {
            return;
        }

        // Only generate if the session has no title yet
        $session = $this->storage->getSession($sessionId);
        if ($session === null || ($session['title'] ?? null) !== null) {
            return;
        }

        $title = $this->titleGenerator->generate($prompt);
        if ($title === null) {
            return;
        }

        // Persist and stream the title event
        $this->storage->updateSessionTitle($sessionId, $title);
        $observer->writeTitle($title);
    }

    /**
     * Generate a title for blocking mode (no observer needed).
     *
     * Runs after the agent completes. Best-effort — failures are silently ignored.
     */
    private function maybeGenerateTitleBlocking(string $sessionId, string $prompt): void
    {
        if ($this->titleGenerator === null) {
            return;
        }

        $session = $this->storage->getSession($sessionId);
        if ($session === null || ($session['title'] ?? null) !== null) {
            return;
        }

        $title = $this->titleGenerator->generate($prompt);
        if ($title === null) {
            return;
        }

        $this->storage->updateSessionTitle($sessionId, $title);
    }
}
