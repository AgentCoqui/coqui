<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Notification;

use CoquiBot\Coqui\Contract\SystemRole;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Support\JsonHelper;

final readonly class RetryBackgroundTaskAction implements NotificationAutomationHandlerInterface
{
    public function __construct(
        private SessionStorage $storage,
    ) {}

    #[\Override]
    public function kind(): string
    {
        return 'task.failed';
    }

    /**
     * @param array<string, mixed> $notification
     */
    #[\Override]
    public function handle(array $notification): NotificationAutomationResult
    {
        $notificationId = (string) ($notification['id'] ?? '');
        $taskId = (string) ($notification['source_id'] ?? '');

        if ($notificationId === '' || $taskId === '') {
            return NotificationAutomationResult::failed('Missing actionable task notification identity.');
        }

        $existing = $this->storage->findTaskByAutomationNotificationId($notificationId);
        if ($existing !== null) {
            return NotificationAutomationResult::completed('Retry task already exists.');
        }

        $task = $this->storage->getTask($taskId);
        if ($task === null) {
            return NotificationAutomationResult::failed('Original background task no longer exists.');
        }

        if (($task['status'] ?? null) !== 'failed') {
            return NotificationAutomationResult::skipped('Original task is no longer failed.');
        }

        $targetSessionId = NotificationPublisher::resolveTargetSession(
            sessionId: (string) ($task['session_id'] ?? ''),
            parentSessionId: isset($task['parent_session_id']) ? (string) $task['parent_session_id'] : null,
        );

        $executionSessionId = $this->storage->createSession(
            modelRole: (string) ($task['role'] ?? SystemRole::Orchestrator->value),
            model: '',
        );

        $activeProjectId = $this->storage->getActiveProjectId($targetSessionId);
        if ($activeProjectId !== null) {
            $this->storage->setActiveProject($executionSessionId, $activeProjectId);
        }

        $originalMetadata = JsonHelper::decodeJsonObject($task['metadata'] ?? null) ?? [];
        $metadata = array_replace_recursive($originalMetadata, [
            'automation' => [
                'notification_id' => $notificationId,
                'action' => 'retry_background_task',
                'original_task_id' => $taskId,
                'trigger_kind' => $this->kind(),
            ],
        ]);

        $followUpTaskId = $this->storage->createTask(
            sessionId: $executionSessionId,
            prompt: (string) ($task['prompt'] ?? ''),
            role: (string) ($task['role'] ?? SystemRole::Orchestrator->value),
            parentSessionId: $targetSessionId,
            title: $this->buildRetryTitle($task),
            maxIterations: max(1, (int) ($task['max_iterations'] ?? 25)),
            toolName: isset($task['tool_name']) && is_string($task['tool_name']) && $task['tool_name'] !== '' ? $task['tool_name'] : null,
            toolArguments: isset($task['tool_arguments']) && is_string($task['tool_arguments']) && $task['tool_arguments'] !== '' ? $task['tool_arguments'] : null,
            maxExecutionSeconds: max(1, (int) ($task['max_execution_seconds'] ?? 3600)),
            projectId: isset($task['project_id']) && is_string($task['project_id']) && $task['project_id'] !== '' ? $task['project_id'] : null,
            sprintId: isset($task['sprint_id']) && is_string($task['sprint_id']) && $task['sprint_id'] !== '' ? $task['sprint_id'] : null,
            metadata: $metadata,
        );

        $this->storage->appendTaskEvent($followUpTaskId, 'automation_retry_created', [
            'notification_id' => $notificationId,
            'original_task_id' => $taskId,
        ]);

        return NotificationAutomationResult::completed('Created retry task ' . $followUpTaskId . '.');
    }

    /**
     * @param array<string, mixed> $task
     */
    private function buildRetryTitle(array $task): string
    {
        $title = (string) ($task['title'] ?? 'Retry failed background task');

        return str_starts_with($title, 'Retry: ')
            ? $title
            : 'Retry: ' . $title;
    }

}