<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl;

/**
 * Converts ISO timestamps to human-readable relative time strings.
 */
final class TimeFormatter
{
    public static function timeSince(string $datetime): string
    {
        try {
            $then = new \DateTimeImmutable($datetime);
        } catch (\Throwable) {
            return $datetime;
        }

        $seconds = max(0, (new \DateTimeImmutable('now'))->getTimestamp() - $then->getTimestamp());

        return match (true) {
            $seconds < 60      => 'just now',
            $seconds < 3600    => ($n = intdiv($seconds, 60)) === 1    ? '1 minute ago'  : "{$n} minutes ago",
            $seconds < 86400   => ($n = intdiv($seconds, 3600)) === 1  ? '1 hour ago'    : "{$n} hours ago",
            $seconds < 604800  => ($n = intdiv($seconds, 86400)) === 1 ? '1 day ago'     : "{$n} days ago",
            $seconds < 2592000 => ($n = intdiv($seconds, 604800)) === 1  ? '1 week ago'  : "{$n} weeks ago",
            $seconds < 31536000 => ($n = intdiv($seconds, 2592000)) === 1 ? '1 month ago' : "{$n} months ago",
            default             => ($n = intdiv($seconds, 31536000)) === 1 ? '1 year ago'  : "{$n} years ago",
        };
    }
}
