<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Support;

/**
 * Formats nullable ISO-8601 timestamps for REPL tables.
 * Relocated from the (removed) backstory REPL handler; used by prompt-source tables.
 */
final class TimestampFormatter
{
    public static function formatNullable(?string $timestamp): string
    {
        if ($timestamp === null || $timestamp === '') {
            return '—';
        }

        $dt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $timestamp);
        if ($dt === false) {
            return $timestamp;
        }

        return $dt->format('Y-m-d H:i');
    }
}
