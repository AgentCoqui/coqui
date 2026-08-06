<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Import;

use CoquiBot\Coqui\Support\IdGenerator;

/**
 * The original→new primary-key map that drives {@see ImportService} FK rewriting.
 *
 * ONE object serves both import modes so there is a single insert code path (no
 * legacy dual paths):
 *
 *  - {@see ImportMode::Preserve} builds an IDENTITY map — every id maps to itself,
 *    so rows insert with their original ids and a collision still fails `conflict`.
 *  - {@see ImportMode::Remap} mints a fresh {@see IdGenerator::hex()} id for every
 *    registered primary key, so no id can collide with the target store.
 *
 * A namespace exists ONLY for the id-keyed collections whose primary keys the
 * remap regenerates (personas, sessions, turns, messages, loops, loop_iterations,
 * loop_stages, child_runs, questions, artifacts, scheduled_tasks). Name-keyed
 * collections (`roles`, `skills`) and the content-addressed `content` self-key are
 * deliberately NOT registered: {@see resolve()} returns such references unchanged,
 * which is exactly how a name key or a filesystem-path-shaped `content_ref` is left
 * intact while an id-shaped `content_ref` that IS a registered key would be rewritten.
 */
final class ImportIdMap
{
    /** @var array<string, array<string, string>> namespace => (oldId => newId) */
    private array $maps = [];

    public function __construct(
        private readonly bool $remap,
    ) {}

    /**
     * Register a primary key in $namespace and return the id the row is inserted
     * under: the same id under preserve, a fresh id under remap. Idempotent — a
     * repeated registration returns the id assigned the first time.
     */
    public function register(string $namespace, string $oldId): string
    {
        if ($oldId === '') {
            return '';
        }

        return $this->maps[$namespace][$oldId]
            ??= ($this->remap ? IdGenerator::hex() : $oldId);
    }

    /**
     * Resolve a foreign-key reference through $namespace. A reference that was
     * never registered (a name key, a content-addressed ref, an out-of-import id)
     * passes through unchanged.
     */
    public function resolve(string $namespace, string $oldId): string
    {
        return $this->maps[$namespace][$oldId] ?? $oldId;
    }

    /**
     * Nullable variant of {@see resolve()}: null in, null out.
     */
    public function resolveNullable(string $namespace, ?string $oldId): ?string
    {
        if ($oldId === null || $oldId === '') {
            return $oldId;
        }

        return $this->resolve($namespace, $oldId);
    }

    /**
     * The full original→new map, keyed by namespace, for FK-rewrite verification.
     *
     * @return array<string, array<string, string>>
     */
    public function all(): array
    {
        return $this->maps;
    }
}
