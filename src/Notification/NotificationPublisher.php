<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Notification;

use CoquiBot\Coqui\Storage\NotificationStore;

/**
 * Centralized notification publishing facade.
 *
 * Handles session routing (resolving the correct user-facing session from
 * parent/work-scope/execution session chains), message formatting, and
 * fingerprint-based deduplication. All notification producers should use
 * this class instead of writing to NotificationStore directly.
 *
 * Session resolution chain:
 *   1. Explicit targetSessionId (if provided)
 *   2. parentSessionId from the task/context
 *   3. Fallback sessionId (the task's own execution session)
 *
 * The goal is to route notifications to the human-visible conversation
 * session rather than an ephemeral child task session.
 */
final class NotificationPublisher
{
    public function __construct(
        private readonly NotificationStore $store,
        private readonly bool $enabled = true,
    ) {}

    /**
     * Publish a notification to the resolved target session.
     *
     * Returns the notification ID if created, null if disabled, deduplicated, or failed.
     *
     * @param array<string, mixed>|null $metadata
     */
    public function publish(
        string $sessionId,
        string $kind,
        string $title,
        ?string $message = null,
        string $class = 'informational',
        string $priority = 'normal',
        ?string $fingerprint = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?array $metadata = null,
        ?string $expiresAt = null,
    ): ?string {
        if (!$this->enabled) {
            return null;
        }

        return $this->store->create(
            sessionId: $sessionId,
            kind: $kind,
            title: $title,
            message: $message,
            class: $class,
            priority: $priority,
            fingerprint: $fingerprint,
            sourceType: $sourceType,
            sourceId: $sourceId,
            metadata: $metadata,
            expiresAt: $expiresAt,
        );
    }

    /**
     * Publish an informational notification.
     *
     * Convenience method for the most common notification type.
     *
     * @param array<string, mixed>|null $metadata
     */
    public function info(
        string $sessionId,
        string $kind,
        string $title,
        ?string $message = null,
        ?string $fingerprint = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?array $metadata = null,
        string $priority = 'normal',
    ): ?string {
        return $this->publish(
            sessionId: $sessionId,
            kind: $kind,
            title: $title,
            message: $message,
            class: 'informational',
            priority: $priority,
            fingerprint: $fingerprint,
            sourceType: $sourceType,
            sourceId: $sourceId,
            metadata: $metadata,
        );
    }

    /**
     * Publish an actionable notification eligible for autonomous continuation.
     *
     * Actionable notifications can be claimed by the NotificationAutomationRunner
     * and resolved into follow-up background tasks.
     *
     * @param array<string, mixed>|null $metadata
     */
    public function actionable(
        string $sessionId,
        string $kind,
        string $title,
        ?string $message = null,
        ?string $fingerprint = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?array $metadata = null,
        string $priority = 'high',
    ): ?string {
        return $this->publish(
            sessionId: $sessionId,
            kind: $kind,
            title: $title,
            message: $message,
            class: 'actionable',
            priority: $priority,
            fingerprint: $fingerprint,
            sourceType: $sourceType,
            sourceId: $sourceId,
            metadata: $metadata,
        );
    }

    /**
     * Resolve the best target session for a notification.
     *
     * Walks the parent chain to find the user-facing conversation session.
     * Use this before calling publish() when you have multiple session candidates.
     *
     * @param string $sessionId The current execution session.
     * @param ?string $parentSessionId Parent session (from task record).
     * @param ?string $workScopeSessionId Work-scope session (from loop/spawn).
     */
    public static function resolveTargetSession(
        string $sessionId,
        ?string $parentSessionId = null,
        ?string $workScopeSessionId = null,
    ): string {
        // Prefer parent session (direct child task → initiating session)
        if ($parentSessionId !== null && $parentSessionId !== '') {
            return $parentSessionId;
        }

        // Fall back to work-scope session (loop stage → loop session)
        if ($workScopeSessionId !== null && $workScopeSessionId !== '') {
            return $workScopeSessionId;
        }

        // Last resort: the execution session itself
        return $sessionId;
    }

    /**
     * Build a standard fingerprint for task-related notifications.
     *
     * Prevents duplicate notifications for the same task event.
     */
    public static function taskFingerprint(string $taskId, string $outcome): string
    {
        return "task:{$taskId}:{$outcome}";
    }

    /**
     * Build a standard fingerprint for loop-related notifications.
     *
     * Prevents duplicate notifications for the same loop milestone.
     */
    public static function loopFingerprint(
        string $loopId,
        int $iterationNumber,
        ?int $stageIndex = null,
        ?string $outcome = null,
    ): string {
        $parts = ["loop:{$loopId}:{$iterationNumber}"];

        if ($stageIndex !== null) {
            $parts[] = "s{$stageIndex}";
        }

        if ($outcome !== null) {
            $parts[] = $outcome;
        }

        return implode(':', $parts);
    }

    /**
     * Check whether a notification with the given fingerprint already exists.
     */
    public function existsByFingerprint(string $sessionId, string $fingerprint): bool
    {
        return $this->store->existsByFingerprint($sessionId, $fingerprint);
    }
}
