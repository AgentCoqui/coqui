<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Notification;

interface NotificationAutomationHandlerInterface
{
    public function kind(): string;

    /**
     * @param array<string, mixed> $notification
     */
    public function handle(array $notification): NotificationAutomationResult;
}