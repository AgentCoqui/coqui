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
    private const int MAX_METADATA_LENGTH = 160;

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
        $lines[] = sprintf(' <fg=cyan>Notifications</> [%d☀︎]:', $count);

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
        // Count is now shown in the Notifications header instead of the
        // readline prompt — readline cannot render ANSI colors reliably.
        return '';
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

            $kindValue = (string) $kind;
            $kindLabel = $this->kindLabelForPromptInjection($kindValue);
            $header = "{$num}. [{$kindLabel}] {$title}";
            if ($priority === 'urgent' || $priority === 'high') {
                $header .= " (priority: {$priority})";
            }
            if ($kindValue !== '') {
                $header .= " [kind: {$kindValue}]";
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

            $metadataLine = $this->formatMetadataForPromptInjection($notification['metadata'] ?? null);
            if ($metadataLine !== null) {
                $lines[] = "   Metadata: {$metadataLine}";
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

        [$icon, $color] = $this->resolveKindStyle($kind);
        $priorityTag = $this->colorizePriority($priority);
        $timeTag = $createdAt !== '' ? sprintf(' <fg=gray>%s</>', $this->relativeTime($createdAt)) : '';
        $coloredTitle = $this->colorizeTitle($truncatedTitle, $color);

        return "  {$priorityTag}<fg={$color}>{$icon}</> {$coloredTitle}{$timeTag}";
    }

    /**
     * Resolve icon and color for a notification kind.
     *
     * Colors are assigned by outcome status (completed=green, failed=red,
     * cancelled=yellow, stage=blue, loop=cyan) rather than by source
     * category so users can scan by result at a glance.
     *
     * @return array{string, string} [icon, color]
     */
    private function resolveKindStyle(string $kind): array
    {
        return match ($kind) {
            'task.completed'          => ['✔', 'green'],
            'task.failed'             => ['✘', 'red'],
            'task.cancelled'          => ['⏹', 'yellow'],
            'task.stale_killed'       => ['✘', 'red'],
            'task.timed_out'          => ['✘', 'red'],
            'tool.completed'          => ['✔', 'green'],
            'tool.failed'             => ['✘', 'red'],
            'loop.iteration',
            'loop.stage_completed'    => ['⛮', 'blue'],
            'loop.iteration_approved' => ['✔', 'green'],
            'loop.completed'          => ['✔', 'cyan'],
            'loop.failed'             => ['✘', 'red'],
            'loop.cancelled'          => ['⏹', 'yellow'],
            default                   => ['☀︎', 'gray'],
        };
    }

    /**
     * Color a title string with gray for bracketed/parenthesized metadata.
     */
    private function colorizeTitle(string $title, string $color): string
    {
        $parts = preg_split('/(\[[^\]]*\]|\([^)]*\))/', $title, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            return "<fg={$color}>{$title}</>";
        }

        $result = '';
        foreach ($parts as $i => $part) {
            if ($part === '') {
                continue;
            }
            $result .= $i % 2 === 1
                ? "<fg=gray>{$part}</>"
                : "<fg={$color}>{$part}</>";
        }

        return $result;
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

    private function kindLabelForPromptInjection(string $kind): string
    {
        if ($kind === '') {
            return 'unknown';
        }

        return str_replace('_', ' ', str_replace(['task.', 'tool.', 'loop.'], '', $kind));
    }

    private function formatMetadataForPromptInjection(mixed $metadata): ?string
    {
        if ($metadata === null || $metadata === '') {
            return null;
        }

        $normalized = $metadata;

        if (is_string($metadata)) {
            try {
                $decoded = json_decode($metadata, true, 8, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $normalized = $decoded;
                }
            } catch (\JsonException) {
                $normalized = $metadata;
            }
        }

        if (is_array($normalized)) {
            try {
                $normalized = json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            } catch (\JsonException) {
                return null;
            }
        }

        if (!is_string($normalized) || $normalized === '') {
            return null;
        }

        return mb_strlen($normalized) > self::MAX_METADATA_LENGTH
            ? mb_substr($normalized, 0, self::MAX_METADATA_LENGTH) . '...'
            : $normalized;
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
