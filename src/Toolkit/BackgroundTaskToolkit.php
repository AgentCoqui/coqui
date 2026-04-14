<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Toolkit;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Coqui\Api\ApiHealthCheck;
use CoquiBot\Coqui\Support\JsonHelper;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Contract\CoquiDefaults;
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
    private int $maxIterationsCap;

    public function __construct(
        private SessionStorage $storage,
        private string $parentSessionId,
        private ?RoleResolver $roleResolver = null,
        int $maxIterationsCap = CoquiDefaults::BACKGROUND_TASK_MAX_ITERATIONS,
        private ?string $expectedWorkspacePath = null,
        private ?\Closure $healthCheck = null,
    ) {
        $this->maxIterationsCap = max(1, $maxIterationsCap);
    }

    public function tools(): array
    {
        return [
            $this->startBackgroundTaskTool(),
            $this->startBackgroundToolTool(),
            $this->taskStatusTool(),
            $this->listTasksTool(),
            $this->cancelTaskTool(),
        ];
    }

    public function guidelines(): string
    {
        return <<<'GUIDELINES'
        ## Background Tasks & Tools

        Use background tasks for long-running operations that would block the main conversation:
        - Complex research tasks requiring many tool calls
        - Multi-step code generation or refactoring
        - Tasks that may take more than 30 seconds

        Background tasks run in a separate process with their own agent instance.
        You can monitor their progress and inject additional input while they run.

        ### Background Tool Execution

        Use `start_background_tool` to run a specific tool asynchronously when you don't need
        the result immediately. Unlike `start_background_task` (which spawns a full agent),
        `start_background_tool` executes a single tool call directly — no LLM involved, just
        the tool's execute() method with the arguments you provide.

        Use `start_background_tool` when:
        - A tool call may take a long time (e.g. web scraping, large file processing)
        - You want to continue working while the tool runs
        - The result is not needed for your immediate next step

        Both task types share the same lifecycle (pending → running → completed/failed) and
        can be monitored with `task_status`, `list_tasks`, and `cancel_task`.

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
                    description: sprintf('Maximum number of agent iterations (1-%d, default: 25)', $this->maxIterationsCap),
                    required: false,
                    integer: true,
                    minimum: 1,
                    maximum: $this->maxIterationsCap,
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

    private function startBackgroundToolTool(): Tool
    {
        return new Tool(
            name: 'start_background_tool',
            description: 'Run a specific tool in the background. The tool executes directly (no LLM agent) with the arguments you provide. Use this when a tool call may take a long time and you want to continue working.',
            parameters: [
                new StringParameter(
                    name: 'tool_name',
                    description: 'The exact name of the tool to execute (e.g. "web_search", "read_file")',
                    required: true,
                ),
                new StringParameter(
                    name: 'arguments',
                    description: 'JSON-encoded arguments to pass to the tool (e.g. {"query": "PHP 8.4 features"})',
                    required: true,
                ),
                new StringParameter(
                    name: 'title',
                    description: 'Short human-readable title for the task (for display purposes)',
                    required: true,
                ),
            ],
            callback: fn(array $args): ToolResult => $this->executeStartBackgroundTool($args),
        );
    }

    /**
     * @param array<string, mixed> $args
     */
    private function executeStartTask(array $args): ToolResult
    {
        if (($healthError = $this->ensureDispatchReady()) !== null) {
            return ToolResult::error($healthError);
        }

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
        $maxIterations = max(1, min($maxIterations, $this->maxIterationsCap));

        // Create a dedicated session for the task
        $model = 'background-task'; // Resolved at runtime by TaskRunCommand
        $sessionId = $this->storage->createSession($role, $model);

        // Propagate active project context from parent session
        $parentProjectId = $this->storage->getActiveProjectId($this->parentSessionId);
        if ($parentProjectId !== null) {
            $this->storage->setActiveProject($sessionId, $parentProjectId);
        }

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

    /**
     * @param array<string, mixed> $args
     */
    private function executeStartBackgroundTool(array $args): ToolResult
    {
        if (($healthError = $this->ensureDispatchReady()) !== null) {
            return ToolResult::error($healthError);
        }

        $toolName = trim((string) ($args['tool_name'] ?? ''));

        if ($toolName === '') {
            return ToolResult::error('Missing required "tool_name" parameter');
        }

        $argumentsJson = trim((string) ($args['arguments'] ?? ''));

        if ($argumentsJson === '') {
            return ToolResult::error('Missing required "arguments" parameter — provide JSON-encoded arguments');
        }

        // Validate JSON
        $decoded = json_decode($argumentsJson, true);
        if (!is_array($decoded)) {
            return ToolResult::error('Invalid "arguments" — must be a valid JSON object (e.g. {"key": "value"})');
        }

        $title = trim((string) ($args['title'] ?? ''));

        if ($title === '') {
            return ToolResult::error('Missing required "title" parameter');
        }

        // Create a lightweight session for event tracking
        $model = 'background-tool';
        $sessionId = $this->storage->createSession('tool', $model);

        // Create the task record with tool metadata
        $taskId = $this->storage->createTask(
            sessionId: $sessionId,
            prompt: sprintf('Execute tool: %s', $toolName),
            role: 'tool',
            parentSessionId: $this->parentSessionId,
            title: $title,
            maxIterations: 1,
            toolName: $toolName,
            toolArguments: $argumentsJson,
        );

        return ToolResult::success(json_encode([
            'task_id' => $taskId,
            'session_id' => $sessionId,
            'status' => 'pending',
            'tool_name' => $toolName,
            'title' => $title,
            'message' => 'Background tool execution queued. Use task_status to monitor progress.',
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: 'Tool task created');
    }

    private function ensureDispatchReady(): ?string
    {
        $health = ($this->healthCheck ?? fn(): array => ApiHealthCheck::check(
            expectedWorkspacePath: $this->expectedWorkspacePath,
            requireTaskManager: true,
        ))();

        if (($health['ok'] ?? false) === true) {
            return null;
        }

        return $health['error'] ?? 'API background task manager is not ready.';
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

        $metadata = JsonHelper::decodeJsonObject($task['metadata'] ?? null);
        if ($metadata !== null) {
            $summary['metadata'] = $metadata;
        }

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
            'metadata' => JsonHelper::decodeJsonObject($t['metadata'] ?? null),
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
