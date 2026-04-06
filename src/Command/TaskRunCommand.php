<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Command;

use CoquiBot\Coqui\Agent\ConcurrentToolExecutor;
use CoquiBot\Coqui\Agent\BackgroundToolExecutor;
use CoquiBot\Coqui\Agent\LearnerOutcomeTracker;
use CoquiBot\Coqui\Api\DatabasePendingInputProvider;
use CoquiBot\Coqui\Api\ProcessCancellationToken;
use CoquiBot\Coqui\Config\AutoApprovalPolicy;
use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Command\WorkspaceOverrideResolver;
use CoquiBot\Coqui\Contract\CoquiDefaults;
use CoquiBot\Coqui\Notification\NotificationPublisher;
use CoquiBot\Coqui\Observer\BackgroundTaskObserver;
use CoquiBot\Coqui\Observer\NullObserver;
use CoquiBot\Coqui\Storage\NotificationStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Storage\SkillLifecycleStore;
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
            ->addOption('workspace', null, InputOption::VALUE_REQUIRED, 'Workspace directory (overrides config and default)')
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

        $workspaceOverride = WorkspaceOverrideResolver::resolve($input);

        $boot = new BootManager($workDir, $workspaceOverride);
        $result = $boot->boot(io: null, configPath: $configPath, skipMaintenance: true);

        if (!$result) {
            $output->writeln('<error>Boot failed</error>');
            return Command::FAILURE;
        }

        // Initialize storage
        $dbPath = $boot->workspacePath() . '/data/coqui.db';
        $storage = new SessionStorage($dbPath);
        $evaluationStore = new \CoquiBot\Coqui\Storage\EvaluationStore($storage->getPdo());
        $learnerOutcomeTracker = new LearnerOutcomeTracker($evaluationStore, new SkillLifecycleStore($storage->getPdo()));

        // Initialize notification publisher for task outcome notifications
        $notificationStore = $boot->notificationStore();
        $publisher = $notificationStore !== null ? new NotificationPublisher($notificationStore) : null;

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

        // Branch: tool tasks use direct execution, agent tasks use the full agent loop
        $toolName = $task['tool_name'] ?? null;

        if (is_string($toolName) && $toolName !== '') {
            return $this->executeToolTask($taskId, $task, $boot, $storage, $workDir, $unsafeMode, $output, $publisher);
        }

        $sessionId = $task['session_id'];
        $prompt = $task['prompt'];
        $role = $task['role'] ?? 'orchestrator';
        $workScopeSessionId = $task['parent_session_id'] ?? null;
        if ($workScopeSessionId === '') {
            $workScopeSessionId = null;
        }
        $taskProjectId = $task['project_id'] ?? null;
        if ($taskProjectId === '') {
            $taskProjectId = null;
        }
        $taskSprintId = $task['sprint_id'] ?? null;
        if ($taskSprintId === '') {
            $taskSprintId = null;
        }
        $resolvedMax = $boot->roleResolver()->resolveMaxIterations($role);
        $dbMax = isset($task['max_iterations']) ? (int) $task['max_iterations'] : $resolvedMax;
        // Background tasks are always clamped for safety (even if role allows unlimited)
        $cap = $boot->config()->getBackgroundTaskMaxIterations();
        $maxIterations = max(1, min($dbMax, $cap));

        // Update status to running
        $storage->updateTaskStatus($taskId, 'running', ['pid' => getmypid()]);
        $storage->updateTaskHeartbeat($taskId);

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
        $agentRunner = AgentRunnerFactory::create(
            boot: $boot,
            projectRoot: $workDir,
            storage: $storage,
            observer: new NullObserver(),
            unsafeMode: $unsafeMode,
            toolExecutor: new ConcurrentToolExecutor(),
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
                role: $role,
                maxIterations: $maxIterations,
                workScopeSessionId: $workScopeSessionId,
                defaultProjectId: $taskProjectId,
                defaultSprintId: $taskSprintId,
            );

            if ($cancellationToken->isCancelled()) {
                $this->publishTaskNotification($publisher, $task, $taskId, 'cancelled', 'Task cancelled');
                $storage->updateTaskStatus($taskId, 'cancelled', [
                    'result' => $turnResult->content,
                ]);
                $learnerOutcomeTracker->recordFromTask($task, 'cancelled', $turnResult->content, null);
                $storage->appendTaskEvent($taskId, 'cancelled', [
                    'message' => 'Task was cancelled via SIGTERM',
                ]);

                return 2;
            }

            if ($turnResult->error !== null) {
                $this->publishTaskNotification($publisher, $task, $taskId, 'failed', 'Task failed', $turnResult->error);
                $storage->updateTaskStatus($taskId, 'failed', [
                    'error' => $turnResult->error,
                    'result' => $turnResult->content,
                ]);
                $learnerOutcomeTracker->recordFromTask($task, 'failed', $turnResult->content, $turnResult->error);
                $storage->appendTaskEvent($taskId, 'failed', [
                    'error' => $turnResult->error,
                    'duration_ms' => $turnResult->durationMs,
                ]);

                return Command::FAILURE;
            }

            $this->publishTaskNotification($publisher, $task, $taskId, 'completed', 'Task completed');
            $storage->updateTaskStatus($taskId, 'completed', [
                'result' => $turnResult->content,
            ]);
            $learnerOutcomeTracker->recordFromTask($task, 'completed', $turnResult->content, null);
            $storage->appendTaskEvent($taskId, 'completed', [
                'duration_ms' => $turnResult->durationMs,
                'iterations' => $turnResult->iterations,
                'total_tokens' => $turnResult->totalTokens,
                'tools_used' => $turnResult->toolsUsed,
            ]);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->publishTaskNotification($publisher, $task, $taskId, 'failed', 'Task failed', $e->getMessage());
            $storage->updateTaskStatus($taskId, 'failed', [
                'error' => $e->getMessage(),
            ]);
            $learnerOutcomeTracker->recordFromTask($task, 'failed', null, $e->getMessage());
            $storage->appendTaskEvent($taskId, 'failed', [
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * Execute a background tool task — direct tool execution, no LLM.
     *
     * @param array<string, mixed> $task
     */
    private function executeToolTask(
        string $taskId,
        array $task,
        BootManager $boot,
        SessionStorage $storage,
        string $workDir,
        bool $unsafeMode,
        OutputInterface $output,
        ?NotificationPublisher $publisher = null,
    ): int {
        $toolName = (string) $task['tool_name'];
        $argumentsJson = (string) ($task['tool_arguments'] ?? '{}');

        $arguments = json_decode($argumentsJson, true);
        if (!is_array($arguments)) {
            $storage->updateTaskStatus($taskId, 'failed', [
                'error' => 'Invalid tool_arguments JSON in task record',
            ]);
            $storage->appendTaskEvent($taskId, 'failed', [
                'error' => 'Invalid tool_arguments JSON',
            ]);

            return Command::FAILURE;
        }

        // Update status to running
        $storage->updateTaskStatus($taskId, 'running', ['pid' => getmypid()]);
        $storage->updateTaskHeartbeat($taskId);

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

        $storage->appendTaskEvent($taskId, 'tool_start', [
            'tool_name' => $toolName,
            'arguments' => $arguments,
        ]);

        $startTime = hrtime(true);

        try {
            // Check cancellation before execution
            if ($cancellationToken->isCancelled()) {
                $this->publishTaskNotification($publisher, $task, $taskId, 'cancelled', 'Tool task cancelled');
                $storage->updateTaskStatus($taskId, 'cancelled');
                $storage->appendTaskEvent($taskId, 'cancelled', [
                    'message' => 'Cancelled before tool execution started',
                ]);

                return 2;
            }

            $executor = new BackgroundToolExecutor(
                boot: $boot,
                projectRoot: $workDir,
                unsafeMode: $unsafeMode,
            );

            $toolResult = $executor->execute($toolName, $arguments);
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            // Check cancellation after execution (token is set by SIGTERM signal handler)
            if ($cancellationToken->isCancelled()) { // @phpstan-ignore if.alwaysFalse
                $this->publishTaskNotification($publisher, $task, $taskId, 'cancelled', 'Tool task cancelled');
                $storage->updateTaskStatus($taskId, 'cancelled', [
                    'result' => $toolResult->content,
                ]);
                $storage->appendTaskEvent($taskId, 'cancelled', [
                    'message' => 'Cancelled via SIGTERM',
                    'duration_ms' => $durationMs,
                ]);

                return 2;
            }

            if ($toolResult->status === \CarmeloSantana\PHPAgents\Enum\ToolResultStatus::Error) {
                $this->publishTaskNotification($publisher, $task, $taskId, 'failed', 'Tool task failed', $toolResult->content);
                $storage->updateTaskStatus($taskId, 'failed', [
                    'error' => $toolResult->content,
                ]);
                $storage->appendTaskEvent($taskId, 'tool_error', [
                    'tool_name' => $toolName,
                    'error' => mb_substr($toolResult->content, 0, 2000),
                    'duration_ms' => $durationMs,
                ]);

                return Command::FAILURE;
            }

            $this->publishTaskNotification($publisher, $task, $taskId, 'completed', 'Tool task completed');
            $storage->updateTaskStatus($taskId, 'completed', [
                'result' => $toolResult->content,
            ]);
            $storage->appendTaskEvent($taskId, 'tool_result', [
                'tool_name' => $toolName,
                'duration_ms' => $durationMs,
                'result_length' => mb_strlen($toolResult->content),
            ]);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
            $this->publishTaskNotification($publisher, $task, $taskId, 'failed', 'Tool task failed', $e->getMessage());
            $storage->updateTaskStatus($taskId, 'failed', [
                'error' => $e->getMessage(),
            ]);
            $storage->appendTaskEvent($taskId, 'tool_error', [
                'tool_name' => $toolName,
                'error' => $e->getMessage(),
                'duration_ms' => $durationMs,
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * Publish a task outcome notification to the parent session.
     *
     * Routes the notification to the user-facing conversation session
     * (via parent_session_id) and uses fingerprinting to prevent duplicates.
     * Failures are silently swallowed — notifications must never break task execution.
     *
     * @param array<string, mixed> $task
     */
    private function publishTaskNotification(
        ?NotificationPublisher $publisher,
        array $task,
        string $taskId,
        string $outcome,
        string $title,
        ?string $error = null,
    ): void {
        if ($publisher === null) {
            return;
        }

        try {
            $targetSession = NotificationPublisher::resolveTargetSession(
                sessionId: (string) ($task['session_id'] ?? ''),
                parentSessionId: $task['parent_session_id'] ?? null,
            );

            $taskTitle = $task['title'] ?? $task['prompt'] ?? '';
            $displayTitle = mb_strlen((string) $taskTitle) > 80
                ? mb_substr((string) $taskTitle, 0, 77) . '...'
                : (string) $taskTitle;

            $notifTitle = $displayTitle !== '' ? "{$title}: {$displayTitle}" : $title;

            $kind = match ($outcome) {
                'completed' => 'task.completed',
                'cancelled' => 'task.cancelled',
                default => 'task.failed',
            };

            $priority = $outcome === 'failed' ? 'high' : 'normal';

            $publisher->publish(
                sessionId: $targetSession,
                kind: $kind,
                title: $notifTitle,
                message: $error !== null ? mb_substr($error, 0, 200) : null,
                class: 'informational',
                priority: $priority,
                fingerprint: NotificationPublisher::taskFingerprint($taskId, $outcome),
                sourceType: 'background_task',
                sourceId: $taskId,
            );
        } catch (\Throwable) {
            // Never break task execution for notification failures
        }
    }
}
