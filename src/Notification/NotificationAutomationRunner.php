<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Notification;

use CoquiBot\Coqui\Contract\CoquiDefaults;
use CoquiBot\Coqui\Storage\NotificationStore;

/**
 * API-only automation runner for actionable notifications.
 *
 * Claims allowlisted actionable notifications, dispatches them to a handler,
 * and transitions each notification to completed, pending-with-retry, or failed.
 */
final class NotificationAutomationRunner
{
    private readonly string $runnerId;

    /** @var array<string, NotificationAutomationHandlerInterface> */
    private array $handlers = [];

    /** @var array{processed:int,claimed:int,completed:int,retried:int,reclaimed:int,failed:int,skipped:int,perKind:array<string, array<string, int>>} */
    private array $stats = [
        'processed' => 0,
        'claimed' => 0,
        'completed' => 0,
        'retried' => 0,
        'reclaimed' => 0,
        'failed' => 0,
        'skipped' => 0,
        'perKind' => [],
    ];

    /**
     * @param iterable<NotificationAutomationHandlerInterface> $handlers
     */
    public function __construct(
        private readonly NotificationStore $store,
        iterable $handlers,
        private readonly int $leaseSeconds = CoquiDefaults::NOTIFICATION_AUTOMATION_LEASE_SECONDS,
        private readonly int $batchSize = CoquiDefaults::NOTIFICATION_AUTOMATION_BATCH_SIZE,
        private readonly int $maxAttempts = CoquiDefaults::NOTIFICATION_AUTOMATION_MAX_ATTEMPTS,
        private readonly int $retryDelaySeconds = CoquiDefaults::NOTIFICATION_AUTOMATION_RETRY_DELAY_SECONDS,
        ?string $runnerId = null,
    ) {
        foreach ($handlers as $handler) {
            $this->handlers[$handler->kind()] = $handler;
        }

        $this->runnerId = $runnerId ?? 'notification-automation:' . bin2hex(random_bytes(6));
    }

    public function tick(): void
    {
        if ($this->handlers === []) {
            return;
        }

        $notifications = $this->store->getPendingActionableGlobal(array_keys($this->handlers), $this->batchSize);

        foreach ($notifications as $notification) {
            $this->processNotification($notification);
        }
    }

    public function reclaim(): void
    {
        $result = $this->store->reclaimExpiredClaims($this->maxAttempts, $this->retryDelaySeconds);
        $this->stats['reclaimed'] += $result['requeued'];
        $this->stats['failed'] += $result['failed'];
    }

    /**
     * @return array{runnerId:string,processed:int,claimed:int,completed:int,retried:int,reclaimed:int,failed:int,skipped:int,perKind:array<string, array<string, int>>}
     */
    public function stats(): array
    {
        return [
            'runnerId' => $this->runnerId,
            ...$this->stats,
        ];
    }

    /**
     * @param array<string, mixed> $notification
     */
    private function processNotification(array $notification): void
    {
        $notificationId = (string) ($notification['id'] ?? '');
        $kind = (string) ($notification['kind'] ?? '');

        if ($notificationId === '' || $kind === '') {
            $this->stats['skipped']++;
            return;
        }

        $handler = $this->handlers[$kind] ?? null;
        if ($handler === null) {
            $this->stats['skipped']++;
            $this->incrementPerKind($kind, 'skipped');
            return;
        }

        if (!$this->store->claim($notificationId, $this->runnerId, $this->leaseSeconds)) {
            return;
        }

        $this->stats['processed']++;
        $this->stats['claimed']++;
        $this->incrementPerKind($kind, 'claimed');

        try {
            $result = $handler->handle($notification);
        } catch (\Throwable $e) {
            $result = NotificationAutomationResult::retry($e->getMessage(), $this->retryDelaySeconds);
        }

        match ($result->outcome) {
            NotificationAutomationOutcome::Completed => $this->markCompleted($notificationId, $kind),
            NotificationAutomationOutcome::Skipped => $this->markSkipped($notificationId, $kind),
            NotificationAutomationOutcome::Retry => $this->markRetry($notificationId, $kind, $result),
            NotificationAutomationOutcome::Failed => $this->markFailed($notificationId, $kind, $result),
        };
    }

    private function markCompleted(string $notificationId, string $kind): void
    {
        $this->store->completeClaim($notificationId);
        $this->stats['completed']++;
        $this->incrementPerKind($kind, 'completed');
    }

    private function markSkipped(string $notificationId, string $kind): void
    {
        $this->store->completeClaim($notificationId);
        $this->stats['skipped']++;
        $this->incrementPerKind($kind, 'skipped');
    }

    private function markRetry(string $notificationId, string $kind, NotificationAutomationResult $result): void
    {
        $message = $result->message ?? 'Actionable notification will be retried.';
        $this->store->retryClaim($notificationId, $message, $result->retryDelaySeconds ?? $this->retryDelaySeconds);
        $this->stats['retried']++;
        $this->incrementPerKind($kind, 'retried');
    }

    private function markFailed(string $notificationId, string $kind, NotificationAutomationResult $result): void
    {
        $this->store->failClaim($notificationId, $result->message ?? 'Actionable notification failed.');
        $this->stats['failed']++;
        $this->incrementPerKind($kind, 'failed');
    }

    private function incrementPerKind(string $kind, string $metric): void
    {
        if (!isset($this->stats['perKind'][$kind])) {
            $this->stats['perKind'][$kind] = [];
        }

        $this->stats['perKind'][$kind][$metric] = ($this->stats['perKind'][$kind][$metric] ?? 0) + 1;
    }
}