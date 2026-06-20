<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Support;


use CoquiBot\Coqui\Contract\CoquiDefaults;
/**
 * Shared JSON decode helpers.
 *
 * Extracted from 11 identical private decodeJsonObject() methods scattered
 * across toolkits, handlers, and agents.
 */
final class JsonHelper
{
    /**
     * Decode a JSON string into a list, returning null on failure.
     *
     * Handles: list passthrough, null/empty rejection, decode errors,
     * and rejects object-shaped arrays.
     *
     * @return list<mixed>|null
     */
    public static function decodeJsonList(mixed $value): ?array
    {
        if (is_array($value)) {
            return array_is_list($value) ? $value : null;
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $decoded = json_decode($value, true, CoquiDefaults::JSON_DECODE_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_array($decoded) && array_is_list($decoded) ? $decoded : null;
    }

    /**
     * Decode a JSON string into an associative array map, returning null on failure.
     *
     * Handles: map passthrough, null/empty rejection, decode errors,
     * and rejects list-shaped arrays.
     *
     * @return array<string, mixed>|null
     */
    public static function decodeJsonMap(mixed $value): ?array
    {
        if (is_array($value)) {
            return array_is_list($value) ? null : $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $decoded = json_decode($value, true, CoquiDefaults::JSON_DECODE_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_array($decoded) && !array_is_list($decoded) ? $decoded : null;
    }

    /**
     * Decode a JSON string into an associative array, returning null on failure.
     *
     * Handles: array passthrough, null/empty rejection, decode errors.
     *
     * @return array<string, mixed>|null
     */
    public static function decodeJsonObject(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $decoded = json_decode($value, true, CoquiDefaults::JSON_DECODE_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
