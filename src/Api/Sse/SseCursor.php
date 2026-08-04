<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Sse;

/**
 * Opaque, lexicographically-orderable SSE stream cursor.
 *
 * Every SSE stream (turn, task, loop) carries a monotonic `id:` line that
 * clients echo back to replay events strictly after it. The CAP contract
 * (schema/sse-frame.json) PINS that id to a string whose lexicographic order
 * matches its numeric order — a bare decimal counter ("9" sorts after "10")
 * is non-conformant.
 *
 * coqui's underlying transport cursor is an integer autoincrement rowid, so
 * the encoding is a zero-padded fixed-width decimal: string comparison of two
 * encoded ids then agrees with the numeric comparison of their rowids. A single
 * shared encoder keeps the emit path (this task) and the replay/Last-Event-ID
 * path (Task 13) symmetric — decode reverses encode exactly.
 */
final class SseCursor
{
    /**
     * Fixed width in digits. 20 covers the full unsigned 64-bit range, so every
     * PHP_INT_MAX rowid encodes to the same length and sorts correctly.
     */
    private const int WIDTH = 20;

    /**
     * Encode a numeric rowid into a zero-padded, fixed-width string cursor.
     */
    public static function encode(int $rowid): string
    {
        return sprintf('%0' . self::WIDTH . 'd', max(0, $rowid));
    }

    /**
     * Decode a string cursor back to its numeric rowid. Leading zeros are
     * discarded by the integer cast, so decode(encode($n)) === $n.
     */
    public static function decode(string $cursor): int
    {
        return (int) $cursor;
    }
}
