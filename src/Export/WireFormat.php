<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Export;

/**
 * Shared wire-projection primitives for the export producers.
 *
 * Centralizes the two invariants every CAP 0.5.0 producer must honor:
 *  - timestamps normalize to RFC-3339 UTC with a trailing `Z` (never a numeric
 *    offset), so a `date('c')`-persisted value (`+00:00`) is rewritten to `Z`;
 *  - object-typed fields emit as a JSON object (`stdClass`) or `null`, never as
 *    a bare `[]`, which JSON would encode as an array and fail `type: object`.
 */
final class WireFormat
{
    /**
     * Normalize a stored timestamp to RFC-3339 UTC (Z), or null when absent.
     */
    public static function timestamp(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable) {
            return $value;
        }
    }

    /**
     * Coerce a stored value into a JSON object (stdClass) or null for a field
     * typed `object|null`. A JSON string decoding to an object is emitted
     * verbatim; an empty array becomes an empty object (never `[]`).
     */
    public static function object(mixed $value): ?\stdClass
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \stdClass) {
            return $value;
        }

        if (is_array($value)) {
            return (object) $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, false);
            if ($decoded instanceof \stdClass) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Decode a stored JSON array value into a PHP list, or null when absent.
     * Used for wire fields typed `array|null` (e.g. tool_calls, attachments).
     *
     * @return array<int|string, mixed>|null
     */
    public static function array(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
