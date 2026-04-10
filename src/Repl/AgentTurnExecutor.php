<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl;

use CoquiBot\Coqui\Agent\AgentRunner;
use CoquiBot\Coqui\Agent\TitleGenerator;
use CoquiBot\Coqui\Api\ProcessCancellationToken;
use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Exception\InteractionCancelledException;
use CoquiBot\Coqui\Exception\ShutdownRequestedException;
use CoquiBot\Coqui\Observer\AnimatedTickCallback;
use CoquiBot\Coqui\Observer\EscCancellationObserver;
use CoquiBot\Coqui\Contract\SystemRole;
use CoquiBot\Coqui\Renderer\TerminalRenderer;
use CoquiBot\Coqui\Storage\SessionStorage;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Executes a single agent turn: builds policy, manages cancellation tokens,
 * switches terminal to raw mode for ESC detection, runs the agent, renders
 * output, and handles post-turn tasks (title generation, sprint continuation).
 */
final class AgentTurnExecutor
{
    public const RESTART_EXIT_CODE = 10;

    public function __construct(
        private readonly AgentRunner $agentRunner,
        private readonly BootManager $boot,
        private readonly SessionStorage $storage,
        private readonly EscCancellationObserver $escObserver,
        private readonly TerminalStateManager $terminalState,
        private readonly ExecutionPolicyFactory $policyFactory,
        private readonly ?AnimatedTickCallback $tickCallback = null,
    ) {}

    /**
     * Execute a single agent turn.
     *
     * The &$savedStty is set during execution so the shutdown guard can restore
     * terminal state on crash; the finally block resets it to null.
     *
     * @param-out string|null $savedStty
     * @phpstan-ignore paramOut.unusedType
     */
    public function execute(
        string $prompt,
        string $sessionId,
        string $activeRole,
        SymfonyStyle $io,
        bool $autoApprove,
        bool $hasSignals,
        ?string &$savedStty,
    ): AgentTurnResult {
        $executionPolicy = $this->policyFactory->buildInteractive($sessionId, $io, $autoApprove);
        $cancellationToken = new ProcessCancellationToken();
        $sigintCount = 0;
        $shutdownRequested = false;
        if ($hasSignals) {
            pcntl_signal(SIGINT, static function () use ($cancellationToken, &$sigintCount, &$shutdownRequested, $io): void {
                $sigintCount++;
                if ($sigintCount === 1) {
                    $shutdownRequested = true;
                    $cancellationToken->cancel();
                    $io->writeln("\n<fg=yellow>⚑ Shutting down — completing current response. Press Ctrl+C again to force quit.</>");
                } else {
                    pcntl_signal(SIGINT, SIG_DFL);
                    posix_kill(posix_getpid(), SIGINT);
                }
            });
        }

        // Enter raw stty mode so ESC is delivered byte-by-byte (no Enter required).
        $stty = $this->terminalState->saveState();
        $savedStty = $stty;
        $this->terminalState->enterRawMode();
        $this->escObserver->beginTurn($cancellationToken);

        // Start animated spinner (tick callback drives animation between blocking calls)
        $this->tickCallback?->start();

        // Periodic event-loop timer drives the spinner DURING blocking ReactPHP I/O.
        // When the provider calls React\Async\await() inside ReactResponseStream,
        // the event loop runs and this timer fires, keeping the spinner animated.
        $timer = null;
        if ($this->tickCallback !== null) {
            $cb = $this->tickCallback;
            $escObserver = $this->escObserver;
            $timer = Loop::addPeriodicTimer(0.05, static function () use ($cb, $escObserver): void {
                if (!$escObserver->active) {
                    return;
                }

                $escObserver->poll();

                $cb->tick();
            });
        }

        try {
            $result = $this->agentRunner->run(
                $prompt,
                $sessionId,
                $executionPolicy,
                $cancellationToken,
                role: $activeRole !== SystemRole::Orchestrator->value ? $activeRole : null,
            );
        } finally {
            $this->escObserver->endTurn();
            $this->tickCallback?->stop();
            if ($timer !== null) {
                Loop::cancelTimer($timer);
            }
            $this->terminalState->drainStdin();
            $this->terminalState->restoreState($stty);
            $savedStty = null;
        }

        // Render output — user sees stats immediately
        $renderer = new TerminalRenderer($io, showHints: fn(): bool => (bool) $this->boot->config()->get('agents.defaults.hints', true));
        $renderer->render($result, contentStreamed: true);

        // Ctrl+C during execution → graceful shutdown (skip deferred work, exit REPL)
        if ($shutdownRequested) {
            return new AgentTurnResult(exitCode: 0);
        }

        if ($cancellationToken->isCancelled()) {
            return new AgentTurnResult();
        }

        // Enqueue title generation as deferred work (first-turn LLM call)
        $result->deferredWork?->enqueue(fn() => $this->maybeGenerateTitle($sessionId, $prompt));

        // Process deferred work (memory extraction, title generation) after
        // stats are visible but before returning control to the REPL.
        $result->deferredWork?->process();

        // Check restart
        if ($result->restartRequested) {
            $io->info('Restart requested by agent. Restarting...');
            return new AgentTurnResult(exitCode: self::RESTART_EXIT_CODE);
        }

        // Offer continuation when iteration limit or budget was reached and an active sprint exists
        $continuationPrompt = null;
        $shouldOfferContinuation = $result->iterationLimitReached || $result->budgetExhausted;
        if ($shouldOfferContinuation && $this->boot->projectStore() !== null) {
            $sprints = $this->boot->projectStore()->getActiveSprintsForSession($sessionId);
            if ($sprints !== []) {
                $sprint = $sprints[0];
                $todoStore = $this->boot->todoStore();
                $progress = $todoStore !== null
                    ? $this->boot->projectStore()->getSprintProgress($sprint['id'], $todoStore, $sessionId)
                    : ['percent' => 0, 'completed' => 0, 'total' => 0];
                $title = $sprint['title'];
                $pct = $progress['percent'];
                $done = $progress['completed'];
                $total = $progress['total'];
                $io->newLine();
                $prompter = new InterruptiblePrompt($io, $this->terminalState);
                try {
                    $reason = $result->budgetExhausted ? 'Context budget reached' : 'Iteration limit reached';
                    if ($prompter->confirm("{$reason}. Sprint '{$title}' is {$pct}% complete ({$done}/{$total} todos). Continue?", true)) {
                        $continuationPrompt = "Continue working on sprint '{$title}'. Check todo_list for remaining items."
                            . " Review the summary above for what was accomplished and what remains.";
                    }
                } catch (InteractionCancelledException) {
                    return new AgentTurnResult();
                } catch (ShutdownRequestedException) {
                    return new AgentTurnResult(exitCode: 0);
                }
            }
        }

        return new AgentTurnResult(continuationPrompt: $continuationPrompt);
    }

    private function maybeGenerateTitle(string $sessionId, string $prompt): void
    {
        try {
            $session = $this->storage->getSession($sessionId);
            if ($session === null || ($session['title'] ?? null) !== null) {
                return;
            }

            $titleGenerator = new TitleGenerator(
                roleResolver: $this->boot->roleResolver(),
                config: $this->boot->config(),
                roleDiscovery: $this->boot->roleDiscovery(),
                providerFactory: $this->boot->providerFactory(),
            );

            $title = $titleGenerator->generate($prompt);
            if ($title === null) {
                return;
            }

            $this->storage->updateSessionTitle($sessionId, $title);
        } catch (\Throwable $e) {
            error_log(sprintf('[Coqui] REPL title generation failed: %s', $e->getMessage()));
        }
    }
}
