<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Import;

/**
 * How {@see ImportService} reconciles the ids carried by an export envelope with
 * the target store (CORE-56).
 *
 *  - {@see self::Preserve}: rows are inserted with their ORIGINAL ids verbatim; a
 *    primary-key collision fails the whole import (`conflict`).
 *  - {@see self::Remap}: every id is minted fresh and every foreign-key reference
 *    is rewritten to the new id atomically. (Wired in Task 11 — CORE-56 part 2.)
 */
enum ImportMode: string
{
    case Preserve = 'preserve';
    case Remap = 'remap';
}
