<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Command;

use CoquiBot\Coqui\Agent\AgentRunner;
use CoquiBot\Coqui\Agent\TitleGenerator;
use CoquiBot\Coqui\Api\ProcessCancellationToken;
use CoquiBot\Coqui\Config\AutoApprovalPolicy;
use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Config\ConfigGuard;
use CoquiBot\Coqui\Observer\BackgroundTaskObserver;
use CoquiBot\Coqui\Observer\NullObserver;
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
 * to the task_events table for SSE streaming, and updates the turn
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

        $workspaceOverride = $this->resolveWorkspaceOverride($input);

        $boot = new BootManager($workDir, $workspaceOverride);
        $result = $boot->boot(io: null, configPath: $configPath);

        if (!$result) {
            $output->writeln('<error>Boot failed</error>');
            return Command::FAILURE;
        }

        // Initialize storage
        $dbPath = $boot->workspacePath() . '/data/coqui.db';
        $storage = new SessionStorage($dbPath);

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

        // Create observer that persists events to task_events table.
        // Uses the turn process ID as the "task ID" — same table, same format.
        $turnObserver = new BackgroundTaskObserver($storage, $turnProcessId);

        // Create agent runner
        $agentRunner = new AgentRunner(
            roleResolver: $boot->roleResolver(),
            config: $boot->config(),
            projectRoot: $workDir,
            workspacePath: $boot->workspacePath(),
            storage: $storage,
            observer: new NullObserver(),
            discovery: $boot->discovery(),
            blacklist: $boot->blacklist(),
            credentialResolver: $boot->credentialResolver(),
            skillDiscovery: $boot->skillDiscovery(),
            roleDiscovery: $boot->roleDiscovery(),
            unsafeMode: $unsafeMode,
            backgroundTasksEnabled: true,
            memoryStore: $boot->memoryStore(),
            memorySummarizer: $boot->memorySummarizer(),
            mountManager: $boot->mountManager(),
            configManager: $boot->configManager(),
            configGuard: new ConfigGuard(),
            spaceToolkit: $boot->spaceToolkit(),
            todoStore: $boot->todoStore(),
            artifactStore: $boot->artifactStore(),
            projectStore: $boot->projectStore(),
        );

        // Create execution policy (auto-approve — no human in the loop)
        $executionPolicy = new AutoApprovalPolicy(
            blacklist: $boot->blacklist(),
            storage: $storage,
            sessionId: $sessionId,
        );

        try {
            // Resolve the active role from the session record
            $session = $storage->getSession($sessionId);
            $sessionRole = ($session !== null && isset($session['model_role']))
                ? (string) $session['model_role']
                : 'orchestrator';
            $role = ($sessionRole !== '' && $sessionRole !== 'orchestrator') ? $sessionRole : null;

            $turnResult = $agentRunner->runWithObserver(
                $prompt,
                $sessionId,
                $executionPolicy,
                $turnObserver,
                $filePaths,
                $role,
            );

            if ($cancellationToken->isCancelled()) {
                $storage->updateTurnProcessStatus($turnProcessId, 'failed', [
                    'error' => 'Cancelled via SIGTERM',
                    'result' => $turnResult->content,
                ]);
                $storage->appendTaskEvent($turnProcessId, 'complete', [
                    'error' => 'Cancelled',
                    'content' => $turnResult->content,
                ]);

                return 2;
            }

            if ($turnResult->error !== null) {
                $storage->updateTurnProcessStatus($turnProcessId, 'failed', [
                    'error' => $turnResult->error,
                    'result' => $turnResult->content,
                ]);
                $storage->appendTaskEvent($turnProcessId, 'complete', [
                    'error' => $turnResult->error,
                    'content' => $turnResult->content,
                ]);

                return Command::FAILURE;
            }

            // Write the final "complete" event with full metadata
            $storage->appendTaskEvent($turnProcessId, 'complete', $turnResult->toArray());

            // Generate title if needed (best-effort, runs in same process)
            $this->maybeGenerateTitle($sessionId, $prompt, $turnProcessId, $boot, $storage);

            $storage->updateTurnProcessStatus($turnProcessId, 'completed', [
                'result' => $turnResult->content,
            ]);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $storage->updateTurnProcessStatus($turnProcessId, 'failed', [
                'error' => $e->getMessage(),
            ]);
            $storage->appendTaskEvent($turnProcessId, 'error', [
                'message' => 'Internal error',
            ]);
            $storage->appendTaskEvent($turnProcessId, 'complete', [
                'error' => 'Internal error',
                'content' => '',
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * Generate a session title if one doesn't exist yet.
     *
     * Best-effort — failures are silently ignored.
     */
    private function maybeGenerateTitle(
        string $sessionId,
        string $prompt,
        string $turnProcessId,
        BootManager $boot,
        SessionStorage $storage,
    ): void {
        try {
            $session = $storage->getSession($sessionId);
            if ($session === null || ($session['title'] ?? null) !== null) {
                return;
            }

            $titleGenerator = new TitleGenerator(
                roleResolver: $boot->roleResolver(),
                config: $boot->config(),
                roleDiscovery: $boot->roleDiscovery(),
            );

            $title = $titleGenerator->generate($prompt);
            if ($title === null) {
                $storage->appendTaskEvent($turnProcessId, 'warning', [
                    'message' => 'Title generation returned no result',
                ]);

                return;
            }

            $storage->updateSessionTitle($sessionId, $title);
            $storage->appendTaskEvent($turnProcessId, 'title', ['title' => $title]);
        } catch (\Throwable $e) {
            // Best-effort — do not let title generation failures affect the turn
            error_log(sprintf(
                '[Coqui] maybeGenerateTitle failed for session %s: %s in %s:%d',
                $sessionId,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
            ));

            try {
                $storage->appendTaskEvent($turnProcessId, 'warning', [
                    'message' => 'Title generation failed: ' . $e->getMessage(),
                ]);
            } catch (\Throwable) {
                // Ignore secondary failure
            }
        }
    }

    private function resolveWorkspaceOverride(InputInterface $input): ?string
    {
        $option = $input->getOption('workspace');

        if (is_string($option) && $option !== '') {
            return $option;
        }

        $env = getenv('COQUI_WORKSPACE');

        if (is_string($env) && $env !== '') {
            return $env;
        }

        return null;
    }
}
