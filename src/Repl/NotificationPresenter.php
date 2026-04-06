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
            ? '<fg=cyan>☀︎ 1 notification</>'
            : sprintf('<fg=cyan>☀︎ %d notifications</>', $count);
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
     * Uses raw ANSI escape codes instead of Symfony Console tags because
     * readline does not interpret `<fg=...>` formatting — it renders them
     * as literal text. The \001/\002 wrappers tell readline these are
     * non-printing characters so cursor positioning stays correct.
     *
     * Returns empty string if count is zero.
     */
    public function formatBadge(int $unreadCount): string
    {
        if ($unreadCount === 0) {
            return '';
        }

        // \001 and \002 are readline's RL_PROMPT_START_IGNORE / RL_PROMPT_END_IGNORE
        // markers. Without them, readline miscounts the prompt width and cursor
        // positioning breaks on line-wrap and history recall.
        return sprintf(" \001\033[36m\002[%d☀︎]\001\033[0m\002", $unreadCount);
    }

    /**
     * Format a sticky actionable automation summary line.
     */
    public function formatActionableSummary(int $pendingCount, int $activeCount): string
    {
        if ($pendingCount <= 0 && $activeCount <= 0) {
            return '';
        }

        $parts = [];
        if ($pendingCount > 0) {
            $parts[] = sprintf('%d pending', $pendingCount);
        }
        if ($activeCount > 0) {
            $parts[] = sprintf('%d active', $activeCount);
        }

        return sprintf('<fg=yellow>⚙ Automation:</> <fg=gray>%s</>', implode(', ', $parts));
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
     *
     * Colors are assigned by outcome status (completed=green, failed=red,
     * cancelled=yellow, stage=blue, loop=cyan) rather than by source
     * category so users can scan by result at a glance.
     */
    private function colorizeKind(string $kind): string
    {
        [$icon, $color] = match ($kind) {
            'task.completed'          => ['✔', 'green'],
            'task.failed'             => ['✘', 'red'],
            'task.cancelled'          => ['⏹', 'yellow'],
            'task.stale_killed'       => ['✘', 'red'],
            'task.timed_out'          => ['✘', 'red'],
            'tool.completed'          => ['✔', 'green'],
            'tool.failed'             => ['✘', 'red'],
            'loop.stage_completed'    => ['⛮', 'blue'],
            'loop.iteration_approved' => ['✔', 'green'],
            'loop.completed'          => ['✔', 'cyan'],
            'loop.failed'             => ['✘', 'red'],
            'loop.cancelled'          => ['⏹', 'yellow'],
            default                   => ['☀︎', 'gray'],
        };

        return "<fg={$color}>{$icon}</>";
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
