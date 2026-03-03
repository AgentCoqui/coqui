<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Toolkit;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Storage\SessionStorage;

/**
 * LLM-facing tools for managing background tasks.
 *
 * These tools let the agent create, monitor, and cancel long-running
 * background tasks that execute in separate processes. Available in both
 * API mode (tasks start immediately) and REPL mode (tasks queue as
 * 'pending' and execute when the API server picks them up).
 *
 * Task creation writes a database record; the BackgroundTaskManager
 * (running in the API server process) picks up pending tasks and
 * spawns child processes.
 */
final readonly class BackgroundTaskToolkit implements ToolkitInterface
{
    public function __construct(
        private SessionStorage $storage,
        private string $parentSessionId,
        private ?RoleResolver $roleResolver = null,
    ) {}

    public function tools(): array
    {
        return [
            $this->startBackgroundTaskTool(),
            $this->taskStatusTool(),
            $this->listTasksTool(),
            $this->cancelTaskTool(),
        ];
    }

    public function guidelines(): string
    {
        return <<<'GUIDELINES'
        ## Background Tasks

        Use background tasks for long-running operations that would block the main conversation:
        - Complex research tasks requiring many tool calls
        - Multi-step code generation or refactoring
        - Tasks that may take more than 30 seconds

        Background tasks run in a separate process with their own agent instance.
        You can monitor their progress and inject additional input while they run.

        Tasks are queued in the database and executed by the API server's task manager.
        If the API server is not running, tasks remain in 'pending' status until it starts.
        GUIDELINES;
    }

    private function startBackgroundTaskTool(): Tool
    {
        return new Tool(
            name: 'start_background_task',
            description: 'Start a long-running task in a separate background process. The task gets its own agent instance and runs independently.',
            parameters: [
                new StringParameter(
                    name: 'prompt',
                    description: 'The detailed prompt/instructions for the background task',
                    required: true,
                ),
                new StringParameter(
                    name: 'title',
                    description: 'Short human-readable title for the task (for display purposes)',
                    required: true,
                ),
                new StringParameter(
                    name: 'role',
                    description: 'The model role to use for the task agent (default: orchestrator)',
                    required: false,
                ),
                new NumberParameter(
                    name: 'max_iterations',
                    description: 'Maximum number of agent iterations (1-100, default: 25)',
                    required: false,
                    integer: true,
                    minimum: 1,
                    maximum: 100,
                ),
            ],
            callback: fn(array $args): ToolResult => $this->executeStartTask($args),
        );
    }

    private function taskStatusTool(): Tool
    {
        return new Tool(
            name: 'task_status',
            description: 'Get the current status and details of a background task.',
            parameters: [
                new StringParameter(
                    name: 'task_id',
                    description: 'The ID of the task to check',
                    required: true,
                ),
            ],
            callback: fn(array $args): ToolResult => $this->executeTaskStatus($args),
        );
    }

    private function listTasksTool(): Tool
    {
        return new Tool(
            name: 'list_tasks',
            description: 'List background tasks with optional status filter.',
            parameters: [
                new EnumParameter(
                    name: 'status',
                    description: 'Filter by status',
                    values: ['all', 'pending', 'running', 'completed', 'failed', 'cancelled'],
                    required: false,
                ),
                new NumberParameter(
                    name: 'limit',
                    description: 'Maximum number of tasks to return (default: 10)',
                    required: false,
                    integer: true,
                    minimum: 1,
                    maximum: 50,
                ),
            ],
            callback: fn(array $args): ToolResult => $this->executeListTasks($args),
        );
    }

    private function cancelTaskTool(): Tool
    {
        return new Tool(
            name: 'cancel_task',
            description: 'Cancel a pending or running background task.',
            parameters: [
                new StringParameter(
                    name: 'task_id',
                    description: 'The ID of the task to cancel',
                    required: true,
                ),
            ],
            callback: fn(array $args): ToolResult => $this->executeCancelTask($args),
        );
    }

    /**
     * @param array<string, mixed> $args
     */
    private function executeStartTask(array $args): ToolResult
    {
        $prompt = trim((string) ($args['prompt'] ?? ''));

        if ($prompt === '') {
            return ToolResult::error('Missing required "prompt" parameter');
        }

        $title = trim((string) ($args['title'] ?? ''));

        if ($title === '') {
            return ToolResult::error('Missing required "title" parameter');
        }

        $role = trim((string) ($args['role'] ?? 'orchestrator'));
        $maxIterations = (int) ($args['max_iterations'] ?? ($this->roleResolver?->resolveMaxIterations($role) ?? 25));
        $maxIterations = max(1, min($maxIterations, 100));

        // Create a dedicated session for the task
        $model = 'background-task'; // Resolved at runtime by TaskRunCommand
        $sessionId = $this->storage->createSession($role, $model);

        // Create the task record — status starts as 'pending'
        $taskId = $this->storage->createTask(
            sessionId: $sessionId,
            prompt: $prompt,
            role: $role,
            parentSessionId: $this->parentSessionId,
            title: $title,
            maxIterations: $maxIterations,
        );

        return ToolResult::success(json_encode([
            'task_id' => $taskId,
            'session_id' => $sessionId,
            'status' => 'pending',
            'title' => $title,
            'message' => 'Task created and queued. Use task_status to monitor progress.',
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: 'Task created');
    }

    private const RESULT_PREVIEW_LENGTH = 2000;

    /**
     * @param array<string, mixed> $args
     */
    private function executeTaskStatus(array $args): ToolResult
    {
        $taskId = trim((string) ($args['task_id'] ?? ''));

        if ($taskId === '') {
            return ToolResult::error('Missing required "task_id" parameter');
        }

        $task = $this->storage->getTask($taskId);

        if ($task === null) {
            return ToolResult::error(sprintf('Task "%s" not found', $taskId));
        }

        // Include recent events for context
        $events = $this->storage->getTaskEvents($taskId, limit: 20);

        $summary = [
            'id' => $task['id'],
            'status' => $task['status'],
            'title' => $task['title'],
            'role' => $task['role'],
            'created_at' => $task['created_at'],
            'started_at' => $task['started_at'],
            'completed_at' => $task['completed_at'],
        ];

        if ($task['result'] !== null) {
            $result = $task['result'];
            if (mb_strlen($result) > self::RESULT_PREVIEW_LENGTH) {
                $summary['result'] = mb_substr($result, 0, self::RESULT_PREVIEW_LENGTH);
                $summary['result_truncated'] = true;
                $summary['result_full_length'] = mb_strlen($result);
            } else {
                $summary['result'] = $result;
            }
        }

        if ($task['error'] !== null) {
            $error = $task['error'];
            if (mb_strlen($error) > self::RESULT_PREVIEW_LENGTH) {
                $summary['error'] = mb_substr($error, 0, self::RESULT_PREVIEW_LENGTH);
                $summary['error_truncated'] = true;
            } else {
                $summary['error'] = $error;
            }
        }

        if (!empty($events)) {
            $summary['recent_events'] = array_map(
                fn(array $e): array => [
                    'type' => $e['event_type'],
                    'data' => json_decode($e['data'] ?? '{}', true),
                    'time' => $e['created_at'],
                ],
                array_slice($events, -10),
            );
        }

        return ToolResult::success(
            json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: 'Task data',
        );
    }

    /**
     * @param array<string, mixed> $args
     */
    private function executeListTasks(array $args): ToolResult
    {
        $status = isset($args['status']) ? trim((string) $args['status']) : null;

        if ($status === 'all') {
            $status = null;
        }

        $limit = (int) ($args['limit'] ?? 10);
        $limit = max(1, min($limit, 50));

        $tasks = $this->storage->listTasks($status, $limit);

        $taskList = array_map(fn(array $t): array => [
            'id' => $t['id'],
            'status' => $t['status'],
            'title' => $t['title'],
            'role' => $t['role'],
            'created_at' => $t['created_at'],
            'completed_at' => $t['completed_at'],
        ], $tasks);

        $counts = $this->storage->getTaskCounts();

        return ToolResult::success(json_encode([
            'tasks' => $taskList,
            'count' => count($taskList),
            'counts' => $counts,
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: 'Task list');
    }

    /**
     * @param array<string, mixed> $args
     */
    private function executeCancelTask(array $args): ToolResult
    {
        $taskId = trim((string) ($args['task_id'] ?? ''));

        if ($taskId === '') {
            return ToolResult::error('Missing required "task_id" parameter');
        }

        $task = $this->storage->getTask($taskId);

        if ($task === null) {
            return ToolResult::error(sprintf('Task "%s" not found', $taskId));
        }

        if (in_array($task['status'], ['completed', 'failed', 'cancelled'], true)) {
            return ToolResult::error(sprintf(
                'Task "%s" is already in terminal state "%s" and cannot be cancelled',
                $taskId,
                $task['status'],
            ));
        }

        // For pending tasks, cancel directly in DB
        if ($task['status'] === 'pending') {
            $this->storage->updateTaskStatus($taskId, 'cancelled');
            $this->storage->appendTaskEvent($taskId, 'cancelled', [
                'message' => 'Cancelled by agent while pending',
            ]);

            return ToolResult::success(json_encode([
                'task_id' => $taskId,
                'status' => 'cancelled',
                'message' => 'Task cancelled successfully',
            ], JSON_UNESCAPED_SLASHES) ?: 'Cancelled');
        }

        // For running tasks, mark as cancelling — the BackgroundTaskManager
        // will detect this on its next tick and send SIGTERM to the process
        $this->storage->updateTaskStatus($taskId, 'cancelling');
        $this->storage->appendTaskEvent($taskId, 'cancel_requested', [
            'message' => 'Cancellation requested by agent',
        ]);

        return ToolResult::success(json_encode([
            'task_id' => $taskId,
            'status' => 'cancelling',
            'message' => 'Cancellation signal sent. The task will stop after its current iteration.',
        ], JSON_UNESCAPED_SLASHES) ?: 'Cancel requested');
    }
}
