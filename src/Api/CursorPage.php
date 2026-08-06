<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

/**
 * Cursor-paginated list envelope for CAP 0.5.0 Core list operations (CORE-18).
 *
 * A list op sorts its items by a declared, deterministic key, then wraps them
 * through {@see self::build}. The cursor is an opaque base64url token carrying
 * the sort key of the last item on the previous page, so pagination is
 * keyset-based (stable under inserts), never offset-based.
 */
final class CursorPage
{
    /**
     * Default page size honored when the client requests none.
     */
    public const int DEFAULT_LIMIT = 50;

    /**
     * Largest page size the instance honors, matching the `max_page_size`
     * advertised in InstanceInfo.
     */
    public const int MAX_LIMIT = 100;

    /**
     * Encode a raw sort key into an opaque base64url cursor token.
     */
    public static function encode(string $key): string
    {
        return rtrim(strtr(base64_encode($key), '+/', '-_'), '=');
    }

    /**
     * Decode an opaque cursor token back into its raw sort key, or null when the
     * token is absent or malformed.
     */
    public static function decode(?string $cursor): ?string
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * Clamp a raw `?limit=` value into `[1, MAX_LIMIT]`, defaulting to
     * DEFAULT_LIMIT when it is absent, non-numeric, or below 1.
     */
    public static function limitFrom(mixed $raw): int
    {
        if (!is_numeric($raw)) {
            return self::DEFAULT_LIMIT;
        }

        $limit = (int) $raw;
        if ($limit < 1) {
            return self::DEFAULT_LIMIT;
        }

        return min($limit, self::MAX_LIMIT);
    }

    /**
     * Build a `{data, next_cursor}` page from `$items` ALREADY sorted in the
     * declared display order.
     *
     * When `$afterKey` is given (a decoded cursor key), items up to and
     * including the one whose key equals it are skipped. A cursor whose key is
     * absent — its item was deleted between pages — yields an empty page rather
     * than re-serving the head. The first `$limit` remaining items become
     * `data`; `next_cursor` is the opaque cursor of the last emitted item when
     * more remain, or null on the final page.
     *
     * @template T
     * @param array<T> $items
     * @param callable(T): string $keyOf
     * @return array{data: list<T>, next_cursor: string|null}
     */
    public static function build(array $items, int $limit, callable $keyOf, ?string $afterKey = null): array
    {
        $items = array_values($items);

        if ($afterKey !== null) {
            $items = self::sliceAfter($items, $afterKey, $keyOf);
        }

        $limit = max(1, $limit);
        $page = array_slice($items, 0, $limit);
        $hasMore = count($items) > $limit;

        $nextCursor = $hasMore && $page !== []
            ? self::encode($keyOf($page[count($page) - 1]))
            : null;

        return ['data' => $page, 'next_cursor' => $nextCursor];
    }

    /**
     * Return the items strictly after the one whose key equals `$afterKey`. An
     * absent key (stale/deleted cursor) returns an empty list.
     *
     * @template T
     * @param list<T> $items
     * @param callable(T): string $keyOf
     * @return list<T>
     */
    private static function sliceAfter(array $items, string $afterKey, callable $keyOf): array
    {
        foreach ($items as $index => $item) {
            if ($keyOf($item) === $afterKey) {
                return array_slice($items, $index + 1);
            }
        }

        return [];
    }
}
