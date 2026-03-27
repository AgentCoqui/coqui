<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl;

use CoquiBot\Coqui\Agent\AgentRunner;
use CoquiBot\Coqui\Agent\TitleGenerator;
use CoquiBot\Coqui\Api\ProcessCancellationToken;
use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Observer\EscCancellationObserver;
use CoquiBot\Coqui\Renderer\TerminalRenderer;
use CoquiBot\Coqui\Storage\SessionStorage;
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
        $this->escObserver->setToken($cancellationToken);
        $sigintCount = 0;

        // Two-stage Ctrl+C:
        //   First  → cooperative cancel (sets token; agent stops after current response)
        //   Second → restore SIG_DFL and re-raise to kill immediately (exit 130)
        if ($hasSignals) {
            pcntl_signal(SIGINT, static function () use ($cancellationToken, &$sigintCount, $io): void {
                $sigintCount++;
                if ($sigintCount === 1) {
                    $cancellationToken->cancel();
                    $io->writeln(
                        "\n<fg=yellow>⚑ Stopping — completing current LLM response, then returning to prompt. Press Ctrl+C again to force quit.</>",
                    );
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
        $this->escObserver->active = true;

        try {
            $result = $this->agentRunner->run(
                $prompt,
                $sessionId,
                $executionPolicy,
                $cancellationToken,
                role: $activeRole !== 'orchestrator' ? $activeRole : null,
            );
        } finally {
            $this->escObserver->active = false;
            $this->terminalState->drainStdin();
            $this->terminalState->restoreState($stty);
            $savedStty = null;
        }

        // Render output
        $renderer = new TerminalRenderer($io, showHints: fn(): bool => (bool) $this->boot->config()->get('agents.defaults.hints', true));
        $renderer->render($result, contentStreamed: true);

        // Generate session title on first turn (best-effort)
        $this->maybeGenerateTitle($sessionId, $prompt);

        // Check restart
        if ($result->restartRequested) {
            $io->info('Restart requested by agent. Restarting...');
            return new AgentTurnResult(exitCode: self::RESTART_EXIT_CODE);
        }

        // Offer continuation when iteration limit was reached and an active sprint exists
        $continuationPrompt = null;
        if ($result->iterationLimitReached && $this->boot->projectStore() !== null) {
            $sprints = $this->boot->projectStore()->getActiveSprintsForSession($sessionId);
            if ($sprints !== []) {
                $sprint = $sprints[0];
                $todoStore = $this->boot->todoStore();
                $progress = $todoStore !== null
                    ? $this->boot->projectStore()->getSprintProgress($sprint['id'], $todoStore)
                    : ['percent' => 0, 'completed' => 0, 'total' => 0];
                $title = $sprint['title'];
                $pct = $progress['percent'];
                $done = $progress['completed'];
                $total = $progress['total'];
                $io->newLine();
                if ($io->confirm("Sprint '{$title}' is {$pct}% complete ({$done}/{$total} todos). Continue?", true)) {
                    $continuationPrompt = "Continue working on sprint '{$title}'. Check todo_list for remaining items.";
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
