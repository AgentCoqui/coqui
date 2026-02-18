<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Command;

use CoquiBot\Coqui\Agent\AgentRunner;
use CoquiBot\Coqui\Api\DatabasePendingInputProvider;
use CoquiBot\Coqui\Api\ProcessCancellationToken;
use CoquiBot\Coqui\Config\AutoApprovalPolicy;
use CoquiBot\Coqui\Config\BootManager;
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
 * Runs a single background task in an isolated process.
 *
 * Spawned by BackgroundTaskManager via proc_open. Reads the task
 * definition from SQLite, executes the agent loop, and persists
 * the result. Handles SIGTERM for cooperative cancellation.
 *
 * Exit codes:
 *   0 = completed successfully
 *   1 = failed (error in agent execution)
 *   2 = cancelled via SIGTERM
 */
#[AsCommand(
    name: 'task:run',
    description: 'Execute a background task (internal — spawned by the API server)',
    hidden: true,
)]
final class TaskRunCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('task-id', InputArgument::REQUIRED, 'The background task ID to execute')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to openclaw.json')
            ->addOption('workdir', 'w', InputOption::VALUE_REQUIRED, 'Working directory', getcwd() ?: '.')
            ->addOption('unsafe', null, InputOption::VALUE_NONE, 'Disable script sanitization');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $taskId = $input->getArgument('task-id');

        if (!is_string($taskId) || $taskId === '') {
            $output->writeln('<error>Task ID is required</error>');
            return Command::FAILURE;
        }

        $workDir = is_string($input->getOption('workdir'))
            ? $input->getOption('workdir')
            : (getcwd() ?: '.');
        $unsafeMode = (bool) $input->getOption('unsafe');

        // Boot the system (headless)
        $configOption = $input->getOption('config');
        $configPath = is_string($configOption) ? $configOption : null;

        $boot = new BootManager($workDir);
        $result = $boot->boot(io: null, configPath: $configPath);

        if (!$result) {
            $output->writeln('<error>Boot failed</error>');
            return Command::FAILURE;
        }

        // Initialize storage
        $dbPath = $boot->workspacePath() . '/data/coqui.db';
        $storage = new SessionStorage($dbPath);

        // Load task definition
        $task = $storage->getTask($taskId);

        if ($task === null) {
            $output->writeln(sprintf('<error>Task %s not found</error>', $taskId));
            return Command::FAILURE;
        }

        if ($task['status'] !== 'pending' && $task['status'] !== 'running') {
            $output->writeln(sprintf('<error>Task %s is in status "%s" — cannot execute</error>', $taskId, $task['status']));
            return Command::FAILURE;
        }

        $sessionId = $task['session_id'];
        $prompt = $task['prompt'];

        // Update status to running
        $storage->updateTaskStatus($taskId, 'running', ['pid' => getmypid()]);

        // Set up cancellation token + SIGTERM handler
        $cancellationToken = new ProcessCancellationToken();

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, function () use ($cancellationToken): void {
                $cancellationToken->cancel();
            });
        }

        // Create observer and input provider
        $taskObserver = new BackgroundTaskObserver($storage, $taskId);
        $inputProvider = new DatabasePendingInputProvider($storage, $taskId);

        // Create agent runner (no restart tool — onRestart is never set for tasks)
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
        );

        // Create execution policy (auto-approve — no human in the loop)
        $executionPolicy = new AutoApprovalPolicy(
            blacklist: $boot->blacklist(),
            storage: $storage,
            sessionId: $sessionId,
        );

        try {
            // Dispatch SIGTERM between iterations
            if (function_exists('pcntl_async_signals')) {
                pcntl_async_signals(true);
            }

            $turnResult = $agentRunner->runForTask(
                prompt: $prompt,
                sessionId: $sessionId,
                executionPolicy: $executionPolicy,
                observer: $taskObserver,
                cancellationToken: $cancellationToken,
                pendingInputProvider: $inputProvider,
            );

            if ($cancellationToken->isCancelled()) {
                $storage->updateTaskStatus($taskId, 'cancelled', [
                    'result' => $turnResult->content,
                ]);
                $storage->appendTaskEvent($taskId, 'cancelled', [
                    'message' => 'Task was cancelled via SIGTERM',
                ]);

                return 2;
            }

            if ($turnResult->error !== null) {
                $storage->updateTaskStatus($taskId, 'failed', [
                    'error' => $turnResult->error,
                    'result' => $turnResult->content,
                ]);
                $storage->appendTaskEvent($taskId, 'failed', [
                    'error' => $turnResult->error,
                    'duration_ms' => $turnResult->durationMs,
                ]);

                return Command::FAILURE;
            }

            $storage->updateTaskStatus($taskId, 'completed', [
                'result' => $turnResult->content,
            ]);
            $storage->appendTaskEvent($taskId, 'completed', [
                'duration_ms' => $turnResult->durationMs,
                'iterations' => $turnResult->iterations,
                'total_tokens' => $turnResult->totalTokens,
                'tools_used' => $turnResult->toolsUsed,
            ]);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $storage->updateTaskStatus($taskId, 'failed', [
                'error' => $e->getMessage(),
            ]);
            $storage->appendTaskEvent($taskId, 'failed', [
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }
}
