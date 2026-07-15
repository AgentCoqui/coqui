<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl;

use CoquiBot\Coqui\Agent\AgentRunner;
use CoquiBot\Coqui\Agent\GroupTurnCoordinator;
use CoquiBot\Coqui\Api\ProcessCancellationToken;
use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Contract\AgentTurnResult as ContractAgentTurnResult;
use CoquiBot\Coqui\Observer\AnimatedTickCallback;
use CoquiBot\Coqui\Observer\EscCancellationObserver;
use CoquiBot\Coqui\Question\InteractiveQuestionResponder;
use CoquiBot\Coqui\Question\QuestionPersistence;
use CoquiBot\Coqui\Contract\SessionType;
use CoquiBot\Coqui\Contract\SystemRole;
use CoquiBot\Coqui\Renderer\TerminalRenderer;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Support\ImagePreviewService;
use CarmeloSantana\PHPAgents\Contract\ToolExecutionPolicyInterface;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Executes a single agent turn: builds policy, manages cancellation tokens,
 * switches terminal to raw mode for ESC detection, runs the agent, renders
 * output, and handles post-turn tasks.
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
        ?string $activeProfile = null,
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
            $session = $this->storage->getSession($sessionId);
            $sessionType = is_array($session) ? SessionType::fromSessionRow($session) : SessionType::Interactive;
            $groupEnabled = $sessionType === SessionType::Group;

            if ($groupEnabled && is_array($session)) {
                $result = $this->executeGroupTurn($prompt, $sessionId, $session, $executionPolicy);
            } else {
                // Synchronous REPL responder: renders `ask_user` questions inline
                // on the TTY. turnId is null here — the turns row is created inside
                // AgentRunner::doRun after this responder is built.
                $questionResponder = new InteractiveQuestionResponder(
                    $io,
                    new QuestionPersistence($this->storage),
                    $sessionId,
                );
                $result = $this->agentRunner->run(
                    $prompt,
                    $sessionId,
                    $executionPolicy,
                    $cancellationToken,
                    role: $activeRole !== SystemRole::Orchestrator->value ? $activeRole : null,
                    profile: $activeProfile,
                    questionResponder: $questionResponder,
                );
            }
        } finally {
            $this->escObserver->setActorContext(null, null);
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
        $renderer = new TerminalRenderer(
            $io,
            showHints: fn(): bool => (bool) $this->boot->config()->get('agents.defaults.hints', true),
            imagePreviewService: new ImagePreviewService($this->boot->workspacePath()),
        );
        $renderer->render($result, contentStreamed: true);

        // Ctrl+C during execution → graceful shutdown (skip deferred work, exit REPL)
        if ($shutdownRequested) {
            return new AgentTurnResult(exitCode: 0);
        }

        if ($cancellationToken->isCancelled()) {
            return new AgentTurnResult();
        }

        // Queue first-turn title generation for the API worker so prompt return
        // is not blocked by a second provider call on the REPL thread.
        $this->storage->enqueueSessionTitleJob($sessionId, $prompt);

        // Process deferred work after
        // stats are visible but before returning control to the REPL.
        $result->deferredWork?->process();

        // Check restart
        if ($result->restartRequested) {
            $io->info('Restart requested by agent. Restarting...');
            return new AgentTurnResult(exitCode: self::RESTART_EXIT_CODE);
        }

        return new AgentTurnResult();
    }

    /**
     * @param array<string, mixed> $session
     */
    private function executeGroupTurn(
        string $prompt,
        string $sessionId,
        array $session,
        ToolExecutionPolicyInterface $executionPolicy,
    ): ContractAgentTurnResult {
        $members = $this->storage->listSessionGroupMemberNames($sessionId);
        $sessionRole = is_string($session['model_role'] ?? null) && $session['model_role'] !== ''
            ? $session['model_role']
            : SystemRole::Orchestrator->value;
        $role = $sessionRole !== SystemRole::Orchestrator->value ? $sessionRole : null;
        $groupMaxRounds = is_int($session['group_max_rounds'] ?? null)
            ? $session['group_max_rounds']
            : 3;
        $groupModel = is_string($session['model'] ?? null) && $session['model'] !== ''
            ? $session['model']
            : $this->boot->roleResolver()->resolve($sessionRole, null);
        $coordinator = new GroupTurnCoordinator($this->storage);

        return $coordinator->run(
            sessionId: $sessionId,
            prompt: $prompt,
            modelString: $groupModel,
            modelRole: $sessionRole,
            members: $members,
            maxRounds: $groupMaxRounds,
            turnProcessId: null,
            filePaths: null,
            executeActor: function (string $actorPrompt, string $actorName, int $round, ?array $actorFilePaths, string $turnId) use (
                $executionPolicy,
                $sessionId,
                $role,
                $sessionRole,
            ): ContractAgentTurnResult {
                $this->escObserver->setActorContext($actorName, $role ?? $sessionRole);

                return $this->agentRunner->runSegment(
                    prompt: $actorPrompt,
                    sessionId: $sessionId,
                    turnId: $turnId,
                    executionPolicy: $executionPolicy,
                    observer: $this->escObserver,
                    filePaths: $actorFilePaths,
                    role: $role,
                    profile: $actorName,
                    actorName: $actorName,
                    actorRole: $role ?? $sessionRole,
                );
            },
            notifyLifecycleEvent: fn(string $event, array $data) => $this->dispatchGroupLifecycleEvent($event, $data),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function dispatchGroupLifecycleEvent(string $event, array $data): void
    {
        $this->escObserver->setActorContext(
            is_string($data['actor_name'] ?? null) ? $data['actor_name'] : null,
            is_string($data['actor_role'] ?? null) ? $data['actor_role'] : null,
        );
        $this->escObserver->handleEvent($event, $data);
    }
}
