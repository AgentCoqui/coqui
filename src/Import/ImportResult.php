<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Import;

/**
 * Outcome of an {@see ImportService::import()} call.
 *
 * Records how many rows each collection contributed and which collections were
 * present in the envelope but not persisted (file-authored objects with no DB
 * table, and the internal diagnostics collections). Task 11 (remap) extends this
 * with the original→new id map it must expose for FK rewrite verification.
 */
final readonly class ImportResult
{
    /**
     * @param array<string, int> $insertedByCollection Rows persisted, keyed by collection name.
     * @param list<string>        $skippedCollections   Present-but-not-persisted collections.
     */
    public function __construct(
        public array $insertedByCollection,
        public array $skippedCollections,
    ) {}

    public function inserted(string $collection): int
    {
        return $this->insertedByCollection[$collection] ?? 0;
    }

    public function totalInserted(): int
    {
        return array_sum($this->insertedByCollection);
    }
}
