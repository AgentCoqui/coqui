<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Utility;

use CoquiBot\Coqui\Storage\ScheduleStore;

/**
 * Shared validation logic for schedule parameters.
 *
 * Used by both ScheduleHandler (API) and ScheduleToolkit (agent tools)
 * to ensure consistent validation rules.
 */
final class ScheduleValidator
{
    /** Maximum prompt length in characters. */
    private const int MAX_PROMPT_LENGTH = 50000;

    /** Name must start with alphanumeric, 1-64 chars total, allows hyphens and underscores. */
    private const string NAME_PATTERN = '/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}$/';

    /**
     * Validate a schedule name format.
     *
     * @return string|null Error message, or null if valid
     */
    public static function validateName(string $name): ?string
    {
        if ($name === '') {
            return 'name is required';
        }

        if (!preg_match(self::NAME_PATTERN, $name)) {
            return 'Name must be 1-64 alphanumeric characters, hyphens, or underscores (must start with alphanumeric)';
        }

        return null;
    }

    /**
     * Validate a cron expression or @once.
     *
     * @return string|null Error message, or null if valid
     */
    public static function validateExpression(string $expression): ?string
    {
        if ($expression === '') {
            return 'schedule_expression is required';
        }

        if (!ScheduleStore::isValidExpression($expression)) {
            return "Invalid schedule expression: {$expression}. Use cron format (e.g., \"0 9 * * *\") or \"@once\" for one-shot.";
        }

        return null;
    }

    /**
     * Validate a timezone string.
     *
     * @return string|null Error message, or null if valid
     */
    public static function validateTimezone(string $timezone): ?string
    {
        try {
            new \DateTimeZone($timezone);
            return null;
        } catch (\Throwable) {
            return "Invalid timezone: {$timezone}";
        }
    }

    /**
     * Validate prompt length.
     *
     * @return string|null Error message, or null if valid
     */
    public static function validatePromptLength(string $prompt): ?string
    {
        if (mb_strlen($prompt) > self::MAX_PROMPT_LENGTH) {
            return sprintf('Prompt must be %d characters or less', self::MAX_PROMPT_LENGTH);
        }

        return null;
    }

    /**
     * Clamp max iterations to the valid range.
     */
    public static function normalizeMaxIterations(int $value): int
    {
        return max(1, min($value, 100));
    }

    /**
     * Clamp max failures to the valid range.
     */
    public static function normalizeMaxFailures(int $value): int
    {
        return max(1, min($value, 100));
    }
}
