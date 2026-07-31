<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Notification;

use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Contract\SystemRole;

final readonly class EscalateLoopFailureAction implements NotificationAutomationHandlerInterface
{
    private const int MAX_ITERATIONS = 24;

    public function __construct(
        private SessionStorage $storage,
        private LoopStore $loopStore,
    ) {}

    #[\Override]
    public function kind(): string
    {
        return 'loop.failed';
    }

    /**
     * @param array<string, mixed> $notification
     */
    #[\Override]
    public function handle(array $notification): NotificationAutomationResult
    {
        $notificationId = (string) ($notification['id'] ?? '');
        $loopId = (string) ($notification['source_id'] ?? '');

        if ($notificationId === '' || $loopId === '') {
            return NotificationAutomationResult::failed('Missing actionable loop notification identity.');
        }

        $existing = $this->storage->findTaskByAutomationNotificationId($notificationId);
        if ($existing !== null) {
            return NotificationAutomationResult::completed('Loop investigation task already exists.');
        }

        $loop = $this->loopStore->getLoop($loopId);
        if ($loop === null) {
            return NotificationAutomationResult::failed('Loop no longer exists.');
        }

        if (($loop['status'] ?? null) !== 'failed') {
            return NotificationAutomationResult::skipped('Loop is no longer failed.');
        }

        $targetSessionId = NotificationPublisher::resolveTargetSession(
            sessionId: (string) ($loop['session_id'] ?? ''),
        );
        $targetSession = $this->storage->getSession($targetSessionId);
        $persona = is_array($targetSession) && is_string($targetSession['persona_id'] ?? null) && $targetSession['persona_id'] !== ''
            ? $targetSession['persona_id']
            : null;

        $executionSessionId = $this->storage->createSession(SystemRole::Orchestrator->value, '', $persona, visibility: 'hidden');
        $activeProjectId = $this->storage->getActiveProjectId($targetSessionId);
        if ($activeProjectId !== null) {
            $this->storage->setActiveProject($executionSessionId, $activeProjectId);
        }

        $metadata = [
            'automation' => [
                'notification_id' => $notificationId,
                'action' => 'escalate_loop_failure',
                'loop_id' => $loopId,
                'trigger_kind' => $this->kind(),
            ],
        ];

        // The loops.project_id column was dropped with the protocol's Project
        // removal (D3); the resolved project rides in the configuration snapshot.
        $loopConfig = json_decode((string) ($loop['configuration'] ?? ''), true);
        $loopProjectId = is_array($loopConfig) && is_string($loopConfig['resolved_project_id'] ?? null) && $loopConfig['resolved_project_id'] !== ''
            ? $loopConfig['resolved_project_id']
            : null;

        $followUpTaskId = $this->storage->createTask(
            sessionId: $executionSessionId,
            prompt: $this->buildPrompt($loop, $notification),
            role: SystemRole::Orchestrator->value,
            parentSessionId: $targetSessionId,
            title: $this->buildTitle($loop),
            maxIterations: self::MAX_ITERATIONS,
            projectId: $loopProjectId,
            metadata: $metadata,
        );

        $this->storage->appendTaskEvent($followUpTaskId, 'automation_loop_investigation_created', [
            'notification_id' => $notificationId,
            'loop_id' => $loopId,
        ]);

        return NotificationAutomationResult::completed('Created loop investigation task ' . $followUpTaskId . '.');
    }

    /**
     * @param array<string, mixed> $loop
     * @param array<string, mixed> $notification
     */
    private function buildPrompt(array $loop, array $notification): string
    {
        $name = is_string($loop['name'] ?? null) && $loop['name'] !== ''
            ? $loop['name']
            : (is_string($loop['definition_name'] ?? null) && $loop['definition_name'] !== '' ? $loop['definition_name'] : 'unknown');
        $message = is_string($notification['message'] ?? null) && $notification['message'] !== ''
            ? $notification['message']
            : 'No failure detail was captured.';

        return <<<PROMPT
Investigate failed loop {$name} ({$loop['id']}).

Failure detail: {$message}

Review the loop state, inspect the related files and artifacts, determine the root cause, and take the smallest safe next step to recover progress. If direct recovery is not appropriate, produce a concrete remediation update for the user and create any necessary follow-up work.
PROMPT;
    }

    /**
     * @param array<string, mixed> $loop
     */
    private function buildTitle(array $loop): string
    {
        $name = is_string($loop['name'] ?? null) && $loop['name'] !== ''
            ? $loop['name']
            : (is_string($loop['definition_name'] ?? null) && $loop['definition_name'] !== ''
                ? $loop['definition_name']
                : (string) ($loop['id'] ?? 'unknown'));

        return 'Investigate failed loop: ' . $name;
    }
}