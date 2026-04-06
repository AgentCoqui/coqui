<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl;

/**
 * Formats notifications for REPL terminal display.
 *
 * Handles badge text, truncation, priority colorization, and stacked
 * notification rendering. Designed to be testable independently from
 * the REPL loop and readline mechanics.
 */
final class NotificationPresenter
{
    private const int MAX_TITLE_LENGTH = 80;
    private const int MAX_MESSAGE_LENGTH = 120;

    /**
     * Format notifications for idle REPL display.
     *
     * Returns an array of pre-formatted Symfony Console output lines
     * ready to be written via SymfonyStyle::writeln().
     *
     * @param list<array<string, mixed>> $notifications
     * @return list<string>
     */
    public function formatIdleNotifications(array $notifications): array
    {
        if ($notifications === []) {
            return [];
        }

        $lines = [];
        $lines[] = '';

        $count = count($notifications);
        $badge = $count === 1
            ? '<fg=cyan>🔔 1 notification</>'
            : sprintf('<fg=cyan>🔔 %d notifications</>', $count);
        $lines[] = $badge;

        foreach ($notifications as $notification) {
            $lines[] = $this->formatSingleNotification($notification);
        }

        $lines[] = '';

        return $lines;
    }

    /**
     * Format a compact badge showing unread count for the prompt line.
     *
     * Returns empty string if count is zero.
     */
    public function formatBadge(int $unreadCount): string
    {
        if ($unreadCount === 0) {
            return '';
        }

        return sprintf(' <fg=cyan>[%d🔔]</>', $unreadCount);
    }

    /**
     * Format notifications for agent turn acknowledgment display.
     *
     * Shown after the agent processes a turn that included notification context.
     *
     * @param list<array<string, mixed>> $notifications
     * @return list<string>
     */
    public function formatTurnAcknowledgment(array $notifications): array
    {
        if ($notifications === []) {
            return [];
        }

        $count = count($notifications);
        $label = $count === 1 ? 'notification' : 'notifications';

        return [
            sprintf('<fg=gray>  • Acknowledged %d %s</>', $count, $label),
        ];
    }

    /**
     * Format notifications for system prompt injection.
     *
     * Returns a plain-text block suitable for inclusion in a synthetic
     * SystemMessage (no Symfony Console formatting tags).
     *
     * @param list<array<string, mixed>> $notifications
     */
    public function formatForPromptInjection(array $notifications): string
    {
        if ($notifications === []) {
            return '';
        }

        $lines = ['[PENDING NOTIFICATIONS]', ''];

        foreach ($notifications as $i => $notification) {
            $num = $i + 1;
            $kind = $notification['kind'] ?? 'unknown';
            $title = $notification['title'] ?? 'Untitled';
            $message = $notification['message'] ?? '';
            $priority = $notification['priority'] ?? 'normal';
            $createdAt = $notification['created_at'] ?? '';

            $header = "{$num}. [{$kind}] {$title}";
            if ($priority === 'urgent' || $priority === 'high') {
                $header .= " (priority: {$priority})";
            }

            $lines[] = $header;

            if ($message !== '') {
                $truncated = mb_strlen($message) > self::MAX_MESSAGE_LENGTH
                    ? mb_substr($message, 0, self::MAX_MESSAGE_LENGTH) . '...'
                    : $message;
                $lines[] = "   {$truncated}";
            }

            if ($createdAt !== '') {
                $lines[] = "   Time: {$createdAt}";
            }

            $lines[] = '';
        }

        $lines[] = 'These notifications are from completed background work. Review them and incorporate any relevant context into your response.';

        return implode("\n", $lines);
    }

    /**
     * Format a single notification line for terminal display.
     *
     * @param array<string, mixed> $notification
     */
    private function formatSingleNotification(array $notification): string
    {
        $kind = $notification['kind'] ?? 'unknown';
        $title = $notification['title'] ?? 'Untitled';
        $priority = $notification['priority'] ?? 'normal';
        $createdAt = $notification['created_at'] ?? '';

        $truncatedTitle = mb_strlen($title) > self::MAX_TITLE_LENGTH
            ? mb_substr($title, 0, self::MAX_TITLE_LENGTH) . '...'
            : $title;

        $kindTag = $this->colorizeKind($kind);
        $priorityTag = $this->colorizePriority($priority);
        $timeTag = $createdAt !== '' ? sprintf(' <fg=gray>%s</>', $this->relativeTime($createdAt)) : '';

        return "  {$priorityTag}{$kindTag} {$truncatedTitle}{$timeTag}";
    }

    /**
     * Colorize a notification kind for terminal display.
     */
    private function colorizeKind(string $kind): string
    {
        $color = match (true) {
            str_starts_with($kind, 'task.') => 'magenta',
            str_starts_with($kind, 'tool.') => 'yellow',
            str_starts_with($kind, 'loop.') => 'blue',
            default => 'gray',
        };

        $shortKind = match ($kind) {
            'task.completed' => '✅ task',
            'task.failed' => '❌ task',
            'task.cancelled' => '⏹ task',
            'task.stale_killed' => '💀 task',
            'task.timed_out' => '⏰ task',
            'tool.completed' => '✅ tool',
            'tool.failed' => '❌ tool',
            'loop.stage_completed' => '📋 stage',
            'loop.iteration_approved' => '✅ iteration',
            'loop.completed' => '🏁 loop',
            'loop.failed' => '❌ loop',
            'loop.cancelled' => '⏹ loop',
            default => "📌 {$kind}",
        };

        return "<fg={$color}>{$shortKind}</>";
    }

    /**
     * Colorize priority indicator.
     */
    private function colorizePriority(string $priority): string
    {
        return match ($priority) {
            'urgent' => '<fg=red>▲ </>',
            'high' => '<fg=yellow>▲ </>',
            default => '  ',
        };
    }

    /**
     * Convert an ISO timestamp to a relative time string.
     */
    private function relativeTime(string $isoTimestamp): string
    {
        try {
            $time = new \DateTimeImmutable($isoTimestamp);
            $now = new \DateTimeImmutable();
            $diff = $now->getTimestamp() - $time->getTimestamp();

            if ($diff < 0) {
                return 'just now';
            }

            if ($diff < 60) {
                return "{$diff}s ago";
            }

            if ($diff < 3600) {
                $minutes = (int) floor($diff / 60);
                return "{$minutes}m ago";
            }

            if ($diff < 86400) {
                $hours = (int) floor($diff / 3600);
                return "{$hours}h ago";
            }

            $days = (int) floor($diff / 86400);
            return "{$days}d ago";
        } catch (\Throwable) {
            return '';
        }
    }
}
