<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Notification;

enum NotificationAutomationOutcome: string
{
    case Completed = 'completed';
    case Retry = 'retry';
    case Failed = 'failed';
    case Skipped = 'skipped';
}