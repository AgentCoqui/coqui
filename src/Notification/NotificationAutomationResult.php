<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Notification;

final readonly class NotificationAutomationResult
{
    private function __construct(
        public NotificationAutomationOutcome $outcome,
        public ?string $message = null,
        public ?int $retryDelaySeconds = null,
    ) {}

    public static function completed(?string $message = null): self
    {
        return new self(NotificationAutomationOutcome::Completed, $message);
    }

    public static function retry(string $message, ?int $retryDelaySeconds = null): self
    {
        return new self(NotificationAutomationOutcome::Retry, $message, $retryDelaySeconds);
    }

    public static function failed(string $message): self
    {
        return new self(NotificationAutomationOutcome::Failed, $message);
    }

    public static function skipped(?string $message = null): self
    {
        return new self(NotificationAutomationOutcome::Skipped, $message);
    }
}