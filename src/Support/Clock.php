<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Support;

/**
 * Time helpers for Coqui's storage and runtime layers.
 *
 * Centralizes the ISO-8601 UTC timestamp format used for every persisted
 * `created_at` / `updated_at` value, so the format string lives in one
 * place instead of being repeated across stores, handlers, and managers.
 */
final class Clock
{
    /** ISO-8601 timestamp in UTC with a trailing Z (e.g. 2026-06-20T15:17:22Z). */
    public static function nowUtc(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
