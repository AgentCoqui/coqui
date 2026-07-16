<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Command;

use CoquiBot\Coqui\Agent\ConcurrentToolExecutor;
use CoquiBot\Coqui\Contract\SystemRole;
use CoquiBot\Coqui\Api\DatabasePendingInputProvider;
use CoquiBot\Coqui\Api\ProcessCancellationToken;
use CoquiBot\Coqui\Config\AutoApprovalPolicy;
use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Config\ProfilePreferences;
use CoquiBot\Coqui\Command\WorkspaceOverrideResolver;
use CoquiBot\Coqui\Notification\NotificationPublisher;
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

        $sessionId = $task['session_id'];
        $prompt = $task['prompt'];
        $role = $task['role'] ?? SystemRole::Orchestrator->value;
        $workScopeSessionId = $task['parent_session_id'] ?? null;
        if ($workScopeSessionId === '') {
            $workScopeSessionId = null;
        }
        $taskProjectId = $task['project_id'] ?? null;
        if ($taskProjectId === '') {
            $taskProjectId = null;
        }
        $session = $storage->getSession($sessionId);
        $taskProfile = is_array($session) && is_string($session['profile'] ?? null) && $session['profile'] !== ''
            ? $session['profile']
            : null;
        if ($taskProfile !== null && $boot->profileDiscovery()->profileExists($taskProfile)) {
            $preferences = ProfilePreferences::fromProfilePath($boot->profileDiscovery()->getProfilePath($taskProfile));
            if (!$preferences->isRoleAllowed($role)) {
                $message = sprintf('Profile "%s" does not allow role "%s".', $taskProfile, $role);
                $storage->updateTaskStatus($taskId, 'failed', ['error' => $message]);
                $storage->appendTaskEvent($taskId, 'failed', ['error' => $message]);
                return 1;
            }
        }

        $resolvedMax = $boot->roleResolver()->resolveMaxIterations($role, $taskProfile);
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

        // Resolve the structured-question policy for this task. Loop stages carry
        // loop/stage identifiers in the task's handoff metadata; the loop's
        // `on_question` mode lives in its stored configuration snapshot. Plain
        // background tasks (no loop context) default to `block`.
        $onQuestion = \CoquiBot\Coqui\Contract\OnQuestionPolicy::Block;
        $loopId = null;
        $stageId = null;
        $loopBlock = null;
        $meta = is_string($task['metadata'] ?? null) ? json_decode($task['metadata'], true) : null;
        if (is_array($meta) && isset($meta['loop_id'])) {
            $loopId = (string) $meta['loop_id'];
            $stageId = isset($meta['stage_id']) ? (string) $meta['stage_id'] : null;
            $loopStore = new \CoquiBot\Coqui\Storage\LoopStore($storage->getPdo());
            $loopRow = $loopStore->getLoop($loopId);
            $config = is_array($loopRow) && is_string($loopRow['configuration'] ?? null)
                ? json_decode($loopRow['configuration'], true)
                : null;
            $onQuestion = \CoquiBot\Coqui\Contract\OnQuestionPolicy::fromString(
                is_array($config) && isset($config['on_question']) && is_string($config['on_question'])
                    ? $config['on_question']
                    : null,
            );
            // Block-mode ask_user escalates the loop to `blocked`; the operator
            // answers over REST, which reopens the iteration (Task 9).
            $loopBlock = new \CoquiBot\Coqui\Question\LoopQuestionBlockNotifier($loopStore);
        }
        $questionResponder = new \CoquiBot\Coqui\Question\PolicyQuestionResponder(
            $onQuestion,
            new \CoquiBot\Coqui\Question\QuestionPersistence($storage),
            $sessionId,
            loopBlock: $loopBlock,
            turnId: null,
            loopId: $loopId,
            stageId: $stageId,
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
                profile: $taskProfile,
                questionResponder: $questionResponder,
            );

            if ($cancellationToken->isCancelled()) {
                $this->publishTaskNotification($publisher, $task, $taskId, 'cancelled', 'Task cancelled');
                $storage->updateTaskStatus($taskId, 'cancelled', [
                    'result' => $turnResult->content,
                ]);
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
            $storage->appendTaskEvent($taskId, 'completed', [
                'duration_ms' => $turnResult->durationMs,
                'iterations' => $turnResult->iterations,
                'total_tokens' => $turnResult->totalTokens,
                'tools_used' => $turnResult->toolsUsed,
            ]);

            // Record budget exhaustion as a separate event for tracking/evaluation
            if ($turnResult->budgetExhausted) {
                $storage->appendTaskEvent($taskId, 'budget_exhausted', [
                    'iterations' => $turnResult->iterations,
                    'total_tokens' => $turnResult->totalTokens,
                ]);
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->publishTaskNotification($publisher, $task, $taskId, 'failed', 'Task failed', $e->getMessage());
            $storage->updateTaskStatus($taskId, 'failed', [
                'error' => $e->getMessage(),
            ]);
            $storage->appendTaskEvent($taskId, 'failed', [
                'error' => $e->getMessage(),
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

            // For loop-linked tasks the title is "Loop stage: role (iter N, stage M)".
            // Combine with the outcome verb directly ("Loop stage completed: role ...")
            // instead of the redundant "Task completed: Loop stage: role ...".
            if (str_starts_with($displayTitle, 'Loop stage:')) {
                $stageDetail = mb_substr($displayTitle, mb_strlen('Loop stage:'));
                $verb = match ($outcome) {
                    'completed' => 'completed',
                    'cancelled' => 'cancelled',
                    default => 'failed',
                };
                $notifTitle = "Loop stage {$verb}:{$stageDetail}";
            } else {
                $notifTitle = $displayTitle !== '' ? "{$title}: {$displayTitle}" : $title;
            }

            $kind = match ($outcome) {
                'completed' => 'task.completed',
                'cancelled' => 'task.cancelled',
                default => 'task.failed',
            };

            $priority = $outcome === 'failed' ? 'high' : 'normal';
            $message = $error !== null ? mb_substr($error, 0, 200) : null;
            $metadata = [
                'task_id' => $taskId,
                'task_session_id' => (string) ($task['session_id'] ?? ''),
                'parent_session_id' => isset($task['parent_session_id']) ? (string) $task['parent_session_id'] : null,
                'role' => (string) ($task['role'] ?? SystemRole::Orchestrator->value),
                'title' => $displayTitle,
            ];

            if ($outcome === 'failed') {
                $publisher->actionable(
                    sessionId: $targetSession,
                    kind: $kind,
                    title: $notifTitle,
                    message: $message,
                    priority: $priority,
                    fingerprint: NotificationPublisher::taskFingerprint($taskId, $outcome),
                    sourceType: 'background_task',
                    sourceId: $taskId,
                    metadata: $metadata,
                );

                return;
            }

            $publisher->info(
                sessionId: $targetSession,
                kind: $kind,
                title: $notifTitle,
                message: $message,
                fingerprint: NotificationPublisher::taskFingerprint($taskId, $outcome),
                sourceType: 'background_task',
                sourceId: $taskId,
                metadata: $metadata,
                priority: $priority,
            );
        } catch (\Throwable) {
            // Never break task execution for notification failures
        }
    }
}
