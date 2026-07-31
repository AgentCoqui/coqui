<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Command;

use CoquiBot\Coqui\Agent\ConcurrentToolExecutor;
use CoquiBot\Coqui\Agent\GroupTurnCoordinator;
use CoquiBot\Coqui\Api\ProcessCancellationToken;
use CoquiBot\Coqui\Config\AutoApprovalPolicy;
use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Contract\AgentTurnResult;
use CoquiBot\Coqui\Contract\SessionType;
use CoquiBot\Coqui\Command\WorkspaceOverrideResolver;
use CoquiBot\Coqui\Observer\NullObserver;
use CoquiBot\Coqui\Observer\TurnProcessObserver;
use CoquiBot\Coqui\Storage\SessionStorage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs a single interactive agent turn in an isolated child process.
 *
 * Spawned by AgentTurnManager via proc_open. Reads the turn process
 * definition from SQLite, executes the agent loop, persists events
 * to the turn_events table for SSE streaming, and updates the turn
 * process record on completion.
 *
 * Exit codes:
 *   0 = completed successfully
 *   1 = failed (error in agent execution)
 *   2 = cancelled via SIGTERM
 */
#[AsCommand(
    name: 'turn:run',
    description: 'Execute an interactive agent turn (internal — spawned by the API server)',
    hidden: true,
)]
final class TurnRunCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('turn-process-id', InputArgument::REQUIRED, 'The turn process ID to execute')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to openclaw.json')
            ->addOption('workdir', 'w', InputOption::VALUE_REQUIRED, 'Working directory', getcwd() ?: '.')
            ->addOption('workspace', null, InputOption::VALUE_REQUIRED, 'Workspace directory (overrides config and default)')
            ->addOption('unsafe', null, InputOption::VALUE_NONE, 'Disable script sanitization');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $turnProcessId = $input->getArgument('turn-process-id');

        if (!is_string($turnProcessId) || $turnProcessId === '') {
            $output->writeln('<error>Turn process ID is required</error>');
            return Command::FAILURE;
        }

        $workDir = is_string($input->getOption('workdir'))
            ? $input->getOption('workdir')
            : (getcwd() ?: '.');
        $unsafeMode = (bool) $input->getOption('unsafe');

        // Boot the system (headless)
        $configOption = $input->getOption('config');
        $configPath = is_string($configOption) ? $configOption : null;

        $workspaceOverride = WorkspaceOverrideResolver::resolve($input);

        $boot = new BootManager($workDir, $workspaceOverride);
        $result = $boot->boot(io: null, configPath: $configPath, skipMaintenance: true);

        if (!$result) {
            $output->writeln('<error>Boot failed</error>');
            return Command::FAILURE;
        }

        // Initialize storage
        $dbPath = $boot->workspacePath() . '/data/coqui.db';
        $storage = new SessionStorage($dbPath, auditRedactor: $boot->auditRedactor());

        // Load turn process definition
        $turnProcess = $storage->getTurnProcess($turnProcessId);

        if ($turnProcess === null) {
            $output->writeln(sprintf('<error>Turn process %s not found</error>', $turnProcessId));
            return Command::FAILURE;
        }

        if ($turnProcess['status'] !== 'pending' && $turnProcess['status'] !== 'running') {
            $output->writeln(sprintf(
                '<error>Turn process %s is in status "%s" — cannot execute</error>',
                $turnProcessId,
                $turnProcess['status'],
            ));
            return Command::FAILURE;
        }

        $sessionId = $turnProcess['session_id'];
        $prompt = $turnProcess['prompt'];
        $filePaths = null;

        if (isset($turnProcess['file_paths']) && is_string($turnProcess['file_paths'])) {
            $decoded = json_decode($turnProcess['file_paths'], true);
            if (is_array($decoded)) {
                $filePaths = $decoded;
            }
        }

        // Update status to running
        $storage->updateTurnProcessStatus($turnProcessId, 'running', ['pid' => getmypid()]);

        // Set up cancellation token + SIGTERM handler
        $cancellationToken = new ProcessCancellationToken();

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, function () use ($cancellationToken): void {
                $cancellationToken->cancel();
            });
        }

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        // Create observer that persists events to the turn_events table.
        $turnObserver = new TurnProcessObserver($storage, $turnProcessId);

        // Create agent runner
        $agentRunner = AgentRunnerFactory::create(
            boot: $boot,
            projectRoot: $workDir,
            storage: $storage,
            observer: new NullObserver(),
            unsafeMode: $unsafeMode,
            includeConfigManager: true,
            toolExecutor: new ConcurrentToolExecutor(),
        );

        // Create execution policy (auto-approve — no human in the loop)
        $executionPolicy = new AutoApprovalPolicy(
            blacklist: $boot->blacklist(),
            storage: $storage,
            sessionId: $sessionId,
        );

        // Structured-question responder: persists the question, emits a `question`
        // turn-event for SSE, then block-polls the DB for the client's answer.
        // The cancellation token lets the poll abort on SIGTERM.
        $questionResponder = new \CoquiBot\Coqui\Question\SuspendingQuestionResponder(
            new \CoquiBot\Coqui\Question\QuestionPersistence($storage),
            $storage,
            $sessionId,
            $turnProcessId,
            $cancellationToken,
        );

        try {
            // Resolve the active role from the session record
            $session = $storage->getSession($sessionId);
            $sessionRole = ($session !== null && isset($session['model_role']))
                ? (string) $session['model_role']
                : 'orchestrator';
            $role = ($sessionRole !== '' && $sessionRole !== 'orchestrator') ? $sessionRole : null;
            $personaRaw = $session['persona_id'] ?? null;
            $persona = is_string($personaRaw) ? $personaRaw : null;
            $groupEnabled = is_array($session) && SessionType::fromSessionRow($session) === SessionType::Group;

            if ($groupEnabled) {
                $members = $storage->listSessionGroupMemberNames($sessionId);
                $groupMaxRounds = is_int($session['group_max_rounds'] ?? null)
                    ? $session['group_max_rounds']
                    : 3;
                $groupModel = $boot->roleResolver()->resolveForSession(
                    is_string($session['model'] ?? null) && $session['model'] !== '' ? $session['model'] : null,
                    $sessionRole,
                    null,
                );

                $coordinator = new GroupTurnCoordinator($storage);
                $turnResult = $coordinator->run(
                    sessionId: $sessionId,
                    prompt: $prompt,
                    modelString: $groupModel,
                    modelRole: $sessionRole,
                    members: $members,
                    maxRounds: $groupMaxRounds,
                    turnProcessId: $turnProcessId,
                    filePaths: $filePaths,
                    executeActor: function (string $actorPrompt, string $actorName, int $round, ?array $actorFilePaths, string $turnId) use (
                        $agentRunner,
                        $executionPolicy,
                        $sessionId,
                        $storage,
                        $turnProcessId,
                        $role,
                        $questionResponder,
                    ): AgentTurnResult {
                        return $agentRunner->runSegment(
                            prompt: $actorPrompt,
                            sessionId: $sessionId,
                            turnId: $turnId,
                            executionPolicy: $executionPolicy,
                            observer: new TurnProcessObserver($storage, $turnProcessId, $actorName, $role ?? 'orchestrator'),
                            filePaths: $actorFilePaths,
                            role: $role,
                            persona: $actorName,
                            actorName: $actorName,
                            actorRole: $role ?? 'orchestrator',
                            questionResponder: $questionResponder,
                        );
                    },
                );
            } else {
                $turnResult = $agentRunner->runWithObserver(
                    $prompt,
                    $sessionId,
                    $executionPolicy,
                    $turnObserver,
                    $filePaths,
                    $role,
                    $persona,
                    $turnProcessId,
                    $questionResponder,
                );
            }

            if ($cancellationToken->isCancelled()) {
                $storage->updateTurnProcessStatus($turnProcessId, 'failed', [
                    'error' => 'Cancelled via SIGTERM',
                    'result' => $turnResult->content,
                ]);
                $storage->appendTurnEvent(
                    $turnProcessId,
                    'complete',
                    AgentTurnResult::fromError('Cancelled', $turnResult->content)->toArray(),
                );

                return 2;
            }

            if ($turnResult->error !== null) {
                $storage->updateTurnProcessStatus($turnProcessId, 'failed', [
                    'error' => $turnResult->error,
                    'result' => $turnResult->content,
                ]);
                $storage->appendTurnEvent(
                    $turnProcessId,
                    'complete',
                    AgentTurnResult::fromError($turnResult->error, $turnResult->content)->toArray(),
                );

                return Command::FAILURE;
            }

            // Write the final "complete" event with full metadata
            $storage->appendTurnEvent($turnProcessId, 'complete', $turnResult->toArray());

            // Queue title generation out-of-band so the interactive response can
            // complete without waiting for a second provider call.
            $storage->enqueueSessionTitleJob($sessionId, $prompt, $turnProcessId);

            $storage->updateTurnProcessStatus($turnProcessId, 'completed', [
                'result' => $turnResult->content,
            ]);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $storage->updateTurnProcessStatus($turnProcessId, 'failed', [
                'error' => $e->getMessage(),
            ]);
            $storage->appendTurnEvent($turnProcessId, 'error', [
                'message' => 'Internal error',
            ]);
            $storage->appendTurnEvent(
                $turnProcessId,
                'complete',
                AgentTurnResult::fromError('Internal error')->toArray(),
            );

            return Command::FAILURE;
        }
    }


}
